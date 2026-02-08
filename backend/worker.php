<?php
/**
 * DomainAlert Background Job Worker — MAXIMUM SPEED
 * 
 * Architecture:
 *   - Rolling-window curl_multi: 200 concurrent RDAP requests per process
 *   - Multi-process via pcntl_fork: 8 child processes (configurable)
 *   - 8 workers × 200 concurrent = 1600 simultaneous RDAP requests
 *   - Batched MySQL writes via transactions (100x faster than individual INSERTs)
 *   - IANA RDAP bootstrap for auto-discovering unknown TLD servers
 *   - WHOIS fallback only for domains where RDAP truly fails
 * 
 * Usage:
 *   php worker.php daemon                          # 200 concurrent, single process
 *   php worker.php daemon --concurrency=300        # 300 concurrent requests
 *   php worker.php daemon --workers=8              # 8 forked processes × 200 each
 *   php worker.php daemon --workers=8 --concurrency=300  # 8 × 300 = 2400 concurrent
 * 
 * Expected throughput: 100-500+ domains/sec
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/services/WhoisService.php';
require_once __DIR__ . '/services/RdapEngine.php';

// ── Parse CLI args ──
$concurrency = 200;
$numWorkers = 1;
$daemonMode = false;

foreach ($argv ?? [] as $arg) {
    if ($arg === 'daemon') $daemonMode = true;
    if (str_starts_with($arg, '--concurrency=')) $concurrency = max(10, min(1000, (int)substr($arg, 14)));
    if (str_starts_with($arg, '--workers=')) $numWorkers = max(1, min(32, (int)substr($arg, 10)));
}

define('BATCH_SIZE', 2000);     // domains per processing batch (from job queue)
define('SLEEP_NO_JOBS', 3);     // seconds when no jobs found
define('PROGRESS_INTERVAL', 5); // update job progress every N seconds

function log_msg(string $msg): void {
    $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
    echo "[" . date('Y-m-d H:i:s') . "] [{$mem}MB] $msg\n";
}

/**
 * Process an import job at maximum speed
 */
function processImportJob(PDO $db, WhoisService $whois, RdapEngine $rdap, array $job, int $numWorkers): void {
    $jobId = $job['id'];
    $domains = json_decode($job['data'], true);
    $processed = (int)$job['processed'];
    $errors = (int)$job['errors'];
    $userId = $job['user_id'];
    $total = count($domains);
    
    log_msg("IMPORT JOB #$jobId — {$processed}/{$total} done | concurrency={$rdap->getConcurrency()} workers=$numWorkers");
    
    $db->prepare("UPDATE jobs SET status = 'processing', updated_at = NOW() WHERE id = ?")->execute([$jobId]);
    
    $lastProgressUpdate = time();
    
    while ($processed < $total) {
        $batch = array_slice($domains, $processed, BATCH_SIZE);
        
        // 1. Clean domain names
        $cleanBatch = [];
        $invalidCount = 0;
        foreach ($batch as $raw) {
            $name = strtolower(trim($raw));
            $name = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $name);
            $name = rtrim($name, '/');
            if (empty($name) || !str_contains($name, '.')) {
                $invalidCount++;
                continue;
            }
            $cleanBatch[] = $name;
        }
        $errors += $invalidCount;
        $processed += $invalidCount;
        
        if (empty($cleanBatch)) {
            updateProgress($db, $jobId, $processed, $errors);
            continue;
        }
        
        // 2. Skip already-existing domains (batch check)
        $toCheck = filterExistingDomains($db, $cleanBatch);
        $skipped = count($cleanBatch) - count($toCheck);
        $processed += $skipped;
        
        if (empty($toCheck)) {
            updateProgress($db, $jobId, $processed, $errors);
            continue;
        }
        
        // 3. RDAP lookup — multi-process if configured, otherwise single-process rolling window
        $t = microtime(true);
        if ($numWorkers > 1) {
            $rdapResults = $rdap->lookupMultiProcess($toCheck, $numWorkers);
        } else {
            $rdapResults = $rdap->lookupBatch($toCheck);
        }
        $rdapTime = round(microtime(true) - $t, 2);
        $rdapHits = count(array_filter($rdapResults, fn($r) => $r !== null));
        
        // 4. Fallback for RDAP misses (sequential, but should be few)
        $fallbackCount = 0;
        foreach ($toCheck as $name) {
            if (($rdapResults[$name] ?? null) === null) {
                try {
                    $rdapResults[$name] = $whois->lookup($name);
                    $fallbackCount++;
                } catch (Exception $e) {
                    $rdapResults[$name] = ['domain' => $name, 'is_registered' => false, 'expiry_date' => null, 'registrar' => null, 'error' => $e->getMessage()];
                    $errors++;
                }
            }
        }
        
        // 5. Batch INSERT via transaction
        $insertCount = batchInsertDomains($db, $rdapResults, $toCheck, $userId);
        $processed += count($toCheck);
        
        $dps = count($toCheck) > 0 && $rdapTime > 0 ? round(count($toCheck) / $rdapTime) : 0;
        log_msg("  Job #$jobId: +{$insertCount} inserted, {$rdapHits} RDAP hits, {$fallbackCount} fallbacks | {$dps} domains/sec | {$processed}/{$total}");
        
        // Update progress (throttled)
        if (time() - $lastProgressUpdate >= PROGRESS_INTERVAL || $processed >= $total) {
            updateProgress($db, $jobId, $processed, $errors);
            $lastProgressUpdate = time();
        }
    }
    
    $db->prepare("UPDATE jobs SET status = 'completed', processed = ?, errors = ?, updated_at = NOW() WHERE id = ?")->execute([$processed, $errors, $jobId]);
    log_msg("JOB #$jobId COMPLETE — $processed domains, $errors errors");
}

