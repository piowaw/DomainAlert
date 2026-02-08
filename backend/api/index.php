<?php

// Global error handling — NEVER return HTML, always JSON
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        // Clean any partial output
        if (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Wewnętrzny błąd serwera: ' . $error['message'],
            'type' => 'fatal',
        ]);
    }
});

set_exception_handler(function(Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Błąd serwera: ' . $e->getMessage(),
        'type' => 'exception',
    ]);
    error_log("Uncaught exception in API: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
});

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/WhoisService.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/ScraperService.php';
require_once __DIR__ . '/../services/AiService.php';
require_once __DIR__ . '/../services/RdapEngine.php';

$db = initDatabase();

// Auto-migrate: ensure jobs table exists
try {
    $result = $db->query("SHOW TABLES LIKE 'jobs'");
    if (!$result->fetch()) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                type VARCHAR(50) NOT NULL,
                status VARCHAR(20) DEFAULT 'pending',
                total INT DEFAULT 0,
                processed INT DEFAULT 0,
                errors INT DEFAULT 0,
                data LONGTEXT,
                result LONGTEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
} catch (Exception $e) {
    // Ignore migration errors
}

$whois = new WhoisService();
$notifications = new NotificationService($db);
$scraper = new ScraperService();
$ai = new AiService($db);

// Router
$requestUri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

// Ensure JSON content type for all API responses
header('Content-Type: application/json; charset=utf-8');

// Disable output buffering that might cause partial HTML output
if (ob_get_level()) ob_end_clean();

// Parse path
$path = parse_url($requestUri, PHP_URL_PATH);
$path = str_replace('/api/', '', $path);
$path = trim($path, '/');
$segments = explode('/', $path);

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Routes
try {
    switch ($segments[0]) {
        case 'auth':
            handleAuth($segments[1] ?? '', $db, $input);
            break;
        case 'domains':
            handleDomains($segments[1] ?? '', $db, $whois, $notifications, $scraper, $ai, $input, $method);
            break;
        case 'users':
            handleUsers($segments[1] ?? '', $db, $input, $method);
            break;
        case 'invitations':
            handleInvitations($segments[1] ?? '', $db, $input, $method);
            break;
        case 'notifications':
            handleNotifications($segments[1] ?? '', $db, $notifications, $input, $method);
            break;
        case 'jobs':
            handleJobs($segments[1] ?? '', $db, $whois, $input, $method);
            break;
        case 'ai':
            handleAi($segments[1] ?? '', $segments[2] ?? '', $db, $ai, $input, $method);
            break;
        case 'profile':
            handleProfile($db, $input, $method);
            break;
        case 'migrate':
            handleMigrate($db);
            break;
        case 'fix-config':
            handleFixConfig();
            break;
        case 'deploy-sync':
            handleDeploySync();
            break;
        default:
            jsonResponse(['error' => 'Not found'], 404);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

function handleAuth(string $action, PDO $db, array $input): void {
    switch ($action) {
        case 'login':
            $email = $input['email'] ?? '';
            $password = $input['password'] ?? '';
            
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !password_verify($password, $user['password'])) {
                jsonResponse(['error' => 'Invalid credentials'], 401);
            }
            
            $token = createJWT([
                'id' => $user['id'],
                'email' => $user['email'],
                'is_admin' => (bool)$user['is_admin']
            ]);
            
            jsonResponse([
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'is_admin' => (bool)$user['is_admin']
                ]
            ]);
            break;
            
        case 'register':
            $email = $input['email'] ?? '';
            $password = $input['password'] ?? '';
            $inviteToken = $input['invite_token'] ?? '';
            
            if (!$email || !$password || !$inviteToken) {
                jsonResponse(['error' => 'Missing required fields'], 400);
            }
            
            // Verify invitation
            $stmt = $db->prepare("SELECT * FROM invitations WHERE token = ? AND used = 0");
            $stmt->execute([$inviteToken]);
            $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invitation) {
                jsonResponse(['error' => 'Invalid or used invitation'], 400);
            }
            
            if ($invitation['email'] && $invitation['email'] !== $email) {
                jsonResponse(['error' => 'Email does not match invitation'], 400);
            }
            
            // Create user
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (email, password) VALUES (?, ?)");
            
            try {
                $stmt->execute([$email, $hashedPassword]);
            } catch (PDOException $e) {
                jsonResponse(['error' => 'Email already exists'], 400);
            }
            
            $userId = $db->lastInsertId();
            
            // Mark invitation as used
            $stmt = $db->prepare("UPDATE invitations SET used = 1 WHERE id = ?");
            $stmt->execute([$invitation['id']]);
            
            $token = createJWT([
                'id' => $userId,
                'email' => $email,
                'is_admin' => false
            ]);
            
            jsonResponse([
                'token' => $token,
                'user' => [
                    'id' => $userId,
                    'email' => $email,
                    'is_admin' => false
                ]
            ]);
            break;
            
        case 'me':
            $user = requireAuth();
            jsonResponse(['user' => $user]);
            break;
            
        default:
            jsonResponse(['error' => 'Not found'], 404);
    }
}

