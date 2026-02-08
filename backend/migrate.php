<?php
/**
 * Database Migration Script (MySQL)
 * Run this script to ensure all tables exist.
 * initDatabase() in config.php handles all CREATE TABLE IF NOT EXISTS.
 * Usage: php migrate.php  OR  visit /migrate.php in browser
 */

// Don't send JSON/CORS headers for this standalone script
define('SKIP_HEADERS', true);

require_once __DIR__ . '/config.php';

$isCli = php_sapi_name() === 'cli';
$nl = $isCli ? "\n" : "<br>";

echo "=== DomainAlert Database Migration ===$nl$nl";

try {
    $db = initDatabase();
    
    // Verify tables exist
    $tables = ['users', 'domains', 'notifications', 'invitations', 'jobs'];
    foreach ($tables as $table) {
        $result = $db->query("SHOW TABLES LIKE '$table'");
        if ($result->fetch()) {
            echo "✓ Table '$table' exists$nl";
        } else {
            echo "✗ Table '$table' MISSING — this should not happen, check config.php$nl";
        }
    }
    
    echo "{$nl}=== Migration completed successfully ===$nl";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . $nl;
    exit(1);
}
