<?php
/**
 * Domain expiry checker — MAXIMUM SPEED
 * 
 * Uses RdapEngine for massive parallel lookups with multi-process support.
 * 
 * Usage:
 *   php check_domains.php                               # single run, 200 concurrent
 *   php check_domains.php daemon                        # continuous loop
 *   php check_domains.php daemon --concurrency=300      # 300 concurrent per process
 *   php check_domains.php daemon --workers=4            # 4 processes × 200 = 800 concurrent
 *   php check_domains.php daemon --workers=8 --concurrency=300  # 2400 concurrent
 * 
 * Expected: 100-500 domains/sec
 */

define('SKIP_HEADERS', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/WhoisService.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/RdapEngine.php';

// ── Parse CLI args ──
$concurrency = 200;
$numWorkers = 1;
$daemonMode = false;
$staleBatch = 5000;

foreach ($argv ?? [] as $arg) {
    if ($arg === 'daemon') $daemonMode = true;
    if (str_starts_with($arg, '--concurrency=')) $concurrency = max(10, min(1000, (int)substr($arg, 14)));
    if (str_starts_with($arg, '--workers=')) $numWorkers = max(1, min(32, (int)substr($arg, 10)));
    if (str_starts_with($arg, '--batch=')) $staleBatch = max(100, (int)substr($arg, 8));
}

function log_msg(string $m): void {
    $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
    echo "[" . date('Y-m-d H:i:s') . "] [{$mem}MB] $m\n";
}

function processBatch(PDO $db, WhoisService $whois, RdapEngine $rdap, NotificationService $notifications, array $domainRows, int $numWorkers): int {
    if (empty($domainRows)) return 0;
    
    $names = array_column($domainRows, 'domain');
    $rowByName = [];
    foreach ($domainRows as $row) {
        $rowByName[$row['domain']] = $row;
    }
    
    // RDAP lookup (multi-process if configured)
    $t = microtime(true);
    if ($numWorkers > 1) {
        $rdapResults = $rdap->lookupMultiProcess($names, $numWorkers);
    } else {
        $rdapResults = $rdap->lookupBatch($names);
    }
    $elapsed = round(microtime(true) - $t, 2);
    
    $rdapHits = count(array_filter($rdapResults, fn($r) => $r !== null));
    $fallbacks = 0;
    
    // Fallback for RDAP misses
    foreach ($names as $name) {
        if (($rdapResults[$name] ?? null) === null) {
            try {
                $rdapResults[$name] = $whois->lookup($name);
                $fallbacks++;
            } catch (Exception $e) {
                // skip
            }
        }
    }
    
    // Batch update via transaction
    $db->beginTransaction();
    $updateStmt = $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = NOW() WHERE id = ?");
    $checked = 0;
    
    foreach ($domainRows as $row) {
        $name = $row['domain'];
        $data = $rdapResults[$name] ?? null;
        if ($data === null) continue;
        
        $wasRegistered = (bool)$row['is_registered'];
        $isNowAvailable = !$data['is_registered'];
        
        $updateStmt->execute([
            $data['expiry_date'] ?? $row['expiry_date'],
            $data['is_registered'] ? 1 : 0,
            $row['id'],
        ]);
        
        if ($wasRegistered && $isNowAvailable) {
            log_msg(">>> AVAILABLE: $name");
            $notifications->notifyDomainAvailable($row['id'], $name);
        }
        $checked++;
    }
    $db->commit();
    
    $dps = count($names) > 0 && $elapsed > 0 ? round(count($names) / $elapsed) : 0;
    log_msg("  {$checked} checked | {$rdapHits} RDAP/{$fallbacks} fallback | {$dps} domains/sec | {$elapsed}s");
    
    return $checked;
}

// ── Main ──
log_msg("=== Domain Checker ===");
log_msg("Concurrency: $concurrency | Workers: $numWorkers | Stale batch: $staleBatch");

$db = initDatabase();

$whois = new WhoisService();
$rdap = new RdapEngine($concurrency);
$notifications = new NotificationService($db);

do {
    $cycleStart = microtime(true);
    $totalChecked = 0;
    
    // 1) Expiring / expired domains
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT * FROM domains WHERE is_registered = 1 AND expiry_date <= ? ORDER BY expiry_date ASC");
    $stmt->execute([$today]);
    $expiring = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($expiring) > 0) {
        log_msg("Checking " . count($expiring) . " expiring/expired domains...");
        $totalChecked += processBatch($db, $whois, $rdap, $notifications, $expiring, $numWorkers);
    }
    
    // 2) Stale domains (not checked in 24h) — large batches
    $stmt = $db->prepare("SELECT * FROM domains WHERE last_checked < DATE_SUB(NOW(), INTERVAL 24 HOUR) OR last_checked IS NULL LIMIT ?");
    $stmt->execute([$staleBatch]);
    $stale = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($stale) > 0) {
        log_msg("Refreshing " . count($stale) . " stale domains...");
        $totalChecked += processBatch($db, $whois, $rdap, $notifications, $stale, $numWorkers);
    }
    
    $cycleTime = round(microtime(true) - $cycleStart, 1);
    log_msg("Cycle done: {$totalChecked} domains in {$cycleTime}s (" . count($expiring) . " expiring, " . count($stale) . " stale)");
    
    if ($daemonMode && count($stale) === 0 && count($expiring) === 0) {
        log_msg("Nothing to do, sleeping 30s...");
        sleep(30);
    }
    
} while ($daemonMode);

log_msg("Done.");