function handleDomains(string $action, PDO $db, WhoisService $whois, NotificationService $notifications, ScraperService $scraper, AiService $ai, array $input, string $method): void {
    $user = requireAuth();
    
    if ($action === '' && $method === 'GET') {
        // List domains with search, filter and pagination
        $search = $_GET['search'] ?? '';
        $filter = $_GET['filter'] ?? 'all'; // all, registered, available, expiring
        $sortBy = $_GET['sort'] ?? 'expiry_date';
        $sortDir = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = max(1, min(1000, intval($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        
        // Build query
        $where = [];
        $params = [];
        
        if ($search) {
            $where[] = "d.domain LIKE ?";
            $params[] = "%$search%";
        }
        
        switch ($filter) {
            case 'registered':
                $where[] = "d.is_registered = 1";
                break;
            case 'available':
                $where[] = "d.is_registered = 0";
                break;
            case 'expiring':
                $where[] = "d.is_registered = 1 AND d.expiry_date IS NOT NULL AND d.expiry_date <= DATE_ADD(NOW(), INTERVAL 30 DAY)";
                break;
        }
        
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // Allowed sort fields
        $sortFields = ['domain', 'expiry_date', 'is_registered', 'last_checked', 'created_at'];
        if (!in_array($sortBy, $sortFields)) $sortBy = 'expiry_date';
        
        // Special handling for expiry_date to put NULLs last
        $orderClause = $sortBy === 'expiry_date' 
            ? "ORDER BY d.expiry_date IS NULL, d.expiry_date $sortDir"
            : "ORDER BY d.$sortBy $sortDir";
        
        // Get total count
        $countStmt = $db->prepare("SELECT COUNT(*) FROM domains d $whereClause");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();
        
        // Get domains
        $query = "SELECT d.*, u.email as added_by_email FROM domains d LEFT JOIN users u ON d.added_by = u.id $whereClause $orderClause LIMIT $limit OFFSET $offset";
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'domains' => $domains,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'total_pages' => (int)ceil($total / $limit)
            ]
        ]);
    }
    
    if ($action === '' && $method === 'POST') {
        // Add new domain
        $domain = $input['domain'] ?? '';
        
        if (!$domain) {
            jsonResponse(['error' => 'Domain is required'], 400);
        }
        
        // Clean domain
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $domain);
        $domain = rtrim($domain, '/');
        
        // Check WHOIS
        $whoisData = $whois->lookup($domain);
        
        // Insert domain
        $stmt = $db->prepare("INSERT IGNORE INTO domains (domain, expiry_date, is_registered, last_checked, added_by) VALUES (?, ?, ?, NOW(), ?)");
        $stmt->execute([
            $domain,
            $whoisData['expiry_date'],
            $whoisData['is_registered'] ? 1 : 0,
            $user['id']
        ]);
        
        if ($stmt->rowCount() == 0) {
            // Domain already exists, update it
            $stmt = $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = NOW() WHERE domain = ?");
            $stmt->execute([$whoisData['expiry_date'], $whoisData['is_registered'] ? 1 : 0, $domain]);
        }
        
        // Get the domain
        $stmt = $db->prepare("SELECT * FROM domains WHERE domain = ?");
        $stmt->execute([$domain]);
        $domainData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If domain is available, notify immediately
        if (!$whoisData['is_registered']) {
            $notifications->notifyDomainAvailable($domainData['id'], $domain);
        }
        
        jsonResponse([
            'domain' => $domainData,
            'whois' => $whoisData
        ], 201);
    }
    
    if ($action === 'import' && $method === 'POST') {
        // Import domains from text
        $text = $input['text'] ?? '';
        $domains = preg_split('/[\s,;]+/', $text);
        $domains = array_filter(array_map('trim', $domains));
        
        $results = [];
        foreach ($domains as $domain) {
            $domain = strtolower($domain);
            $domain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $domain);
            $domain = rtrim($domain, '/');
            
            if (empty($domain)) continue;
            
            $whoisData = $whois->lookup($domain);
            
            $stmt = $db->prepare("INSERT IGNORE INTO domains (domain, expiry_date, is_registered, last_checked, added_by) VALUES (?, ?, ?, NOW(), ?)");
            $stmt->execute([
                $domain,
                $whoisData['expiry_date'],
                $whoisData['is_registered'] ? 1 : 0,
                $user['id']
            ]);
            
            $results[] = [
                'domain' => $domain,
                'expiry_date' => $whoisData['expiry_date'],
                'is_registered' => $whoisData['is_registered'],
                'added' => $stmt->rowCount() > 0
            ];
            
            // Rate limiting - wait a bit between WHOIS queries
            usleep(500000); // 0.5 second
        }
        
        jsonResponse(['imported' => $results]);
    }
    
    if ($action === 'check' && $method === 'POST') {
        // Check a single domain (for manual refresh)
        $domainId = $input['id'] ?? 0;
        
        $stmt = $db->prepare("SELECT * FROM domains WHERE id = ?");
        $stmt->execute([$domainId]);
        $domain = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$domain) {
            jsonResponse(['error' => 'Domain not found'], 404);
        }
        
        $whoisData = $whois->lookup($domain['domain']);
        
        $wasRegistered = $domain['is_registered'];
        $isNowAvailable = !$whoisData['is_registered'];
        
        $stmt = $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = NOW() WHERE id = ?");
        $stmt->execute([$whoisData['expiry_date'], $whoisData['is_registered'] ? 1 : 0, $domainId]);
        
        // If domain became available, notify
        if ($wasRegistered && $isNowAvailable) {
            $notifications->notifyDomainAvailable($domainId, $domain['domain']);
        }
        
        $stmt = $db->prepare("SELECT * FROM domains WHERE id = ?");
        $stmt->execute([$domainId]);
        $updatedDomain = $stmt->fetch(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'domain' => $updatedDomain,
            'whois' => $whoisData
        ]);
    }
    
    if (is_numeric($action) && $method === 'DELETE') {
        // Delete domain
        $stmt = $db->prepare("DELETE FROM domains WHERE id = ?");
        $stmt->execute([$action]);
        jsonResponse(['success' => true]);
    }
    
    // Get domain stats
    if ($action === 'stats' && $method === 'GET') {
        $stmt = $db->query("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_registered = 1 THEN 1 ELSE 0 END) as registered,
            SUM(CASE WHEN is_registered = 0 THEN 1 ELSE 0 END) as available,
            SUM(CASE WHEN is_registered = 1 AND expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiring
        FROM domains");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'total' => (int)$stats['total'],
            'registered' => (int)$stats['registered'],
            'available' => (int)$stats['available'],
            'expiring' => (int)$stats['expiring']
        ]);
    }
    
    // Get full domain details (WHOIS + scrape + Google + DNS) — AI loaded separately
    if (is_numeric($action) && $method === 'GET') {
        $stmt = $db->prepare("SELECT * FROM domains WHERE id = ?");
        $stmt->execute([$action]);
        $domain = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$domain) {
            jsonResponse(['error' => 'Domain not found'], 404);
        }
        
        $forceRefresh = isset($_GET['refresh']);
        $cached = $ai->getCachedDomainDetails((int)$action);
        
        if ($cached && !$forceRefresh) {
            jsonResponse([
                'domain' => $domain,
                'details' => $cached,
                'cached' => true,
            ]);
        }
        
        // Gather all data EXCEPT AI (AI is loaded async via separate endpoint)
        $whoisData = $whois->lookup($domain['domain']);
        $scrapeData = $scraper->scrapeWebsite($domain['domain']);
        $googleData = $scraper->searchGoogle($domain['domain']);
        $dnsRecords = $scraper->getDnsRecords($domain['domain']);
        
        // Cache the results without AI (AI will be cached separately)
        $details = [
            'whois_raw' => $whoisData['raw'] ?? '',
            'whois_parsed' => $whoisData,
            'scrape_data' => $scrapeData,
            'google_data' => $googleData,
            'dns_records' => $dnsRecords,
            'ai_analysis' => null,
        ];
        
        $ai->cacheDomainDetails((int)$action, $details);
        
        // Update domain info
        $stmt = $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = NOW() WHERE id = ?");
        $stmt->execute([$whoisData['expiry_date'], $whoisData['is_registered'] ? 1 : 0, $action]);
        
        // Reload domain
        $stmt = $db->prepare("SELECT * FROM domains WHERE id = ?");
        $stmt->execute([$action]);
        $domain = $stmt->fetch(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'domain' => $domain,
            'details' => [
                'whois_raw' => $whoisData['raw'] ?? '',
                'whois_parsed' => $whoisData,
                'scrape_data' => $scrapeData,
                'google_data' => $googleData,
                'dns_records' => $dnsRecords,
                'ai_analysis' => null,
                'scraped_at' => date('Y-m-d H:i:s'),
            ],
            'cached' => false,
        ]);
    }
    
    jsonResponse(['error' => 'Not found'], 404);
}

