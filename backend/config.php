<?php
// Database configuration — MySQL
define('DB_HOST', 'localhost');
define('DB_NAME', 'domainalert');
define('DB_USER', 'domainalert');
define('DB_PASS', 'omainalert');
define('DB_CHARSET', 'utf8mb4');

// Legacy SQLite path (used only by migration script)
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

// Ollama AI configuration
define('OLLAMA_URL', 'http://localhost:11434');
define('OLLAMA_MODEL', 'deepseek-r1:1.5b'); // Zmień na inny model jeśli chcesz, np. llama3, mistral, phi3

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Initialize database — MySQL
function initDatabase(): PDO {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $db = new PDO($dsn, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // MySQL performance tuning
    $db->exec("SET SESSION innodb_lock_wait_timeout = 30");
    $db->exec("SET SESSION wait_timeout = 600");
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            email VARCHAR(255) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            is_admin TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS invitations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            token VARCHAR(255) UNIQUE NOT NULL,
            email VARCHAR(255),
            created_by INT,
            used TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS domains (
            id INT PRIMARY KEY AUTO_INCREMENT,
            domain VARCHAR(255) UNIQUE NOT NULL,
            expiry_date DATE,
            is_registered TINYINT(1) DEFAULT 1,
            last_checked DATETIME,
            added_by INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (added_by) REFERENCES users(id),
            INDEX idx_expiry (expiry_date),
            INDEX idx_registered (is_registered),
            INDEX idx_last_checked (last_checked)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT PRIMARY KEY AUTO_INCREMENT,
            domain_id INT,
            type VARCHAR(50),
            message TEXT,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (domain_id) REFERENCES domains(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
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
    
    // Create default admin if not exists
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE is_admin = 1");
    if ($stmt->fetchColumn() == 0) {
        $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users (email, password, is_admin) VALUES (?, ?, 1)")->execute(['admin@example.com', $hashedPassword]);
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
