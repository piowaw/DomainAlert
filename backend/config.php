<?php
// Database configuration
define('DB_PATH', __DIR__ . '/database.sqlite');

// NTFY configuration - zmień NTFY_TOPIC na swój własny temat!
define('NTFY_SERVER', 'https://ntfy.sh');
define('NTFY_TOPIC', 'domainalert-demo'); // ZMIEŃ NA SWÓJ UNIKALNY TEMAT

// SMTP configuration (for email notifications)
// Odkomentuj i wypełnij aby włączyć powiadomienia email
// define('SMTP_HOST', 'smtp.gmail.com');
// define('SMTP_PORT', 587);
// define('SMTP_USER', 'your-email@gmail.com');
// define('SMTP_PASS', 'your-app-password');
// define('SMTP_FROM', 'your-email@gmail.com');
// define('SMTP_FROM_NAME', 'DomainAlert');

// JWT configuration - ZMIEŃ TO NA PRODUKCJI!
define('JWT_SECRET', 'ZMIEN-TO-NA-BARDZO-DLUGI-LOSOWY-CIAG-ZNAKOW');
define('JWT_EXPIRY', 86400 * 7); // 7 days

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Initialize database
function initDatabase(): PDO {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            is_admin INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS invitations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT UNIQUE NOT NULL,
            email TEXT,
            created_by INTEGER,
            used INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id)
        );
        
        CREATE TABLE IF NOT EXISTS domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT UNIQUE NOT NULL,
            expiry_date DATE,
            is_registered INTEGER DEFAULT 1,
            last_checked DATETIME,
            added_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (added_by) REFERENCES users(id)
        );
        
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain_id INTEGER,
            type TEXT,
            message TEXT,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (domain_id) REFERENCES domains(id)
        );
    ");
    
    // Create default admin if not exists
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE is_admin = 1");
    if ($stmt->fetchColumn() == 0) {
        $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $db->exec("INSERT INTO users (email, password, is_admin) VALUES ('admin@example.com', '$hashedPassword', 1)");
    }
    
    return $db;
}

// JWT functions
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
    $expectedSignature = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    
    if ($signature !== $expectedSignature) return null;
    
    $data = json_decode(base64_decode($payload), true);
    if ($data['exp'] < time()) return null;
    
    return $data;
}

function getAuthUser(): ?array {
    $authHeader = '';
    
    // Try multiple methods to get Authorization header
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        // Check both cases
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    
    // Fallback for servers where getallheaders doesn't work
    if (empty($authHeader) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }
    
    // Apache fallback
    if (empty($authHeader) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    
    // Another Apache mod_rewrite fallback
    if (empty($authHeader)) {
        $requestHeaders = [];
        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) === 'HTTP_') {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $requestHeaders[$header] = $value;
            }
        }
        $authHeader = $requestHeaders['Authorization'] ?? '';
    }
    
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        return verifyJWT($matches[1]);
    }
    
    return null;
}

function requireAuth(): array {
    $user = getAuthUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    return $user;
}

function requireAdmin(): array {
    $user = requireAuth();
    if (!$user['is_admin']) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit();
    }
    return $user;
}

function jsonResponse(mixed $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit();
}