function handleUsers(string $action, PDO $db, array $input, string $method): void {
    $user = requireAdmin();
    
    if ($action === '' && $method === 'GET') {
        $stmt = $db->query("SELECT id, email, is_admin, created_at FROM users");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['users' => $users]);
    }
    
    // Create user with generated password
    if ($action === '' && $method === 'POST') {
        $email = $input['email'] ?? '';
        
        if (!$email) {
            jsonResponse(['error' => 'Email is required'], 400);
        }
        
        // Generate random password
        $password = bin2hex(random_bytes(6)); // 12 character password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            $stmt = $db->prepare("INSERT INTO users (email, password) VALUES (?, ?)");
            $stmt->execute([$email, $hashedPassword]);
        } catch (PDOException $e) {
            jsonResponse(['error' => 'Email already exists'], 400);
        }
        
        $userId = $db->lastInsertId();
        
        jsonResponse([
            'user' => [
                'id' => $userId,
                'email' => $email,
                'is_admin' => false,
            ],
            'password' => $password // Return to admin so they can share it
        ], 201);
    }
    
    if (is_numeric($action) && $method === 'DELETE') {
        if ($action == $user['id']) {
            jsonResponse(['error' => 'Cannot delete yourself'], 400);
        }
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$action]);
        jsonResponse(['success' => true]);
    }
    
    // Update user role (admin only)
    if (is_numeric($action) && $method === 'PUT') {
        $targetUserId = (int)$action;
        
        if ($targetUserId == $user['id']) {
            jsonResponse(['error' => 'Cannot change your own role'], 400);
        }
        
        $isAdmin = isset($input['is_admin']) ? ($input['is_admin'] ? 1 : 0) : null;
        
        if ($isAdmin === null) {
            jsonResponse(['error' => 'is_admin is required'], 400);
        }
        
        $stmt = $db->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
        $stmt->execute([$isAdmin, $targetUserId]);
        
        $stmt = $db->prepare("SELECT id, email, is_admin, created_at FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$updatedUser) {
            jsonResponse(['error' => 'User not found'], 404);
        }
        
        jsonResponse(['user' => $updatedUser]);
    }
    
    jsonResponse(['error' => 'Not found'], 404);
}

function handleInvitations(string $action, PDO $db, array $input, string $method): void {
    if ($action === 'verify' && $method === 'POST') {
        // Public endpoint to verify invitation token
        $token = $input['token'] ?? '';
        $stmt = $db->prepare("SELECT id, email FROM invitations WHERE token = ? AND used = 0");
        $stmt->execute([$token]);
        $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invitation) {
            jsonResponse(['valid' => false]);
        }
        
        jsonResponse(['valid' => true, 'email' => $invitation['email']]);
    }
    
    $user = requireAdmin();
    
    if ($action === '' && $method === 'GET') {
        $stmt = $db->query("SELECT i.*, u.email as created_by_email FROM invitations i LEFT JOIN users u ON i.created_by = u.id ORDER BY i.created_at DESC");
        $invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['invitations' => $invitations]);
    }
    
    if ($action === '' && $method === 'POST') {
        $email = $input['email'] ?? null;
        $token = bin2hex(random_bytes(16));
        
        $stmt = $db->prepare("INSERT INTO invitations (token, email, created_by) VALUES (?, ?, ?)");
        $stmt->execute([$token, $email, $user['id']]);
        
        jsonResponse([
            'invitation' => [
                'id' => $db->lastInsertId(),
                'token' => $token,
                'email' => $email,
                'url' => '?invite=' . $token
            ]
        ], 201);
    }
    
    if (is_numeric($action) && $method === 'DELETE') {
        $stmt = $db->prepare("DELETE FROM invitations WHERE id = ?");
        $stmt->execute([$action]);
        jsonResponse(['success' => true]);
    }
    
    jsonResponse(['error' => 'Not found'], 404);
}

function handleNotifications(string $action, PDO $db, NotificationService $notifications, array $input, string $method): void {
    $user = requireAuth();
    
    // Test ntfy
    if ($action === 'test-ntfy' && $method === 'POST') {
        $result = $notifications->testNtfy();
        jsonResponse($result);
    }
    
    // Test email
    if ($action === 'test-email' && $method === 'POST') {
        $email = $input['email'] ?? $user['email'];
        $result = $notifications->testEmail($email);
        jsonResponse($result);
    }
    
    // Get info
    jsonResponse([
        'topic' => $notifications->getTopic(),
        'subscription_url' => $notifications->getSubscriptionUrl(),
        'instructions' => 'Zasubskrybuj ten URL w aplikacji ntfy, aby otrzymywać powiadomienia push.',
        'smtp_configured' => defined('SMTP_HOST'),
    ]);
}

function handleProfile(PDO $db, array $input, string $method): void {
    $user = requireAuth();
    
    // Get profile
    if ($method === 'GET') {
        $stmt = $db->prepare("SELECT id, email, is_admin, created_at FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        jsonResponse(['user' => $profile]);
    }
    
    // Update profile
    if ($method === 'PUT') {
        $email = $input['email'] ?? null;
        $currentPassword = $input['current_password'] ?? null;
        $newPassword = $input['new_password'] ?? null;
        
        $updates = [];
        $params = [];
        
        // Update email
        if ($email && $email !== $user['email']) {
            // Check if email is taken
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $user['id']]);
            if ($stmt->fetch()) {
                jsonResponse(['error' => 'Email already taken'], 400);
            }
            $updates[] = "email = ?";
            $params[] = $email;
        }
        
        // Update password
        if ($newPassword) {
            if (!$currentPassword) {
                jsonResponse(['error' => 'Current password is required'], 400);
            }
            
            // Verify current password
            $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!password_verify($currentPassword, $userData['password'])) {
                jsonResponse(['error' => 'Current password is incorrect'], 400);
            }
            
            $updates[] = "password = ?";
            $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
        }
        
        if (empty($updates)) {
            jsonResponse(['message' => 'Nothing to update']);
        }
        
        $params[] = $user['id'];
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        // Return updated profile
        $stmt = $db->prepare("SELECT id, email, is_admin, created_at FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        
        jsonResponse(['user' => $profile, 'message' => 'Profile updated']);
    }
    
    jsonResponse(['error' => 'Not found'], 404);
}

