<?php
/**
 * DomainAlert Background Job Worker — FAST parallel processing
 * 
 * Uses curl_multi for parallel RDAP lookups (20-50 domains at once)
 * 
 * Run continuously:  php worker.php daemon
 * Run once:          php worker.php
 * Custom parallel:   php worker.php daemon --parallel=50
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/services/WhoisService.php';

// Configuration
$parallelSize = 20; // default parallel requests
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--parallel=')) {
        $parallelSize = max(1, min(100, (int)substr($arg, 11)));
    }
}

define('PARALLEL_SIZE', $parallelSize);
define('SLEEP_NO_JOBS', 5);

$daemonMode = in_array('daemon', $argv ?? []);

// RDAP servers for parallel lookups
$RDAP_SERVERS = [
    'com'    => 'https://rdap.verisign.com/com/v1/domain/',
    'net'    => 'https://rdap.verisign.com/net/v1/domain/',
    'org'    => 'https://rdap.publicinterestregistry.org/rdap/domain/',
    'io'     => 'https://rdap.nic.io/domain/',
    'pl'     => 'https://rdap.dns.pl/domain/',
    'de'     => 'https://rdap.denic.de/domain/',
    'eu'     => 'https://rdap.eurid.eu/domain/',
    'uk'     => 'https://rdap.nominet.uk/uk/domain/',
    'fr'     => 'https://rdap.nic.fr/domain/',
    'nl'     => 'https://rdap.sidn.nl/domain/',
    'xyz'    => 'https://rdap.nic.xyz/domain/',
    'app'    => 'https://rdap.nic.google/domain/',
    'dev'    => 'https://rdap.nic.google/domain/',
    'co'     => 'https://rdap.nic.co/domain/',
    'me'     => 'https://rdap.nic.me/domain/',
    'info'   => 'https://rdap.afilias.net/rdap/info/domain/',
    'biz'    => 'https://rdap.nic.biz/domain/',
    'online' => 'https://rdap.centralnic.com/online/domain/',
    'site'   => 'https://rdap.centralnic.com/site/domain/',
    'ru'     => 'https://rdap.ripn.net/domain/',
];

function log_msg(string $msg): void {
    echo "[" . date('Y-m-d H:i:s') . "] $msg\n";
}

/**
 * Parallel RDAP lookup using curl_multi
 * Returns [domainName => parsed_result | null]
 */
function parallelRdap(array $domainNames): array {
    global $RDAP_SERVERS;
    
    $results = [];
    $mh = curl_multi_init();
    $handles = [];
    
    foreach ($domainNames as $name) {
        $parts = explode('.', $name);
        $tld = end($parts);
        $url = $RDAP_SERVERS[$tld] ?? null;
        
        if (!$url) {
            $results[$name] = null; // no RDAP server, will use fallback
            continue;
        }
        
        $ch = curl_init($url . $name);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/rdap+json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',
        ]);
        
        curl_multi_add_handle($mh, $ch);
        $handles[(int)$ch] = ['ch' => $ch, 'domain' => $name];
    }
    
    // Execute all in parallel
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) curl_multi_select($mh, 0.05);
    } while ($running > 0 && $status === CURLM_OK);
    
    // Collect results
    foreach ($handles as $info) {
        $ch = $info['ch'];
        $name = $info['domain'];
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($ch);
        
        if ($code === 200 && $body) {
            $json = json_decode($body, true);
            if ($json) {
                $r = ['domain' => $name, 'is_registered' => true, 'expiry_date' => null, 'registrar' => null, 'raw' => '', 'error' => null];
                if (isset($json['events'])) {
                    foreach ($json['events'] as $ev) {
                        if ($ev['eventAction'] === 'expiration') {
                            $r['expiry_date'] = date('Y-m-d', strtotime($ev['eventDate']));
                            break;
                        }
                    }
                }
                if (isset($json['entities'])) {
                    foreach ($json['entities'] as $ent) {
                        if (in_array('registrar', $ent['roles'] ?? [])) {
                            $r['registrar'] = $ent['vcardArray'][1][1][3] ?? ($ent['handle'] ?? null);
                            break;
                        }
                    }
                }
                $results[$name] = $r;
            } else {
                $results[$name] = null;
            }
        } elseif ($code === 404) {
            $results[$name] = ['domain' => $name, 'is_registered' => false, 'expiry_date' => null, 'registrar' => null, 'raw' => '', 'error' => null];
        } else {
            $results[$name] = null;
        }
        
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    
    curl_multi_close($mh);
    return $results;
}

