<?php
/**
 * PHP built-in server router
 * Usage: php -S localhost:8000 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static files
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

// Route API requests
if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/api/index.php';
    exit;
}

// Default: serve index or 404
if (file_exists(__DIR__ . '/index.html')) {
    include __DIR__ . '/index.html';
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
