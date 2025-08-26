<?php
// Emergency debug script - bypasses Laravel entirely
header('Content-Type: application/json');

try {
    echo json_encode([
        'php_version' => phpversion(),
        'timestamp' => date('Y-m-d H:i:s'),
        'env_vars' => [
            'APP_ENV' => $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'not set',
            'APP_DEBUG' => $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?? 'not set',
            'APP_KEY' => isset($_ENV['APP_KEY']) || getenv('APP_KEY') ? 'SET' : 'NOT SET',
            'DB_HOST' => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'not set',
            'DB_USERNAME' => $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?? 'not set',
            'DB_PASSWORD' => isset($_ENV['DB_PASSWORD']) || getenv('DB_PASSWORD') ? 'SET' : 'NOT SET',
            'SESSION_DRIVER' => $_ENV['SESSION_DRIVER'] ?? getenv('SESSION_DRIVER') ?? 'not set',
        ],
        'extensions' => [
            'pdo' => extension_loaded('pdo') ? 'loaded' : 'missing',
            'pdo_pgsql' => extension_loaded('pdo_pgsql') ? 'loaded' : 'missing',
            'openssl' => extension_loaded('openssl') ? 'loaded' : 'missing',
        ],
        'filesystem' => [
            'storage_writable' => is_writable(__DIR__ . '/../storage') ? 'yes' : 'no',
            'bootstrap_cache_exists' => file_exists(__DIR__ . '/../bootstrap/cache') ? 'yes' : 'no',
        ]
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Emergency debug failed',
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