function processImportJob(PDO $db, WhoisService $whois, array $job): void {
    $jobId = $job['id'];
    $domains = json_decode($job['data'], true);
    $processed = (int)$job['processed'];
    $errors = (int)$job['errors'];
    $userId = $job['user_id'];
    $total = count($domains);
    
    log_msg("Import job #$jobId — $processed/$total done, parallel=" . PARALLEL_SIZE);
    
    $db->prepare("UPDATE jobs SET status = 'processing', updated_at = datetime('now') WHERE id = ?")->execute([$jobId]);
    
    while ($processed < $total) {
        $batch = array_slice($domains, $processed, PARALLEL_SIZE);
        
        // Clean domain names
        $cleanBatch = [];
        foreach ($batch as $raw) {
            $name = strtolower(trim($raw));
            $name = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $name);
            $name = rtrim($name, '/');
            if (empty($name)) {
                $errors++;
                $processed++;
                continue;
            }
            $cleanBatch[] = $name;
        }
        
        // Skip already-existing domains
        if (!empty($cleanBatch)) {
            $placeholders = implode(',', array_fill(0, count($cleanBatch), '?'));
            $stmt = $db->prepare("SELECT domain FROM domains WHERE domain IN ($placeholders)");
            $stmt->execute($cleanBatch);
            $existing = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'domain');
            
            $toCheck = array_diff($cleanBatch, $existing);
            $skipped = count($cleanBatch) - count($toCheck);
            $processed += $skipped;
        } else {
            $toCheck = [];
        }
        
        if (empty($toCheck)) {
            // Update progress
            $db->prepare("UPDATE jobs SET processed = ?, errors = ?, updated_at = datetime('now') WHERE id = ?")->execute([$processed, $errors, $jobId]);
            continue;
        }
        
        // Parallel RDAP lookup
        $rdapResults = parallelRdap(array_values($toCheck));
        
        // Insert results, fallback for RDAP misses
        foreach ($toCheck as $name) {
            try {
                $data = $rdapResults[$name] ?? null;
                if ($data === null) {
                    $data = $whois->lookup($name); // sequential fallback
                }
                
                $db->prepare("INSERT OR IGNORE INTO domains (domain, expiry_date, is_registered, last_checked, added_by) VALUES (?, ?, ?, datetime('now'), ?)")
                   ->execute([$name, $data['expiry_date'], $data['is_registered'] ? 1 : 0, $userId]);
                
                $processed++;
            } catch (Exception $e) {
                $errors++;
                $processed++;
            }
        }
        
        // Update progress after each batch
        $pct = $total > 0 ? round($processed / $total * 100) : 0;
        log_msg("  Job #$jobId: $processed/$total ($pct%) — errors: $errors");
        $db->prepare("UPDATE jobs SET processed = ?, errors = ?, updated_at = datetime('now') WHERE id = ?")->execute([$processed, $errors, $jobId]);
    }
    
    $db->prepare("UPDATE jobs SET status = 'completed', processed = ?, errors = ?, updated_at = datetime('now') WHERE id = ?")->execute([$processed, $errors, $jobId]);
    log_msg("Job #$jobId DONE — $processed domains, $errors errors");
}

function processWhoisCheckJob(PDO $db, WhoisService $whois, array $job): void {
    $jobId = $job['id'];
    $data = json_decode($job['data'], true);
    $domainIds = $data['domain_ids'] ?? [];
    $processed = (int)$job['processed'];
    $errors = (int)$job['errors'];
    $total = count($domainIds);
    
    log_msg("WHOIS check job #$jobId — $processed/$total done, parallel=" . PARALLEL_SIZE);
    
    $db->prepare("UPDATE jobs SET status = 'processing', updated_at = datetime('now') WHERE id = ?")->execute([$jobId]);
    
    while ($processed < $total) {
        $batchIds = array_slice($domainIds, $processed, PARALLEL_SIZE);
        
        // Fetch domain records
        $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
        $stmt = $db->prepare("SELECT * FROM domains WHERE id IN ($placeholders)");
        $stmt->execute($batchIds);
        $domainRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Map by name for results
        $idByName = [];
        $names = [];
        foreach ($domainRows as $row) {
            $names[] = $row['domain'];
            $idByName[$row['domain']] = $row;
        }
        
        // Account for missing domain IDs
        $missing = count($batchIds) - count($domainRows);
        $errors += $missing;
        $processed += $missing;
        
        if (!empty($names)) {
            // Parallel RDAP
            $rdapResults = parallelRdap($names);
            
            foreach ($names as $name) {
                try {
                    $whoisData = $rdapResults[$name] ?? null;
                    if ($whoisData === null) {
                        $whoisData = $whois->lookup($name);
                    }
                    
                    $row = $idByName[$name];
                    $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = datetime('now') WHERE id = ?")
                       ->execute([$whoisData['expiry_date'] ?? $row['expiry_date'], $whoisData['is_registered'] ? 1 : 0, $row['id']]);
                    
                    $processed++;
                } catch (Exception $e) {
                    $errors++;
                    $processed++;
                }
            }
        }
        
        $pct = $total > 0 ? round($processed / $total * 100) : 0;
        log_msg("  Job #$jobId: $processed/$total ($pct%) — errors: $errors");
        $db->prepare("UPDATE jobs SET processed = ?, errors = ?, updated_at = datetime('now') WHERE id = ?")->execute([$processed, $errors, $jobId]);
    }
    
    $db->prepare("UPDATE jobs SET status = 'completed', processed = ?, errors = ?, updated_at = datetime('now') WHERE id = ?")->execute([$processed, $errors, $jobId]);
    log_msg("Job #$jobId DONE — $processed checked, $errors errors");
}

function getNextJob(PDO $db): ?array {
    $stmt = $db->query("SELECT * FROM jobs WHERE status IN ('pending', 'processing') ORDER BY created_at ASC LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Main ──
log_msg("Worker started (parallel=" . PARALLEL_SIZE . ", mode=" . ($daemonMode ? 'daemon' : 'once') . ")");

try {
    $db = initDatabase();
    $whois = new WhoisService();
    
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
                processImportJob($db, $whois, $job);
                break;
            case 'whois_check':
                processWhoisCheckJob($db, $whois, $job);
                break;
            default:
                log_msg("Unknown job type: {$job['type']}");
                $db->prepare("UPDATE jobs SET status = 'failed', updated_at = datetime('now') WHERE id = ?")->execute([$job['id']]);
        }
        
        $elapsed = round(microtime(true) - $t, 1);
        log_msg("Finished in {$elapsed}s");
        
    } while ($daemonMode);
    
    log_msg("Worker stopped");
    
} catch (Exception $e) {
    log_msg("FATAL: " . $e->getMessage());
    exit(1);
}