/**
 * Process a WHOIS check job at maximum speed
 */
function processWhoisCheckJob(PDO $db, WhoisService $whois, RdapEngine $rdap, array $job, int $numWorkers): void {
    $jobId = $job['id'];
    $data = json_decode($job['data'], true);
    $domainIds = $data['domain_ids'] ?? [];
    $processed = (int)$job['processed'];
    $errors = (int)$job['errors'];
    $total = count($domainIds);
    
    log_msg("WHOIS CHECK JOB #$jobId — {$processed}/{$total} done | concurrency={$rdap->getConcurrency()} workers=$numWorkers");
    
    $db->prepare("UPDATE jobs SET status = 'processing', updated_at = NOW() WHERE id = ?")->execute([$jobId]);
    
    $lastProgressUpdate = time();
    
    while ($processed < $total) {
        $batchIds = array_slice($domainIds, $processed, BATCH_SIZE);
        
        // 1. Fetch domain records
        $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
        $stmt = $db->prepare("SELECT * FROM domains WHERE id IN ($placeholders)");
        $stmt->execute($batchIds);
        $domainRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $rowByName = [];
        $names = [];
        foreach ($domainRows as $row) {
            $names[] = $row['domain'];
            $rowByName[$row['domain']] = $row;
        }
        
        $missing = count($batchIds) - count($domainRows);
        $errors += $missing;
        $processed += $missing;
        
        if (empty($names)) {
            updateProgress($db, $jobId, $processed, $errors);
            continue;
        }
        
        // 2. RDAP lookup
        $t = microtime(true);
        if ($numWorkers > 1) {
            $rdapResults = $rdap->lookupMultiProcess($names, $numWorkers);
        } else {
            $rdapResults = $rdap->lookupBatch($names);
        }
        $rdapTime = round(microtime(true) - $t, 2);
        
        // 3. Fallback + batch update
        $db->beginTransaction();
        $updateStmt = $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = NOW() WHERE id = ?");
        
        foreach ($names as $name) {
            try {
                $data = $rdapResults[$name] ?? null;
                if ($data === null) {
                    $data = $whois->lookup($name);
                }
                $row = $rowByName[$name];
                $updateStmt->execute([
                    $data['expiry_date'] ?? $row['expiry_date'],
                    $data['is_registered'] ? 1 : 0,
                    $row['id']
                ]);
                $processed++;
            } catch (Exception $e) {
                $errors++;
                $processed++;
            }
        }
        $db->commit();
        
        $dps = count($names) > 0 && $rdapTime > 0 ? round(count($names) / $rdapTime) : 0;
        log_msg("  Job #$jobId: {$dps} domains/sec | {$processed}/{$total}");
        
        if (time() - $lastProgressUpdate >= PROGRESS_INTERVAL || $processed >= $total) {
            updateProgress($db, $jobId, $processed, $errors);
            $lastProgressUpdate = time();
        }
    }
    
    $db->prepare("UPDATE jobs SET status = 'completed', processed = ?, errors = ?, updated_at = NOW() WHERE id = ?")->execute([$processed, $errors, $jobId]);
    log_msg("JOB #$jobId COMPLETE — $processed checked, $errors errors");
}