function handleJobs(string $action, PDO $db, WhoisService $whois, array $input, string $method): void {
    $user = requireAuth();
    
    // List all jobs
    if ($action === '' && $method === 'GET') {
        $stmt = $db->query("SELECT * FROM jobs ORDER BY created_at DESC LIMIT 50");
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['jobs' => $jobs]);
    }
    
    // Create new job (background import or whois check)
    if ($action === '' && $method === 'POST') {
        $type = $input['type'] ?? '';
        $data = $input['data'] ?? [];
        
        if (!in_array($type, ['import', 'whois_check'])) {
            jsonResponse(['error' => 'Invalid job type. Allowed: import, whois_check'], 400);
        }
        
        if ($type === 'import') {
            $domains = $data['domains'] ?? [];
            if (empty($domains)) {
                jsonResponse(['error' => 'No domains provided'], 400);
            }
            $total = count($domains);
            $jobData = json_encode($domains);
        } else if ($type === 'whois_check') {
            $domainIds = $data['domain_ids'] ?? [];
            $checkAll = $data['check_all'] ?? false;
            
            // If check_all, get all domain IDs for this user
            if ($checkAll) {
                $stmt = $db->prepare("SELECT id FROM domains WHERE added_by = ?");
                $stmt->execute([$user['id']]);
                $domainIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
            }
            
            if (empty($domainIds)) {
                jsonResponse(['error' => 'No domains to check'], 400);
            }
            $total = count($domainIds);
            $jobData = json_encode(['domain_ids' => $domainIds]);
        }
        
        // Create job record
        $stmt = $db->prepare("INSERT INTO jobs (user_id, type, status, total, data) VALUES (?, ?, 'pending', ?, ?)");
        $stmt->execute([$user['id'], $type, $total, $jobData]);
        $jobId = $db->lastInsertId();
        
        jsonResponse(['job' => [
            'id' => (int)$jobId,
            'type' => $type,
            'status' => 'pending',
            'total' => $total,
            'processed' => 0,
            'errors' => 0,
        ]], 201);
    }
    
    // Get job status
    if (is_numeric($action) && $method === 'GET') {
        $stmt = $db->prepare("SELECT * FROM jobs WHERE id = ?");
        $stmt->execute([$action]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) {
            jsonResponse(['error' => 'Job not found'], 404);
        }
        
        jsonResponse(['job' => $job]);
    }
    
    // Process a batch — claim BIG batch, do ALL RDAP in memory, flush to DB once.
    // 10 workers × 2000 batch × 200 concurrent RDAP = fast with few DB connections.
    if ($action === 'process' && $method === 'POST') {
        $jobId = $input['job_id'] ?? 0;
        $batchSize = min(5000, max(1, (int)($input['batch_size'] ?? 2000)));
        
        // === STEP 1: Atomic claim ===
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND status IN ('pending', 'processing') AND processed < total FOR UPDATE");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) {
            $db->commit();
            $s2 = $db->prepare("SELECT * FROM jobs WHERE id = ?");
            $s2->execute([$jobId]);
            $j2 = $s2->fetch(PDO::FETCH_ASSOC);
            jsonResponse(['job' => $j2 ?: [], 'message' => 'completed']);
        }
        
        $claimStart = (int)$job['processed'];
        $claimEnd = min($claimStart + $batchSize, (int)$job['total']);
        
        $db->prepare("UPDATE jobs SET processed = ?, status = 'processing', updated_at = NOW() WHERE id = ?")
           ->execute([$claimEnd, $jobId]);
        $db->commit(); // release lock fast
        
        // === STEP 2: ALL RDAP in memory — zero DB during lookups ===
        $rdap = new RdapEngine(200); // 200 concurrent RDAP per worker
        $jobDataDecoded = json_decode($job['data'], true);
        $batchErrors = 0;
        $insertBuffer = [];
        $updateBuffer = [];
        
        if ($job['type'] === 'import') {
            $domainList = $jobDataDecoded;
            $batch = array_slice($domainList, $claimStart, $claimEnd - $claimStart);
            
            $cleanNames = [];
            foreach ($batch as $raw) {
                $name = strtolower(trim($raw));
                $name = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $name);
                $name = rtrim($name, '/');
                if (empty($name) || !str_contains($name, '.')) { $batchErrors++; continue; }
                $cleanNames[] = $name;
            }
            
            // Single quick read to filter existing
            $toCheck = $cleanNames;
            if (!empty($cleanNames)) {
                $existing = [];
                foreach (array_chunk($cleanNames, 10000) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $s = $db->prepare("SELECT domain FROM domains WHERE domain IN ($ph)");
                    $s->execute($chunk);
                    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $d) $existing[$d] = true;
                }
                $toCheck = array_values(array_filter($cleanNames, fn($n) => !isset($existing[$n])));
            }
            
            // RDAP lookups — all in memory, zero DB
            if (!empty($toCheck)) {
                $rdapResults = $rdap->lookupBatch($toCheck);
                
                $fallbackCount = 0;
                foreach ($toCheck as $name) {
                    if (($rdapResults[$name] ?? null) === null && $fallbackCount < 20) {
                        try { $rdapResults[$name] = $whois->lookup($name); $fallbackCount++; }
                        catch (Exception $e) { $rdapResults[$name] = ['is_registered' => false, 'expiry_date' => null]; $batchErrors++; }
                    }
                }
                
                // Buffer in memory
                foreach ($toCheck as $name) {
                    $data = $rdapResults[$name] ?? null;
                    if ($data) {
                        $insertBuffer[] = [$name, $data['expiry_date'] ?? null, ($data['is_registered'] ?? false) ? 1 : 0];
                    }
                }
            }
            
            // === STEP 3: Single DB flush ===
            if (!empty($insertBuffer)) {
                $db->beginTransaction();
                $ins = $db->prepare("INSERT IGNORE INTO domains (domain, expiry_date, is_registered, last_checked, added_by) VALUES (?, ?, ?, NOW(), ?)");
                foreach ($insertBuffer as $row) {
                    $ins->execute([$row[0], $row[1], $row[2], $user['id']]);
                }
                $db->commit();
            }
            
        } elseif ($job['type'] === 'whois_check') {
            $domainIds = $jobDataDecoded['domain_ids'] ?? [];
            $batchIds = array_slice($domainIds, $claimStart, $claimEnd - $claimStart);
            
            if (!empty($batchIds)) {
                $rows = [];
                foreach (array_chunk($batchIds, 10000) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $s = $db->prepare("SELECT * FROM domains WHERE id IN ($ph)");
                    $s->execute($chunk);
                    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[$row['domain']] = $row;
                }
                
                if (!empty($rows)) {
                    $names = array_keys($rows);
                    $rdapResults = $rdap->lookupBatch($names);
                    
                    $fallbackCount = 0;
                    foreach ($names as $name) {
                        if (($rdapResults[$name] ?? null) === null && $fallbackCount < 20) {
                            try { $rdapResults[$name] = $whois->lookup($name); $fallbackCount++; } catch (Exception $e) { $batchErrors++; }
                        }
                    }
                    
                    foreach ($rows as $name => $row) {
                        $data = $rdapResults[$name] ?? null;
                        if ($data) {
                            $updateBuffer[] = [$data['expiry_date'] ?? $row['expiry_date'], ($data['is_registered'] ?? false) ? 1 : 0, $row['id']];
                        }
                    }
                    
                    if (!empty($updateBuffer)) {
                        $db->beginTransaction();
                        $upd = $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = NOW() WHERE id = ?");
                        foreach ($updateBuffer as $row) { $upd->execute($row); }
                        $db->commit();
                    }
                }
            }
        }
        
        // === STEP 4: Update errors + check completion ===
        if ($batchErrors > 0) {
            $db->prepare("UPDATE jobs SET errors = errors + ? WHERE id = ?")->execute([$batchErrors, $jobId]);
        }
        
        $finalStmt = $db->prepare("SELECT * FROM jobs WHERE id = ?");
        $finalStmt->execute([$jobId]);
        $job = $finalStmt->fetch(PDO::FETCH_ASSOC);
        if ($job && (int)$job['processed'] >= (int)$job['total'] && $job['status'] !== 'completed') {
            $db->prepare("UPDATE jobs SET status = 'completed' WHERE id = ?")->execute([$jobId]);
            $finalStmt->execute([$jobId]);
            $job = $finalStmt->fetch(PDO::FETCH_ASSOC);
        }
        
        jsonResponse(['job' => $job]);
    }
    
    // Resume a stuck job — resets 'processing' back to 'pending' so it can be re-triggered
    if ($action === 'resume' && $method === 'POST') {
        $jobId = $input['job_id'] ?? 0;
        $stmt = $db->prepare("SELECT * FROM jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) {
            jsonResponse(['error' => 'Job not found'], 404);
        }
        
        if ($job['status'] === 'completed') {
            jsonResponse(['job' => $job, 'message' => 'Job already completed']);
        }
        
        // Reset to pending so the frontend can kick it off again
        $db->prepare("UPDATE jobs SET status = 'pending', updated_at = NOW() WHERE id = ?")->execute([$jobId]);
        
        $stmt = $db->prepare("SELECT * FROM jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        jsonResponse(['job' => $job, 'message' => 'Job reset to pending, will resume from ' . $job['processed'] . '/' . $job['total']]);
    }
    
    // Delete job
    if (is_numeric($action) && $method === 'DELETE') {
        $stmt = $db->prepare("DELETE FROM jobs WHERE id = ?");
        $stmt->execute([$action]);
        jsonResponse(['success' => true]);
    }
    
    jsonResponse(['error' => 'Not found'], 404);
}

