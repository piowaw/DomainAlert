<?php
/**
 * Cron job to check domain expiry status
 * 
 * Run this every minute:
 * * * * * * php /path/to/check_domains.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/WhoisService.php';
require_once __DIR__ . '/../services/NotificationService.php';

$db = initDatabase();
$whois = new WhoisService();
$notifications = new NotificationService($db);

echo "[" . date('Y-m-d H:i:s') . "] Starting domain check...\n";

// Get domains expiring today or already expired
$today = date('Y-m-d');
$stmt = $db->prepare("
    SELECT * FROM domains 
    WHERE is_registered = 1 
    AND expiry_date <= ? 
    ORDER BY expiry_date ASC
");
$stmt->execute([$today]);
$expiringDomains = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($expiringDomains) . " domains expiring today or earlier\n";

foreach ($expiringDomains as $domain) {
    echo "Checking: {$domain['domain']} (expires: {$domain['expiry_date']})\n";
    
    $whoisData = $whois->lookup($domain['domain']);
    
    $wasRegistered = $domain['is_registered'];
    $isNowAvailable = !$whoisData['is_registered'];
    
    // Update domain status
    $stmt = $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = datetime('now') WHERE id = ?");
    $stmt->execute([
        $whoisData['expiry_date'] ?? $domain['expiry_date'],
        $whoisData['is_registered'] ? 1 : 0,
        $domain['id']
    ]);
    
    // If domain became available, send notification
    if ($wasRegistered && $isNowAvailable) {
        echo ">>> DOMAIN AVAILABLE: {$domain['domain']}\n";
        $notifications->notifyDomainAvailable($domain['id'], $domain['domain']);
    }
    
    // Rate limiting
    sleep(1);
}

// Also check domains that haven't been checked in over 24 hours
$stmt = $db->prepare("
    SELECT * FROM domains 
    WHERE last_checked < datetime('now', '-24 hours')
    OR last_checked IS NULL
    LIMIT 10
");
$stmt->execute();
$staleDoains = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\nChecking " . count($staleDoains) . " domains not checked in 24h\n";

foreach ($staleDoains as $domain) {
    echo "Refreshing: {$domain['domain']}\n";
    
    $whoisData = $whois->lookup($domain['domain']);
    
    $wasRegistered = $domain['is_registered'];
    $isNowAvailable = !$whoisData['is_registered'];
    
    $stmt = $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = datetime('now') WHERE id = ?");
    $stmt->execute([
        $whoisData['expiry_date'] ?? $domain['expiry_date'],
        $whoisData['is_registered'] ? 1 : 0,
        $domain['id']
    ]);
    
    if ($wasRegistered && $isNowAvailable) {
        echo ">>> DOMAIN AVAILABLE: {$domain['domain']}\n";
        $notifications->notifyDomainAvailable($domain['id'], $domain['domain']);
    }
    
    sleep(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Check complete.\n";
