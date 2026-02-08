<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/WhoisService.php';
require_once __DIR__ . '/../services/NotificationService.php';

$db = initDatabase();
$whois = new WhoisService();
$notifications = new NotificationService($db);

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
            handleDomains($segments[1] ?? '', $db, $whois, $notifications, $input, $method);
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

function handleDomains(string $action, PDO $db, WhoisService $whois, NotificationService $notifications, array $input, string $method): void {
    $user = requireAuth();
    
    if ($action === '' && $method === 'GET') {
        // List all domains
        $stmt = $db->query("SELECT d.*, u.email as added_by_email FROM domains d LEFT JOIN users u ON d.added_by = u.id ORDER BY d.expiry_date ASC NULLS LAST");
        $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['domains' => $domains]);
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
