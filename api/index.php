<?php

/**
 * Vercel PHP serverless entry point for Laravel.
 *
 * Vercel's filesystem is read-only at runtime (except /tmp).
 * We redirect all writable paths to /tmp so Laravel can compile
 * views, write logs, and manage the session cache.
 */

define('LARAVEL_START', microtime(true));

// Bootstrap writable directories in /tmp for Vercel's read-only filesystem
$tmpStorage = '/tmp/storage';
$tmpBootstrapCache = '/tmp/bootstrap/cache';

foreach ([
    "$tmpStorage/framework/cache/data",
    "$tmpStorage/framework/sessions",
    "$tmpStorage/framework/views",
    "$tmpStorage/logs",
    "$tmpStorage/app",
    $tmpBootstrapCache,
] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Copy bootstrap cache files to /tmp if they exist in the project
$projectBootstrapCache = __DIR__ . '/../bootstrap/cache';
foreach (['packages.php', 'services.php'] as $cacheFile) {
    $src = "$projectBootstrapCache/$cacheFile";
    $dst = "$tmpBootstrapCache/$cacheFile";
    if (file_exists($src) && !file_exists($dst)) {
        copy($src, $dst);
    }
}

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

// Redirect storage to /tmp so Laravel can write at runtime
$app->useStoragePath($tmpStorage);

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

$response->send();

$kernel->terminate($request, $response);
