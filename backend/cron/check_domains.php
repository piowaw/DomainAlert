<?php
/**
 * Domain expiry checker — parallel RDAP, no artificial delays
 * 
 * Run via cron every minute:
 *   php /path/to/check_domains.php
 * 
 * Or continuous daemon:
 *   php /path/to/check_domains.php daemon
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/WhoisService.php';
require_once __DIR__ . '/../services/NotificationService.php';

$RDAP_SERVERS = [
    'com' => 'https://rdap.verisign.com/com/v1/domain/',
    'net' => 'https://rdap.verisign.com/net/v1/domain/',
    'org' => 'https://rdap.publicinterestregistry.org/rdap/domain/',
    'io'  => 'https://rdap.nic.io/domain/',
    'pl'  => 'https://rdap.dns.pl/domain/',
    'de'  => 'https://rdap.denic.de/domain/',
    'eu'  => 'https://rdap.eurid.eu/domain/',
    'uk'  => 'https://rdap.nominet.uk/uk/domain/',
    'fr'  => 'https://rdap.nic.fr/domain/',
    'nl'  => 'https://rdap.sidn.nl/domain/',
    'xyz' => 'https://rdap.nic.xyz/domain/',
    'app' => 'https://rdap.nic.google/domain/',
    'dev' => 'https://rdap.nic.google/domain/',
    'co'  => 'https://rdap.nic.co/domain/',
    'me'  => 'https://rdap.nic.me/domain/',
    'info'=> 'https://rdap.afilias.net/rdap/info/domain/',
    'biz' => 'https://rdap.nic.biz/domain/',
    'ru'  => 'https://rdap.ripn.net/domain/',
];

define('PARALLEL', 30);
define('STALE_BATCH', 200);

$daemonMode = in_array('daemon', $argv ?? []);

function log_msg(string $m): void { echo "[" . date('Y-m-d H:i:s') . "] $m\n"; }

function parallelRdap(array $domainRows): array {
    global $RDAP_SERVERS;
    $mh = curl_multi_init();
    $handles = [];
    $results = [];

    foreach ($domainRows as $row) {
        $name = $row['domain'];
        $tld = substr($name, strrpos($name, '.') + 1);
        $url = $RDAP_SERVERS[$tld] ?? null;
        if (!$url) { $results[$name] = null; continue; }

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

    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) curl_multi_select($mh, 0.05);
    } while ($running > 0 && $status === CURLM_OK);

    foreach ($handles as $info) {
        $ch = $info['ch'];
        $name = $info['domain'];
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($ch);

        if ($code === 200 && $body) {
            $json = json_decode($body, true);
            $expiry = null;
            if (isset($json['events'])) {
                foreach ($json['events'] as $ev) {
                    if ($ev['eventAction'] === 'expiration') {
                        $expiry = date('Y-m-d', strtotime($ev['eventDate']));
                        break;
                    }
                }
            }
            $results[$name] = ['is_registered' => true, 'expiry_date' => $expiry];
        } elseif ($code === 404) {
            $results[$name] = ['is_registered' => false, 'expiry_date' => null];
        } else {
            $results[$name] = null; // fallback needed
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

function processBatch(PDO $db, WhoisService $whois, NotificationService $notifications, array $domainRows): int {
    $chunks = array_chunk($domainRows, PARALLEL);
    $checked = 0;

    foreach ($chunks as $chunk) {
        $rdap = parallelRdap($chunk);

        foreach ($chunk as $row) {
            $name = $row['domain'];
            $data = $rdap[$name] ?? null;

            if ($data === null) {
                try { $data = $whois->lookup($name); } catch (Exception $e) { continue; }
            }

            $wasRegistered = (bool)$row['is_registered'];
            $isNowAvailable = !$data['is_registered'];

            $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = datetime('now') WHERE id = ?")
               ->execute([$data['expiry_date'] ?? $row['expiry_date'], $data['is_registered'] ? 1 : 0, $row['id']]);

            if ($wasRegistered && $isNowAvailable) {
                log_msg(">>> AVAILABLE: $name");
                $notifications->notifyDomainAvailable($row['id'], $name);
            }
            $checked++;
        }
    }
    return $checked;
}

// ── Main ──
$db = initDatabase();
$whois = new WhoisService();
$notifications = new NotificationService($db);

do {
    $t = microtime(true);

    // 1) Expiring / expired domains (registered, expiry <= today)
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT * FROM domains WHERE is_registered = 1 AND expiry_date <= ? ORDER BY expiry_date ASC");
    $stmt->execute([$today]);
    $expiring = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($expiring) > 0) {
        log_msg("Checking " . count($expiring) . " expiring/expired domains...");
        $n = processBatch($db, $whois, $notifications, $expiring);
        log_msg("  Done: $n checked");
    }

    // 2) Stale domains (not checked in 24h)
    $stmt = $db->query("SELECT * FROM domains WHERE last_checked < datetime('now', '-24 hours') OR last_checked IS NULL LIMIT " . STALE_BATCH);
    $stale = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($stale) > 0) {
        log_msg("Refreshing " . count($stale) . " stale domains...");
        $n = processBatch($db, $whois, $notifications, $stale);
        log_msg("  Done: $n refreshed");
    }

    $elapsed = round(microtime(true) - $t, 1);
    log_msg("Cycle complete in {$elapsed}s (" . count($expiring) . " expiring, " . count($stale) . " stale)");

    if ($daemonMode && count($stale) === 0 && count($expiring) === 0) {
        sleep(30); // nothing to do, wait 30s
    }

} while ($daemonMode);

log_msg("Done.");