/**
 * Filter out domains that already exist in the database (batch query)
 */
function filterExistingDomains(PDO $db, array $domainNames): array {
    if (empty($domainNames)) return [];
    
    $existing = [];
    // MySQL handles large IN clauses fine, but chunk at 10000 for memory
    foreach (array_chunk($domainNames, 10000) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $db->prepare("SELECT domain FROM domains WHERE domain IN ($placeholders)");
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $existing[$d] = true;
        }
    }
    
    return array_values(array_filter($domainNames, fn($d) => !isset($existing[$d])));
}

/**
 * Batch insert domains using a transaction (100x faster than individual INSERTs)
 */
function batchInsertDomains(PDO $db, array $rdapResults, array $names, int $userId): int {
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT IGNORE INTO domains (domain, expiry_date, is_registered, last_checked, added_by) VALUES (?, ?, ?, NOW(), ?)");
    
    $count = 0;
    foreach ($names as $name) {
        $data = $rdapResults[$name] ?? null;
        if ($data === null) continue;
        
        $stmt->execute([
            $name,
            $data['expiry_date'] ?? null,
            ($data['is_registered'] ?? false) ? 1 : 0,
            $userId,
        ]);
        $count++;
    }
    
    $db->commit();
    return $count;
}

function updateProgress(PDO $db, int $jobId, int $processed, int $errors): void {
    $db->prepare("UPDATE jobs SET processed = ?, errors = ?, updated_at = NOW() WHERE id = ?")->execute([$processed, $errors, $jobId]);
}

function getNextJob(PDO $db): ?array {
    $stmt = $db->query("SELECT * FROM jobs WHERE status IN ('pending', 'processing') ORDER BY created_at ASC LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Main ──
log_msg("=== DomainAlert Worker ===");
log_msg("Concurrency: $concurrency | Workers: $numWorkers | Mode: " . ($daemonMode ? 'daemon' : 'once'));
log_msg("Max simultaneous requests: " . ($concurrency * $numWorkers));

try {
    $db = initDatabase();
    
    $whois = new WhoisService();
    $rdap = new RdapEngine($concurrency);
    
    do {
        $job = getNextJob($db);
        
        if (!$job) {
            if ($daemonMode) {
                sleep(SLEEP_NO_JOBS);
                continue;
            }
            log_msg("No pending jobs");
            break;
        }
        
        $t = microtime(true);
        
        switch ($job['type']) {
            case 'import':
                processImportJob($db, $whois, $rdap, $job, $numWorkers);
                break;
            case 'whois_check':
                processWhoisCheckJob($db, $whois, $rdap, $job, $numWorkers);
                break;
            default:
                log_msg("Unknown job type: {$job['type']}");
                $db->prepare("UPDATE jobs SET status = 'failed', updated_at = NOW() WHERE id = ?")->execute([$job['id']]);
        }
        
        $elapsed = round(microtime(true) - $t, 1);
        log_msg("Job finished in {$elapsed}s");
        
    } while ($daemonMode);
    
    log_msg("Worker stopped");
    
} catch (Exception $e) {
    log_msg("FATAL: " . $e->getMessage());
    exit(1);
}
