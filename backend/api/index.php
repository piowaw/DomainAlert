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

// Auto-migrate: ensure jobs table exists (for servers with old config.php)
try {
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='jobs'");
    if (!$result->fetch()) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                status TEXT DEFAULT 'pending',
                total INTEGER DEFAULT 0,
                processed INTEGER DEFAULT 0,
                errors INTEGER DEFAULT 0,
                data TEXT,
                result TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
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
                $where[] = "d.is_registered = 1 AND d.expiry_date IS NOT NULL AND d.expiry_date <= date('now', '+30 days')";
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
        $stmt = $db->prepare("INSERT OR IGNORE INTO domains (domain, expiry_date, is_registered, last_checked, added_by) VALUES (?, ?, ?, datetime('now'), ?)");
        $stmt->execute([
            $domain,
            $whoisData['expiry_date'],
            $whoisData['is_registered'] ? 1 : 0,
            $user['id']
        ]);
        
        if ($db->lastInsertId() == 0) {
            // Domain already exists, update it
            $stmt = $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = datetime('now') WHERE domain = ?");
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
            
            $stmt = $db->prepare("INSERT OR IGNORE INTO domains (domain, expiry_date, is_registered, last_checked, added_by) VALUES (?, ?, ?, datetime('now'), ?)");
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
                'added' => $db->lastInsertId() > 0
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
        
        $stmt = $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = datetime('now') WHERE id = ?");
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
            SUM(CASE WHEN is_registered = 1 AND expiry_date IS NOT NULL AND expiry_date <= date('now', '+30 days') THEN 1 ELSE 0 END) as expiring
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
        $stmt = $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = datetime('now') WHERE id = ?");
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
    
    // Process a batch of a pending job — each call handles batch_size domains
    // with parallel RDAP. Frontend fires many of these in parallel.
    // Strategy: minimize DB lock time — do all RDAP first, write once at the end.
    if ($action === 'process' && $method === 'POST') {
        $jobId = $input['job_id'] ?? 0;
        $batchSize = min(100, max(1, (int)($input['batch_size'] ?? 50)));
        
        // === STEP 1: Claim batch with BEGIN IMMEDIATE (serializes writers) ===
        $claimStart = null;
        $claimEnd = null;
        $jobData = null;
        
        for ($attempt = 0; $attempt < 15; $attempt++) {
            try {
                $db->exec("BEGIN IMMEDIATE"); // grabs write lock immediately
                $stmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND status IN ('pending', 'processing') AND processed < total");
                $stmt->execute([$jobId]);
                $job = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$job) {
                    $db->exec("COMMIT");
                    // Check if completed
                    $s2 = $db->prepare("SELECT * FROM jobs WHERE id = ?");
                    $s2->execute([$jobId]);
                    $j2 = $s2->fetch(PDO::FETCH_ASSOC);
                    jsonResponse(['job' => $j2 ?: [], 'message' => 'completed']);
                }
                
                $claimStart = (int)$job['processed'];
                $claimEnd = min($claimStart + $batchSize, (int)$job['total']);
                
                $db->prepare("UPDATE jobs SET processed = ?, status = 'processing', updated_at = datetime('now') WHERE id = ?")
                   ->execute([$claimEnd, $jobId]);
                $db->exec("COMMIT"); // release lock ASAP
                
                $jobData = $job;
                break;
            } catch (Exception $e) {
                try { $db->exec("ROLLBACK"); } catch (Exception $ignore) {}
                if ($attempt < 14 && str_contains($e->getMessage(), 'locked')) {
                    usleep(rand(30000, 100000) * ($attempt + 1)); // jittered backoff
                } else {
                    jsonResponse(['error' => 'DB claim failed: ' . $e->getMessage()], 500);
                }
            }
        }
        
        if (!$jobData) {
            jsonResponse(['error' => 'Could not claim batch after retries'], 500);
        }
        
        // === STEP 2: RDAP lookups — NO DB, pure network, can take as long as needed ===
        $rdap = new RdapEngine(30); // 30 concurrent RDAP per worker
        $jobDataDecoded = json_decode($jobData['data'], true);
        $batchErrors = 0;
        
        if ($jobData['type'] === 'import') {
            $domainList = $jobDataDecoded;
            $batch = array_slice($domainList, $claimStart, $claimEnd - $claimStart);
            
            // Clean domain names
            $cleanNames = [];
            foreach ($batch as $raw) {
                $name = strtolower(trim($raw));
                $name = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $name);
                $name = rtrim($name, '/');
                if (empty($name) || !str_contains($name, '.')) {
                    $batchErrors++;
                    continue;
                }
                $cleanNames[] = $name;
            }
            
            // Check which already exist (read-only — no lock needed)
            $toCheck = $cleanNames;
            if (!empty($cleanNames)) {
                try {
                    $existing = [];
                    $ph = implode(',', array_fill(0, count($cleanNames), '?'));
                    $s = $db->prepare("SELECT domain FROM domains WHERE domain IN ($ph)");
                    $s->execute($cleanNames);
                    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $d) $existing[$d] = true;
                    $toCheck = array_values(array_filter($cleanNames, fn($n) => !isset($existing[$n])));
                } catch (Exception $e) {
                    // If even reads fail, just check all — duplicates will be INSERT OR IGNORE'd
                }
            }
            
            // Parallel RDAP (pure network, no DB)
            if (!empty($toCheck)) {
                $rdapResults = $rdap->lookupBatch($toCheck);
                
                // Fallback for misses
                foreach ($toCheck as $name) {
                    if (($rdapResults[$name] ?? null) === null) {
                        try {
                            $rdapResults[$name] = $whois->lookup($name);
                        } catch (Exception $e) {
                            $rdapResults[$name] = ['is_registered' => false, 'expiry_date' => null];
                            $batchErrors++;
                        }
                    }
                }
                
                // === STEP 3: Single fast DB write with BEGIN IMMEDIATE ===
                for ($attempt = 0; $attempt < 15; $attempt++) {
                    try {
                        $db->exec("BEGIN IMMEDIATE");
                        $ins = $db->prepare("INSERT OR IGNORE INTO domains (domain, expiry_date, is_registered, last_checked, added_by) VALUES (?, ?, ?, datetime('now'), ?)");
                        foreach ($toCheck as $name) {
                            $data = $rdapResults[$name] ?? null;
                            if ($data) {
                                $ins->execute([$name, $data['expiry_date'] ?? null, ($data['is_registered'] ?? false) ? 1 : 0, $user['id']]);
                            }
                        }
                        $db->exec("COMMIT");
                        break;
                    } catch (Exception $e) {
                        try { $db->exec("ROLLBACK"); } catch (Exception $ignore) {}
                        if ($attempt < 14 && str_contains($e->getMessage(), 'locked')) {
                            usleep(rand(50000, 150000) * ($attempt + 1));
                        } else {
                            $batchErrors += count($toCheck);
                            break;
                        }
                    }
                }
            }
            
        } elseif ($jobData['type'] === 'whois_check') {
            $domainIds = $jobDataDecoded['domain_ids'] ?? [];
            $batchIds = array_slice($domainIds, $claimStart, $claimEnd - $claimStart);
            
            if (!empty($batchIds)) {
                $ph = implode(',', array_fill(0, count($batchIds), '?'));
                $s = $db->prepare("SELECT * FROM domains WHERE id IN ($ph)");
                $s->execute($batchIds);
                $rows = [];
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[$row['domain']] = $row;
                
                if (!empty($rows)) {
                    $names = array_keys($rows);
                    $rdapResults = $rdap->lookupBatch($names);
                    
                    foreach ($names as $name) {
                        if (($rdapResults[$name] ?? null) === null) {
                            try { $rdapResults[$name] = $whois->lookup($name); } catch (Exception $e) { $batchErrors++; }
                        }
                    }
                    
                    for ($attempt = 0; $attempt < 15; $attempt++) {
                        try {
                            $db->exec("BEGIN IMMEDIATE");
                            $upd = $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = datetime('now') WHERE id = ?");
                            foreach ($rows as $name => $row) {
                                $data = $rdapResults[$name] ?? null;
                                if ($data) {
                                    $upd->execute([$data['expiry_date'] ?? $row['expiry_date'], ($data['is_registered'] ?? false) ? 1 : 0, $row['id']]);
                                }
                            }
                            $db->exec("COMMIT");
                            break;
                        } catch (Exception $e) {
                            try { $db->exec("ROLLBACK"); } catch (Exception $ignore) {}
                            if ($attempt < 14 && str_contains($e->getMessage(), 'locked')) {
                                usleep(rand(50000, 150000) * ($attempt + 1));
                            } else {
                                $batchErrors += count($rows);
                                break;
                            }
                        }
                    }
                }
            }
        }
        
        // === STEP 4: Update error count + check completion ===
        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                $db->exec("BEGIN IMMEDIATE");
                if ($batchErrors > 0) {
                    $db->prepare("UPDATE jobs SET errors = errors + ?, updated_at = datetime('now') WHERE id = ?")->execute([$batchErrors, $jobId]);
                }
                $finalStmt = $db->prepare("SELECT * FROM jobs WHERE id = ?");
                $finalStmt->execute([$jobId]);
                $job = $finalStmt->fetch(PDO::FETCH_ASSOC);
                if ($job && (int)$job['processed'] >= (int)$job['total'] && $job['status'] !== 'completed') {
                    $db->prepare("UPDATE jobs SET status = 'completed', updated_at = datetime('now') WHERE id = ?")->execute([$jobId]);
                    $finalStmt->execute([$jobId]);
                    $job = $finalStmt->fetch(PDO::FETCH_ASSOC);
                }
                $db->exec("COMMIT");
                break;
            } catch (Exception $e) {
                try { $db->exec("ROLLBACK"); } catch (Exception $ignore) {}
                if ($attempt < 9 && str_contains($e->getMessage(), 'locked')) {
                    usleep(rand(30000, 80000) * ($attempt + 1));
                } else {
                    // Last resort: just read without transaction
                    try {
                        $finalStmt = $db->prepare("SELECT * FROM jobs WHERE id = ?");
                        $finalStmt->execute([$jobId]);
                        $job = $finalStmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Exception $ignore) {}
                    break;
                }
            }
        }
        
        jsonResponse(['job' => $job ?? ['id' => $jobId, 'status' => 'processing']]);
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
        $db->prepare("UPDATE jobs SET status = 'pending', updated_at = datetime('now') WHERE id = ?")->execute([$jobId]);
        
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