function handleAi(string $action, string $subAction, PDO $db, AiService $ai, array $input, string $method): void {
    $user = requireAuth();
    
    // AI Status
    if ($action === 'status' && $method === 'GET') {
        $status = $ai->getStatus();
        // Check if configured model is actually available
        $status['model_ready'] = in_array($status['model'], $status['models_available']);
        jsonResponse($status);
    }
    
    // Analyze domain with AI (async endpoint)
    if ($action === 'analyze' && is_numeric($subAction) && $method === 'POST') {
        set_time_limit(600);
        
        // Check AI availability first
        $status = $ai->getStatus();
        if (!$status['ollama_running']) {
            jsonResponse(['ai_analysis' => null, 'error' => 'Ollama nie jest uruchomiona. Uruchom kontener w zakładce Status.'], 503);
        }
        if (!in_array($status['model'], $status['models_available'])) {
            jsonResponse(['ai_analysis' => null, 'error' => "Model {$status['model']} nie jest pobrany. Pobierz go w zakładce Status."], 503);
        }
        
        $domainId = (int)$subAction;
        $stmt = $db->prepare("SELECT * FROM domains WHERE id = ?");
        $stmt->execute([$domainId]);
        $domain = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$domain) {
            jsonResponse(['error' => 'Domain not found'], 404);
        }
        
        // Get cached details for context
        $cached = $ai->getCachedDomainDetails($domainId);
        $whoisData = $cached['whois_parsed'] ?? [];
        $scrapeData = $cached['scrape_data'] ?? [];
        $googleData = $cached['google_data'] ?? [];
        
        $aiAnalysis = $ai->analyzeDomain($domain['domain'], $whoisData, $scrapeData, $googleData);
        
        // Update cache with AI analysis
        if ($aiAnalysis && $cached) {
            $cached['ai_analysis'] = $aiAnalysis;
            $ai->cacheDomainDetails($domainId, $cached);
        }
        
        jsonResponse([
            'ai_analysis' => $aiAnalysis,
            'error' => $aiAnalysis === null ? 'AI nie odpowiedziała. Sprawdź czy model jest pobrany.' : null,
        ]);
    }
    
    // Test AI connection — actually sends a simple prompt
    if ($action === 'test' && $method === 'POST') {
        set_time_limit(300);
        requireAdmin();
        $status = $ai->getStatus();
        
        if (!$status['ollama_running']) {
            jsonResponse(['success' => false, 'error' => 'Ollama nie odpowiada na ' . $status['ollama_url'], 'status' => $status]);
        }
        
        if (!in_array($status['model'], $status['models_available'])) {
            jsonResponse([
                'success' => false, 
                'error' => "Model {$status['model']} nie jest pobrany. Dostępne modele: " . implode(', ', $status['models_available'] ?: ['brak']),
                'status' => $status
            ]);
        }
        
        $response = $ai->chat('Odpowiedz jednym słowem: OK');
        jsonResponse([
            'success' => $response !== null,
            'response' => $response,
            'error' => $response === null ? 'Model nie odpowiedział. Sprawdź logi serwera.' : null,
            'status' => $status
        ]);
    }
    
    // Conversations list
    if ($action === 'conversations' && $subAction === '' && $method === 'GET') {
        $conversations = $ai->getConversations($user['id']);
        jsonResponse(['conversations' => $conversations]);
    }
    
    // Create conversation
    if ($action === 'conversations' && $subAction === '' && $method === 'POST') {
        $title = $input['title'] ?? 'Nowa rozmowa';
        $domain = $input['domain'] ?? null;
        $id = $ai->createConversation($user['id'], $title, $domain);
        
        $conversation = $ai->getConversation($id, $user['id']);
        jsonResponse(['conversation' => $conversation], 201);
    }
    
    // Get conversation with messages
    if ($action === 'conversations' && is_numeric($subAction) && $method === 'GET') {
        $conversation = $ai->getConversation((int)$subAction, $user['id']);
        if (!$conversation) {
            jsonResponse(['error' => 'Conversation not found'], 404);
        }
        jsonResponse(['conversation' => $conversation]);
    }
    
    // Send message to conversation
    if ($action === 'conversations' && is_numeric($subAction) && $method === 'POST') {
        set_time_limit(600);
        $message = $input['message'] ?? '';
        if (empty($message)) {
            jsonResponse(['error' => 'Message is required'], 400);
        }
        
        // Check AI availability first
        $status = $ai->getStatus();
        if (!$status['ollama_running']) {
            jsonResponse(['error' => 'Ollama nie jest uruchomiona. Uruchom kontener w zakładce Status.'], 503);
        }
        
        $response = $ai->continueConversation((int)$subAction, $message, $user['id']);
        if (!$response) {
            jsonResponse(['error' => 'Conversation not found'], 404);
        }
        jsonResponse(['message' => $response]);
    }
    
    // Delete conversation
    if ($action === 'conversations' && is_numeric($subAction) && $method === 'DELETE') {
        $ai->deleteConversation((int)$subAction, $user['id']);
        jsonResponse(['success' => true]);
    }
    
    // Knowledge base - list
    if ($action === 'knowledge' && $subAction === '' && $method === 'GET') {
        $domain = $_GET['domain'] ?? null;
        $knowledge = $ai->getKnowledgeBase($domain);
        jsonResponse(['knowledge' => $knowledge]);
    }
    
    // Knowledge base - add
    if ($action === 'knowledge' && $subAction === '' && $method === 'POST') {
        $content = $input['content'] ?? '';
        $type = $input['type'] ?? 'note';
        $domain = $input['domain'] ?? null;
        $source = $input['source'] ?? null;
        
        if (empty($content)) {
            jsonResponse(['error' => 'Content is required'], 400);
        }
        
        $id = $ai->addKnowledge($content, $type, $domain, $source, $user['id']);
        jsonResponse(['knowledge' => ['id' => $id, 'content' => $content, 'type' => $type, 'domain' => $domain]], 201);
    }
    
    // Knowledge base - delete
    if ($action === 'knowledge' && is_numeric($subAction) && $method === 'DELETE') {
        $ai->deleteKnowledge((int)$subAction);
        jsonResponse(['success' => true]);
    }
    
    // Quick chat (no conversation)
    if ($action === 'chat' && $method === 'POST') {
        set_time_limit(600);
        $message = $input['message'] ?? '';
        if (empty($message)) {
            jsonResponse(['error' => 'Message is required'], 400);
        }
        
        // Check AI availability first
        $status = $ai->getStatus();
        if (!$status['ollama_running']) {
            jsonResponse(['error' => 'Ollama nie jest uruchomiona. Uruchom kontener w zakładce Status.'], 503);
        }
        if (!in_array($status['model'], $status['models_available'])) {
            jsonResponse(['error' => "Model {$status['model']} nie jest pobrany. Pobierz go w zakładce Status."], 503);
        }
        
        $response = $ai->chat($message);
        if ($response === null) {
            jsonResponse(['error' => 'AI nie odpowiedziała w czasie. Model może być przeciążony — spróbuj ponownie.'], 504);
        }
        jsonResponse(['response' => $response]);
    }
    
    // ====== AI Environment Management via Docker (admin only) ======
    
    // Install/Start Ollama Docker container
    if ($action === 'install' && $method === 'POST') {
        requireAdmin();
        $output = [];
        $returnCode = 0;
        
        // Check if container already exists
        exec('docker ps -a --filter "name=ollama" --format "{{.Names}}" 2>&1', $checkOutput, $checkCode);
        $containerExists = !empty($checkOutput) && in_array('ollama', $checkOutput);
        
        if ($containerExists) {
            // Check if running
            exec('docker ps --filter "name=ollama" --filter "status=running" --format "{{.Names}}" 2>&1', $runningOutput);
            if (!empty($runningOutput)) {
                jsonResponse(['success' => true, 'message' => 'Kontener Ollama już działa', 'output' => 'Container ollama is already running', 'already_installed' => true]);
            }
            // Start existing container
            exec('docker start ollama 2>&1', $output, $returnCode);
            sleep(3);
            $status = $ai->getStatus();
            jsonResponse([
                'success' => $returnCode === 0,
                'message' => $returnCode === 0 ? 'Kontener Ollama uruchomiony' : 'Błąd uruchamiania kontenera',
                'output' => implode("\n", $output),
                'status' => $status
            ]);
        }
        
        // Create and run new container
        $gpuFlag = '';
        exec('docker info 2>/dev/null | grep -i "nvidia\|gpu"', $gpuCheck);
        if (!empty($gpuCheck)) {
            $gpuFlag = '--gpus all';
        }
        
        $cmd = "docker run -d {$gpuFlag} -v ollama_data:/root/.ollama -p 11434:11434 --name ollama --restart unless-stopped ollama/ollama 2>&1";
        exec($cmd, $output, $returnCode);
        
        if ($returnCode === 0) {
            sleep(3);
            $status = $ai->getStatus();
            jsonResponse(['success' => true, 'message' => 'Kontener Ollama utworzony i uruchomiony', 'output' => implode("\n", $output), 'status' => $status]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Błąd tworzenia kontenera Ollama', 'output' => implode("\n", $output)], 500);
        }
    }
    
    // Pull model (inside Docker container)
    if ($action === 'pull-model' && $method === 'POST') {
        set_time_limit(600);
        requireAdmin();
        $model = $input['model'] ?? OLLAMA_MODEL;
        $output = [];
        $returnCode = 0;
        
        // Check if container is running
        exec('docker ps --filter "name=ollama" --filter "status=running" --format "{{.Names}}" 2>&1', $runCheck);
        if (empty($runCheck)) {
            jsonResponse(['success' => false, 'message' => 'Kontener Ollama nie jest uruchomiony. Najpierw zainstaluj/uruchom Ollama.', 'output' => ''], 400);
        }
        
        $model = escapeshellarg($model);
        exec("docker exec ollama ollama pull {$model} 2>&1", $output, $returnCode);
        
        if ($returnCode === 0) {
            jsonResponse(['success' => true, 'message' => "Model {$model} pobrany pomyślnie", 'output' => implode("\n", $output)]);
        } else {
            jsonResponse(['success' => false, 'message' => "Błąd pobierania modelu {$model}", 'output' => implode("\n", $output)], 500);
        }
    }
    
    // Restart Ollama container
    if ($action === 'restart' && $method === 'POST') {
        requireAdmin();
        $output = [];
        $returnCode = 0;
        
        exec('docker restart ollama 2>&1', $output, $returnCode);
        
        // Wait for Ollama to start
        sleep(3);
        
        // Check if running
        $status = $ai->getStatus();
        
        jsonResponse([
            'success' => $status['ollama_running'],
            'message' => $status['ollama_running'] ? 'Kontener Ollama zrestartowany pomyślnie' : 'Ollama nie odpowiada po restarcie',
            'output' => implode("\n", $output),
            'status' => $status
        ]);
    }
    
    // Stop Ollama container
    if ($action === 'stop' && $method === 'POST') {
        requireAdmin();
        $output = [];
        $returnCode = 0;
        exec('docker stop ollama 2>&1', $output, $returnCode);
        jsonResponse(['success' => $returnCode === 0, 'message' => $returnCode === 0 ? 'Kontener Ollama zatrzymany' : 'Błąd zatrzymywania kontenera', 'output' => implode("\n", $output)]);
    }
    
    // Remove Ollama container (for clean reinstall)
    if ($action === 'remove' && $method === 'POST') {
        requireAdmin();
        $output = [];
        exec('docker stop ollama 2>/dev/null; docker rm ollama 2>&1', $output, $returnCode);
        jsonResponse(['success' => true, 'message' => 'Kontener Ollama usunięty', 'output' => implode("\n", $output)]);
    }
    
    // Docker status info
    if ($action === 'docker-status' && $method === 'GET') {
        requireAdmin();
        $dockerInfo = [];
        
        // Check Docker availability
        exec('docker --version 2>&1', $dockerVersion, $dockerCode);
        $dockerInfo['docker_available'] = $dockerCode === 0;
        $dockerInfo['docker_version'] = $dockerCode === 0 ? implode('', $dockerVersion) : null;
        
        // Check Ollama container
        exec('docker ps -a --filter "name=ollama" --format "{{.ID}}|{{.Status}}|{{.Image}}|{{.Ports}}" 2>&1', $containerInfo);
        if (!empty($containerInfo)) {
            $parts = explode('|', $containerInfo[0]);
            $dockerInfo['container'] = [
                'id' => $parts[0] ?? '',
                'status' => $parts[1] ?? '',
                'image' => $parts[2] ?? '',
                'ports' => $parts[3] ?? '',
            ];
        } else {
            $dockerInfo['container'] = null;
        }
        
        // Check volume
        exec('docker volume inspect ollama_data 2>/dev/null | grep -o "Mountpoint.*" | head -1', $volInfo);
        $dockerInfo['volume_exists'] = !empty($volInfo);
        
        jsonResponse($dockerInfo);
    }
    
    jsonResponse(['error' => 'Not found'], 404);
}

