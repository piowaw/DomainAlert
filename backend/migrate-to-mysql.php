<?php
/**
 * SQLite → MySQL Data Migration Script
 * 
 * Visit this page in a browser ONCE to copy all data from the SQLite database
 * into the new MySQL database. Tables must already exist in MySQL (initDatabase handles this).
 * 
 * After migration, you can delete database.sqlite and this file.
 * 
 * Usage: Visit https://yourdomain.com/migrate-to-mysql.php
 */

// Don't send JSON/CORS headers for this standalone script
define('SKIP_HEADERS', true);

require_once __DIR__ . '/config.php';

// Prevent accidental re-runs in production — delete this file after migration
$isCli = php_sapi_name() === 'cli';
$nl = $isCli ? "\n" : "<br>";

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre style='font-family: monospace; font-size: 14px; padding: 20px;'>";
}

echo "=== SQLite → MySQL Data Migration ===$nl$nl";

// ── 1. Connect to MySQL (via config.php) ──
try {
    $mysql = initDatabase(); // This also creates tables if they don't exist
    echo "✓ Connected to MySQL$nl";
} catch (Exception $e) {
    echo "✗ MySQL connection failed: " . $e->getMessage() . $nl;
    exit(1);
}

// ── 2. Connect to SQLite ──
$sqlitePath = __DIR__ . '/database.sqlite';
if (!file_exists($sqlitePath)) {
    // Also check parent dir (deployed layout might flatten)
    $altPath = dirname(__DIR__) . '/database.sqlite';
    if (file_exists($altPath)) {
        $sqlitePath = $altPath;
    } else {
        echo "✗ SQLite database not found at:$nl  $sqlitePath$nl  $altPath$nl";
        echo "{$nl}If you don't have existing data to migrate, you're done — MySQL is ready!$nl";
        exit(0);
    }
}

if (!extension_loaded('pdo_sqlite')) {
    echo "✗ pdo_sqlite extension is not installed on this server.$nl";
    echo "  You need to enable it in Plesk: PHP Settings → Extensions → pdo_sqlite$nl";
    echo "  Or ask your hosting provider to enable it.$nl$nl";
    echo "  Alternatively, you can export your SQLite data on a local machine$nl";
    echo "  and import the SQL dump into MySQL via phpMyAdmin.$nl";
    exit(1);
}

try {
    $sqlite = new PDO('sqlite:' . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Connected to SQLite: $sqlitePath$nl$nl";
} catch (Exception $e) {
    echo "✗ SQLite connection failed: " . $e->getMessage() . $nl;
    exit(1);
}

// ── 3. Migrate each table ──
$tables = [
    'users' => [
        'columns' => 'id, email, password, is_admin, created_at',
        'insert'  => 'INSERT IGNORE INTO users (id, email, password, is_admin, created_at) VALUES (?, ?, ?, ?, ?)',
    ],
    'domains' => [
        'columns' => 'id, domain, expiry_date, is_registered, last_checked, added_by',
        'insert'  => 'INSERT IGNORE INTO domains (id, domain, expiry_date, is_registered, last_checked, added_by) VALUES (?, ?, ?, ?, ?, ?)',
    ],
    'notifications' => [
        'columns' => 'id, domain_id, type, message, is_read, created_at',
        'insert'  => 'INSERT IGNORE INTO notifications (id, domain_id, type, message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?)',
    ],
    'invitations' => [
        'columns' => 'id, email, token, invited_by, used, created_at',
        'insert'  => 'INSERT IGNORE INTO invitations (id, email, token, invited_by, used, created_at) VALUES (?, ?, ?, ?, ?, ?)',
    ],
    'jobs' => [
        'columns' => 'id, user_id, type, status, total, processed, errors, data, result, created_at, updated_at',
        'insert'  => 'INSERT IGNORE INTO jobs (id, user_id, type, status, total, processed, errors, data, result, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    ],
];

$totalMigrated = 0;

foreach ($tables as $table => $config) {
    echo "── Migrating '$table' ──$nl";
    
    // Check if table exists in SQLite
    $check = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
    if (!$check->fetch()) {
        echo "  ⏭ Table does not exist in SQLite, skipping$nl$nl";
        continue;
    }
    
    // Count rows
    $countResult = $sqlite->query("SELECT COUNT(*) FROM $table");
    $totalRows = (int)$countResult->fetchColumn();
    echo "  Found $totalRows rows in SQLite$nl";
    
    if ($totalRows === 0) {
        echo "  ⏭ No data to migrate$nl$nl";
        continue;
    }
    
    // Check existing MySQL rows
    $mysqlCount = (int)$mysql->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    if ($mysqlCount > 0) {
        echo "  ⚠ MySQL already has $mysqlCount rows — using INSERT IGNORE to skip duplicates$nl";
    }
    
    // Fetch all from SQLite and insert into MySQL in batches
    $stmt = $sqlite->query("SELECT {$config['columns']} FROM $table ORDER BY id");
    $insertStmt = $mysql->prepare($config['insert']);
    
    $migrated = 0;
    $skipped = 0;
    $batchSize = 500;
    
    $mysql->beginTransaction();
    
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        try {
            $insertStmt->execute($row);
            if ($insertStmt->rowCount() > 0) {
                $migrated++;
            } else {
                $skipped++;
            }
        } catch (Exception $e) {
            $skipped++;
            // Log but continue
            if ($skipped <= 5) {
                echo "  ⚠ Row skipped: " . $e->getMessage() . $nl;
            }
        }
        
        if (($migrated + $skipped) % $batchSize === 0) {
            $mysql->commit();
            $mysql->beginTransaction();
            echo "  ... {$migrated} migrated, {$skipped} skipped / {$totalRows}$nl";
            flush();
        }
    }
    
    $mysql->commit();
    
    // Fix auto-increment to be higher than max ID
    try {
        $maxId = (int)$mysql->query("SELECT MAX(id) FROM $table")->fetchColumn();
        if ($maxId > 0) {
            $nextId = $maxId + 1;
            $mysql->exec("ALTER TABLE $table AUTO_INCREMENT = $nextId");
        }
    } catch (Exception $e) {
        // Non-critical
    }
    
    echo "  ✓ Migrated: $migrated | Skipped: $skipped | Total: $totalRows$nl$nl";
    $totalMigrated += $migrated;
}

echo "=== Migration Complete ===$nl";
echo "Total rows migrated: $totalMigrated$nl$nl";
echo "Next steps:$nl";
echo "  1. Verify your app works with MySQL$nl";
echo "  2. Delete database.sqlite$nl";
echo "  3. Delete this file (migrate-to-mysql.php)$nl";

if (!$isCli) {
    echo "</pre>";
}
