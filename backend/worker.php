<?php
/**
 * DomainAlert Background Job Worker
 * 
 * Processes pending jobs in the background.
 * Run continuously with:    php worker.php daemon
 * Run once:                 php worker.php
 * 
 * For production, set up with cron (every minute):
 * * * * * * cd /path/to/backend && php worker.php >> /var/log/domainalert-worker.log 2>&1
 * 
 * Or run as a daemon with supervisor/systemd
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/services/WhoisService.php';

// Configuration
define('BATCH_SIZE', 100);          // Domains per batch (increased from 20)
define('SLEEP_BETWEEN_JOBS', 1);    // Seconds between jobs
define('SLEEP_NO_JOBS', 10);        // Seconds when no jobs found
define('RATE_LIMIT_DELAY', 0.2);    // Seconds between WHOIS requests (200ms)

$daemonMode = isset($argv[1]) && $argv[1] === 'daemon';

function log_message(string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $message\n";
}

function processImportJob(PDO $db, WhoisService $whois, array $job): void {
    $jobId = $job['id'];
    $domains = json_decode($job['data'], true);
    $processed = (int)$job['processed'];
    $errors = (int)$job['errors'];
    $userId = $job['user_id'];
    
    log_message("Processing import job #$jobId - {$job['processed']}/{$job['total']} done");
    
    // Mark as processing
    $stmt = $db->prepare("UPDATE jobs SET status = 'processing', updated_at = datetime('now') WHERE id = ?");
    $stmt->execute([$jobId]);
    
    // Get batch of domains to process
    $batch = array_slice($domains, $processed, BATCH_SIZE);
    
    foreach ($batch as $domainName) {
        try {
            $domainName = strtolower(trim($domainName));
            $domainName = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $domainName);
            $domainName = rtrim($domainName, '/');
            
            if (empty($domainName)) {
                $errors++;
                $processed++;
                continue;
            }
            
            // Check if domain already exists
            $checkStmt = $db->prepare("SELECT id FROM domains WHERE domain = ?");
            $checkStmt->execute([$domainName]);
            if ($checkStmt->fetch()) {
                // Domain already exists, skip
                $processed++;
                continue;
            }
            
            // Check WHOIS
            $whoisData = $whois->lookup($domainName);
            
            // Insert domain
            $stmt = $db->prepare("INSERT OR IGNORE INTO domains (domain, expiry_date, is_registered, last_checked, added_by) VALUES (?, ?, ?, datetime('now'), ?)");
            $stmt->execute([
                $domainName,
                $whoisData['expiry_date'],
                $whoisData['is_registered'] ? 1 : 0,
                $userId
            ]);
            
            $processed++;
            log_message("  ✓ $domainName - " . ($whoisData['is_registered'] ? 'registered' : 'available'));
            
            // Rate limiting
            usleep(RATE_LIMIT_DELAY * 1000000);
            
        } catch (Exception $e) {
            $errors++;
            $processed++;
            log_message("  ✗ $domainName - " . $e->getMessage());
        }
        
        // Update progress periodically
        if ($processed % 5 === 0) {
            $stmt = $db->prepare("UPDATE jobs SET processed = ?, errors = ?, updated_at = datetime('now') WHERE id = ?");
            $stmt->execute([$processed, $errors, $jobId]);
        }
    }
    
    // Final update
    $status = $processed >= count($domains) ? 'completed' : 'processing';
    $stmt = $db->prepare("UPDATE jobs SET status = ?, processed = ?, errors = ?, updated_at = datetime('now') WHERE id = ?");
    $stmt->execute([$status, $processed, $errors, $jobId]);
    
    if ($status === 'completed') {
        log_message("Job #$jobId completed: $processed domains, $errors errors");
    }
}

function processWhoisCheckJob(PDO $db, WhoisService $whois, array $job): void {
    $jobId = $job['id'];
    $data = json_decode($job['data'], true);
    $domainIds = $data['domain_ids'] ?? [];
    $processed = (int)$job['processed'];
    $errors = (int)$job['errors'];
    
    log_message("Processing WHOIS check job #$jobId - {$job['processed']}/{$job['total']} done");
    
    // Mark as processing
    $stmt = $db->prepare("UPDATE jobs SET status = 'processing', updated_at = datetime('now') WHERE id = ?");
    $stmt->execute([$jobId]);
    
    // Get batch of domains to check
    $batch = array_slice($domainIds, $processed, BATCH_SIZE);
    
    foreach ($batch as $domainId) {
        try {
            // Get domain
            $stmt = $db->prepare("SELECT * FROM domains WHERE id = ?");
            $stmt->execute([$domainId]);
            $domain = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$domain) {
                $errors++;
                $processed++;
                continue;
            }
            
            // Check WHOIS
            $whoisData = $whois->lookup($domain['domain']);
            
            // Update domain
            $stmt = $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = datetime('now') WHERE id = ?");
            $stmt->execute([
                $whoisData['expiry_date'],
                $whoisData['is_registered'] ? 1 : 0,
                $domain['id']
            ]);
            
            $processed++;
            log_message("  ✓ {$domain['domain']} - expires: " . ($whoisData['expiry_date'] ?? 'unknown'));
            
            // Rate limiting
            usleep(RATE_LIMIT_DELAY * 1000000);
            
        } catch (Exception $e) {
            $errors++;
            $processed++;
            log_message("  ✗ Domain ID $domainId - " . $e->getMessage());
        }
        
        // Update progress periodically
        if ($processed % 5 === 0) {
            $stmt = $db->prepare("UPDATE jobs SET processed = ?, errors = ?, updated_at = datetime('now') WHERE id = ?");
            $stmt->execute([$processed, $errors, $jobId]);
        }
    }
    
    // Final update
    $status = $processed >= count($domainIds) ? 'completed' : 'processing';
    $stmt = $db->prepare("UPDATE jobs SET status = ?, processed = ?, errors = ?, updated_at = datetime('now') WHERE id = ?");
    $stmt->execute([$status, $processed, $errors, $jobId]);
    
    if ($status === 'completed') {
        log_message("Job #$jobId completed: $processed domains checked, $errors errors");
    }
}

function getNextJob(PDO $db): ?array {
    $stmt = $db->query("SELECT * FROM jobs WHERE status IN ('pending', 'processing') ORDER BY created_at ASC LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Main loop
log_message("Starting DomainAlert worker" . ($daemonMode ? " in daemon mode" : ""));

try {
    $db = initDatabase();
    $whois = new WhoisService();
    
    do {
        $job = getNextJob($db);
        
        if (!$job) {
            if ($daemonMode) {
                log_message("No jobs found, sleeping for " . SLEEP_NO_JOBS . "s");
                sleep(SLEEP_NO_JOBS);
                continue;
            } else {
                log_message("No pending jobs");
                break;
            }
        }
        
        switch ($job['type']) {
            case 'import':
                processImportJob($db, $whois, $job);
                break;
            case 'whois_check':
                processWhoisCheckJob($db, $whois, $job);
                break;
            default:
                log_message("Unknown job type: {$job['type']}");
                $stmt = $db->prepare("UPDATE jobs SET status = 'failed', updated_at = datetime('now') WHERE id = ?");
                $stmt->execute([$job['id']]);
        }
        
        // Check if job is still processing (not completed)
        if ($job['status'] !== 'completed') {
            // Small delay between batches
            sleep(SLEEP_BETWEEN_JOBS);
        }
        
    } while ($daemonMode || ($job && $job['status'] !== 'completed'));
    
    log_message("Worker finished");
    
} catch (Exception $e) {
    log_message("FATAL ERROR: " . $e->getMessage());
    exit(1);
}