// ── Migration endpoint — visit /api/migrate in browser ──
function handleMigrate(PDO $mysql): void {
    // Disable the global error/exception handlers for this HTML page
    restore_error_handler();
    restore_exception_handler();
    
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
    
    echo "<pre style='font-family:monospace;font-size:14px;padding:20px;'>";
    $nl = "<br>\n";
    
    echo "=== DomainAlert MySQL Status ===$nl$nl";
    
    // Debug: show which DB driver is actually connected
    $driver = $mysql->getAttribute(PDO::ATTR_DRIVER_NAME);
    $serverInfo = '';
    try { $serverInfo = $mysql->getAttribute(PDO::ATTR_SERVER_VERSION); } catch (Exception $e) {}
    echo "DB Driver: $driver | Version: $serverInfo$nl";
    
    if ($driver !== 'mysql') {
        echo "{$nl}⚠ ERROR: Still connected to $driver instead of MySQL!$nl";
        echo "Your config.php on the server still has the old SQLite connection.$nl";
        echo "The deployed config.php needs to be updated with MySQL settings.$nl$nl";
        echo "Expected DSN: mysql:host=localhost;dbname=domainalert;charset=utf8mb4$nl";
        echo "Check: DB_HOST, DB_NAME, DB_USER, DB_PASS constants in config.php$nl";
        echo "</pre>";
        exit;
    }
    flush();
    
    // Show MySQL tables
    $tables = ['users', 'domains', 'notifications', 'invitations', 'jobs'];
    foreach ($tables as $table) {
        try {
            $result = $mysql->query("SHOW TABLES LIKE '$table'");
            $exists = (bool)$result->fetch();
            $result->closeCursor();
            
            if ($exists) {
                $countStmt = $mysql->query("SELECT COUNT(*) FROM `$table`");
                $count = (int)$countStmt->fetchColumn();
                $countStmt->closeCursor();
                echo "✓ Table '$table' — $count rows$nl";
            } else {
                echo "✗ Table '$table' — MISSING$nl";
            }
        } catch (Exception $e) {
            echo "✗ Table '$table' — ERROR: " . htmlspecialchars($e->getMessage()) . $nl;
        }
        flush();
    }
    
    echo "{$nl}── SQLite Migration ──$nl";
    
    // Find SQLite file
    $paths = [
        __DIR__ . '/../database.sqlite',
        __DIR__ . '/../../database.sqlite',
    ];
    if (isset($_SERVER['DOCUMENT_ROOT'])) {
        $paths[] = $_SERVER['DOCUMENT_ROOT'] . '/database.sqlite';
    }
    
    $sqlitePath = null;
    foreach ($paths as $p) {
        if (file_exists($p)) {
            $sqlitePath = realpath($p);
            break;
        }
    }
    
    if (!$sqlitePath) {
        echo "No SQLite database found — nothing to migrate.$nl";
        echo "Checked:$nl";
        foreach ($paths as $p) echo "  $p$nl";
        echo "{$nl}✓ MySQL is ready to use!$nl";
        echo "</pre>";
        exit;
    }
    
    echo "Found SQLite: $sqlitePath$nl";
    
    if (!extension_loaded('pdo_sqlite')) {
        echo "✗ pdo_sqlite extension not available — cannot read SQLite.$nl";
        echo "  Enable it in Plesk PHP settings or import via phpMyAdmin.$nl";
        echo "</pre>";
        exit;
    }
    
    $sqlite = new PDO('sqlite:' . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $tableConfigs = [
        'users' => 'id, email, password, is_admin, created_at',
        'domains' => 'id, domain, expiry_date, is_registered, last_checked, added_by',
        'notifications' => 'id, domain_id, type, message, is_read, created_at',
        'invitations' => 'id, email, token, invited_by, used, created_at',
        'jobs' => 'id, user_id, type, status, total, processed, errors, data, result, created_at, updated_at',
    ];
    
    $totalMigrated = 0;
    foreach ($tableConfigs as $table => $cols) {
        $check = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
        if (!$check->fetch()) {
            echo "⏭ '$table' not in SQLite$nl";
            continue;
        }
        
        $colList = explode(', ', $cols);
        $placeholders = implode(',', array_fill(0, count($colList), '?'));
        $insertSql = "INSERT IGNORE INTO $table ($cols) VALUES ($placeholders)";
        
        $rows = $sqlite->query("SELECT $cols FROM $table ORDER BY id")->fetchAll(PDO::FETCH_NUM);
        $count = count($rows);
        $migrated = 0;
        
        $mysql->beginTransaction();
        $stmt = $mysql->prepare($insertSql);
        foreach ($rows as $row) {
            $stmt->execute($row);
            if ($stmt->rowCount() > 0) $migrated++;
        }
        $mysql->commit();
        
        // Fix auto-increment
        $maxId = (int)$mysql->query("SELECT COALESCE(MAX(id),0) FROM $table")->fetchColumn();
        if ($maxId > 0) $mysql->exec("ALTER TABLE $table AUTO_INCREMENT = " . ($maxId + 1));
        
        echo "✓ '$table': $migrated migrated / $count total$nl";
        $totalMigrated += $migrated;
    }
    
    echo "{$nl}=== Done! $totalMigrated rows migrated ===$nl";
    echo "</pre>";
    exit;
}

// ── Fix config.php on server — visit /api/fix-config ──
function handleFixConfig(): void {
    restore_error_handler();
    restore_exception_handler();
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre style='font-family:monospace;font-size:14px;padding:20px;'>";

    // Find config.php — could be in parent dir (backend/) or same dir (flattened deploy)
    $configPath = realpath(__DIR__ . '/../config.php');
    if (!$configPath) {
        // Flattened deploy: config.php is at document root alongside api/
        $configPath = realpath(__DIR__ . '/../../config.php');
    }
    if (!$configPath && isset($_SERVER['DOCUMENT_ROOT'])) {
        $configPath = $_SERVER['DOCUMENT_ROOT'] . '/config.php';
    }
    
    echo "Config path: $configPath\n\n";
    
    if (file_exists($configPath)) {
        $backup = $configPath . '.bak.' . date('Ymd_His');
        copy($configPath, $backup);
        echo "✓ Backed up old config to: $backup\n";
    }

    $newConfig = file_get_contents(__DIR__ . '/config-mysql.php.txt');
    if (!$newConfig) {
        // Inline the config if the template file doesn't exist
        $newConfig = <<<'PHPCONFIG'
<?php
// Database configuration — MySQL
define('DB_HOST', 'localhost');
define('DB_NAME', 'domainalert');
define('DB_USER', 'domainalert');
define('DB_PASS', 'omainalert');
define('DB_CHARSET', 'utf8mb4');

// Legacy SQLite path (used only by migration script)
define('DB_PATH', __DIR__ . '/database.sqlite');

define('NTFY_SERVER', 'https://ntfy.sh');
define('NTFY_TOPIC', 'domainalert-demo');

define('JWT_SECRET', 'ZMIEN-TO-NA-BARDZO-DLUGI-LOSOWY-CIAG-ZNAKOW');
define('JWT_EXPIRY', 86400 * 7);

define('OLLAMA_URL', 'http://localhost:11434');
define('OLLAMA_MODEL', 'deepseek-r1:1.5b');

if (!defined('SKIP_HEADERS')) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
}

function initDatabase(): PDO {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $db = new PDO($dsn, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("SET SESSION innodb_lock_wait_timeout = 30");
    $db->exec("SET SESSION wait_timeout = 600");

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INT PRIMARY KEY AUTO_INCREMENT, email VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL, is_admin TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS invitations (
        id INT PRIMARY KEY AUTO_INCREMENT, token VARCHAR(255) UNIQUE NOT NULL,
        email VARCHAR(255), created_by INT, used TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS domains (
        id INT PRIMARY KEY AUTO_INCREMENT, domain VARCHAR(255) UNIQUE NOT NULL,
        expiry_date DATE, is_registered TINYINT(1) DEFAULT 1, last_checked DATETIME,
        added_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (added_by) REFERENCES users(id),
        INDEX idx_expiry (expiry_date), INDEX idx_registered (is_registered),
        INDEX idx_last_checked (last_checked)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT PRIMARY KEY AUTO_INCREMENT, domain_id INT, type VARCHAR(50),
        message TEXT, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (domain_id) REFERENCES domains(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS jobs (
        id INT PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL,
        type VARCHAR(50) NOT NULL, status VARCHAR(20) DEFAULT 'pending',
        total INT DEFAULT 0, processed INT DEFAULT 0, errors INT DEFAULT 0,
        data LONGTEXT, result LONGTEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id), INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE is_admin = 1");
    if ($stmt->fetchColumn() == 0) {
        $h = password_hash('admin123', PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users (email, password, is_admin) VALUES (?, ?, 1)")->execute(['admin@example.com', $h]);
    }
    return $db;
}

function createJWT(array $payload): string {
    $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload['exp'] = time() + JWT_EXPIRY;
    $payload = base64_encode(json_encode($payload));
    $signature = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$signature";
}

function verifyJWT(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $payload, $signature] = $parts;
    $expected = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if ($signature !== $expected) return null;
    $data = json_decode(base64_decode($payload), true);
    if ($data['exp'] < time()) return null;
    return $data;
}

function getAuthUser(): ?array {
    $authHeader = '';
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (empty($authHeader) && isset($_SERVER['HTTP_AUTHORIZATION'])) $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    if (empty($authHeader) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    if (empty($authHeader)) {
        $rh = [];
        foreach ($_SERVER as $k => $v) {
            if (substr($k, 0, 5) === 'HTTP_') {
                $h = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($k, 5)))));
                $rh[$h] = $v;
            }
        }
        $authHeader = $rh['Authorization'] ?? '';
    }
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) return verifyJWT($m[1]);
    return null;
}

function requireAuth(): array {
    $u = getAuthUser();
    if (!$u) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit(); }
    return $u;
}

function requireAdmin(): array {
    $u = requireAuth();
    if (!$u['is_admin']) { http_response_code(403); echo json_encode(['error' => 'Admin access required']); exit(); }
    return $u;
}

function jsonResponse(mixed $data, int $code = 200): void {
    http_response_code($code); echo json_encode($data); exit();
}
PHPCONFIG;
    }

    $result = file_put_contents($configPath, $newConfig);
    if ($result === false) {
        echo "\n✗ FAILED to write config.php — permission denied?\n";
        echo "  File: $configPath\n";
        echo "  Owner: " . posix_getpwuid(fileowner($configPath))['name'] . "\n";
        echo "  Perms: " . substr(sprintf('%o', fileperms($configPath)), -4) . "\n";
    } else {
        echo "✓ config.php overwritten with MySQL version ($result bytes)\n\n";
        echo "Testing MySQL connection...\n";
        try {
            $dsn = "mysql:host=localhost;dbname=domainalert;charset=utf8mb4";
            $testDb = new PDO($dsn, 'domainalert', 'omainalert');
            $testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $driver = $testDb->getAttribute(PDO::ATTR_DRIVER_NAME);
            echo "✓ MySQL connected! Driver: $driver\n";
            echo "\n=== SUCCESS ===\n";
            echo "Now visit /api/migrate to migrate your SQLite data.\n";
        } catch (Exception $e) {
            echo "✗ MySQL connection failed: " . $e->getMessage() . "\n";
        }
    }
    echo "</pre>";
    exit;
}

