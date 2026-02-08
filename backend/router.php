<?php
/**
 * PHP built-in server router
 * Usage: php -S localhost:8000 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static files from root
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

// Serve static files from public/ (built frontend assets)
if ($uri !== '/' && file_exists(__DIR__ . '/public' . $uri)) {
    $file = __DIR__ . '/public' . $uri;
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $mimeTypes = ['js' => 'application/javascript', 'css' => 'text/css', 'html' => 'text/html', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon'];
    if (isset($mimeTypes[$ext])) header('Content-Type: ' . $mimeTypes[$ext]);
    readfile($file);
    exit;
}

// Route API requests
if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/api/index.php';
    exit;
}

// Default: serve index or 404
if (file_exists(__DIR__ . '/public/index.html')) {
    include __DIR__ . '/public/index.html';
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
