<?php

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
    
    // Get full domain details (WHOIS + scrape + Google + DNS + AI)
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
        
        // Gather all data
        $whoisData = $whois->lookup($domain['domain']);
        $scrapeData = $scraper->scrapeWebsite($domain['domain']);
        $googleData = $scraper->searchGoogle($domain['domain']);
        $dnsRecords = $scraper->getDnsRecords($domain['domain']);
        
        // AI Analysis (if available)
        $aiAnalysis = $ai->analyzeDomain($domain['domain'], $whoisData, $scrapeData, $googleData);
        
        // Cache the results
        $details = [
            'whois_raw' => $whoisData['raw'] ?? '',
            'whois_parsed' => $whoisData,
            'scrape_data' => $scrapeData,
            'google_data' => $googleData,
            'dns_records' => $dnsRecords,
            'ai_analysis' => $aiAnalysis,
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
                'ai_analysis' => $aiAnalysis,
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
    
    // Process a batch of a pending job — parallel RDAP via RdapEngine
    if ($action === 'process' && $method === 'POST') {
        $jobId = $input['job_id'] ?? 0;
        $batchSize = min(2000, max(1, $input['batch_size'] ?? 500));
        
        $stmt = $db->prepare("SELECT * FROM jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) {
            jsonResponse(['error' => 'Job not found'], 404);
        }
        
        if ($job['status'] === 'completed') {
            jsonResponse(['job' => $job, 'message' => 'Job already completed']);
        }
        
        // Mark as processing
        $db->prepare("UPDATE jobs SET status = 'processing', updated_at = datetime('now') WHERE id = ?")->execute([$jobId]);
        
        $rdap = new RdapEngine(200); // 200 concurrent RDAP requests
        $jobDataDecoded = json_decode($job['data'], true);
        $processed = (int)$job['processed'];
        $errors = (int)$job['errors'];
        $total = (int)$job['total'];
        
        if ($job['type'] === 'import') {
            $domainList = $jobDataDecoded;
            $batch = array_slice($domainList, $processed, $batchSize);
            
            // 1. Clean all domain names
            $cleanNames = [];
            foreach ($batch as $raw) {
                $name = strtolower(trim($raw));
                $name = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $name);
                $name = rtrim($name, '/');
                if (empty($name) || !str_contains($name, '.')) {
                    $errors++;
                    $processed++;
                    continue;
                }
                $cleanNames[] = $name;
            }
            
            // 2. Filter already-existing domains
            $toCheck = $cleanNames;
            if (!empty($cleanNames)) {
                $existing = [];
                foreach (array_chunk($cleanNames, 900) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $s = $db->prepare("SELECT domain FROM domains WHERE domain IN ($ph)");
                    $s->execute($chunk);
                    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $d) $existing[$d] = true;
                }
                $toCheck = [];
                foreach ($cleanNames as $n) {
                    if (isset($existing[$n])) { $processed++; }
                    else { $toCheck[] = $n; }
                }
            }
            
            // 3. Parallel RDAP lookup (200 concurrent via rolling window)
            if (!empty($toCheck)) {
                $rdapResults = $rdap->lookupBatch($toCheck);
                
                // 4. Fallback for RDAP misses
                foreach ($toCheck as $name) {
                    if (($rdapResults[$name] ?? null) === null) {
                        try {
                            $rdapResults[$name] = $whois->lookup($name);
                        } catch (Exception $e) {
                            $rdapResults[$name] = ['is_registered' => false, 'expiry_date' => null];
                            $errors++;
                        }
                    }
                }
                
                // 5. Batch INSERT in transaction
                $db->beginTransaction();
                $ins = $db->prepare("INSERT OR IGNORE INTO domains (domain, expiry_date, is_registered, last_checked, added_by) VALUES (?, ?, ?, datetime('now'), ?)");
                foreach ($toCheck as $name) {
                    $data = $rdapResults[$name] ?? null;
                    if ($data) {
                        $ins->execute([$name, $data['expiry_date'] ?? null, ($data['is_registered'] ?? false) ? 1 : 0, $user['id']]);
                    }
                    $processed++;
                }
                $db->commit();
            }
            
        } elseif ($job['type'] === 'whois_check') {
            $domainIds = $jobDataDecoded['domain_ids'] ?? [];
            $batchIds = array_slice($domainIds, $processed, $batchSize);
            
            // 1. Fetch domain records in bulk
            $rows = [];
            foreach (array_chunk($batchIds, 900) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $s = $db->prepare("SELECT * FROM domains WHERE id IN ($ph)");
                $s->execute($chunk);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[$row['domain']] = $row;
            }
            
            $missing = count($batchIds) - count($rows);
            $errors += $missing;
            $processed += $missing;
            
            if (!empty($rows)) {
                $names = array_keys($rows);
                
                // 2. Parallel RDAP
                $rdapResults = $rdap->lookupBatch($names);
                
                // 3. Fallback for misses
                foreach ($names as $name) {
                    if (($rdapResults[$name] ?? null) === null) {
                        try { $rdapResults[$name] = $whois->lookup($name); } catch (Exception $e) { $errors++; }
                    }
                }
                
                // 4. Batch UPDATE in transaction
                $db->beginTransaction();
                $upd = $db->prepare("UPDATE domains SET expiry_date = ?, is_registered = ?, last_checked = datetime('now') WHERE id = ?");
                foreach ($rows as $name => $row) {
                    $data = $rdapResults[$name] ?? null;
                    if ($data) {
                        $upd->execute([$data['expiry_date'] ?? $row['expiry_date'], ($data['is_registered'] ?? false) ? 1 : 0, $row['id']]);
                    }
                    $processed++;
                }
                $db->commit();
            }
        }
        
        // Update job status
        $status = $processed >= $total ? 'completed' : 'processing';
        $db->prepare("UPDATE jobs SET status = ?, processed = ?, errors = ?, updated_at = datetime('now') WHERE id = ?")->execute([$status, $processed, $errors, $jobId]);
        
        $stmt = $db->prepare("SELECT * FROM jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        jsonResponse(['job' => $job]);
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
        jsonResponse($ai->getStatus());
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
        $message = $input['message'] ?? '';
        if (empty($message)) {
            jsonResponse(['error' => 'Message is required'], 400);
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
        $message = $input['message'] ?? '';
        if (empty($message)) {
            jsonResponse(['error' => 'Message is required'], 400);
        }
        
        $response = $ai->chat($message);
        jsonResponse(['response' => $response ?? 'Ollama nie jest dostępna. Sprawdź czy serwer jest uruchomiony.']);
    }
    
    jsonResponse(['error' => 'Not found'], 404);
}