// ── Force re-deploy backend files from git repo — visit /api/deploy-sync ──
function handleDeploySync(): void {
    restore_error_handler();
    restore_exception_handler();
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre style='font-family:monospace;font-size:14px;padding:20px;'>";
    $nl = "<br>\n";
    
    echo "=== Deploy Sync ===$nl$nl";
    
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__);
    $backendDir = $docRoot . '/backend';
    
    echo "Document root: $docRoot$nl";
    echo "Backend dir: $backendDir$nl$nl";
    
    if (!is_dir($backendDir)) {
        echo "✗ backend/ directory not found$nl";
        echo "</pre>";
        exit;
    }
    
    echo "── Copying backend/* to document root ──$nl";
    
    $filesToCopy = ['config.php','router.php','worker.php','migrate.php','migrate-to-mysql.php','fix-config.php','.htaccess'];
    $dirsToCopy = ['api','services','cron','public'];
    
    foreach ($filesToCopy as $file) {
        $src = "$backendDir/$file";
        $dst = "$docRoot/$file";
        if (file_exists($src)) {
            $r = copy($src, $dst);
            echo ($r ? '✓' : '✗') . " $file$nl";
        } else {
            echo "⏭ $file$nl";
        }
    }
    
    foreach ($dirsToCopy as $dir) {
        $srcDir = "$backendDir/$dir";
        if (!is_dir($srcDir)) { echo "⏭ $dir/$nl"; continue; }
        $count = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = "$docRoot/$dir/" . $it->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($target)) mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
                $count++;
            }
        }
        echo "✓ $dir/ ($count files)$nl";
    }
    
    echo "{$nl}=== Sync complete ===$nl";
    echo "All files updated. Visit /api/migrate to verify MySQL.$nl";
    echo "</pre>";
    exit;
}
