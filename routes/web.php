<?php

use Illuminate\Support\Facades\Route;

// Temporary debug route that shows environment info (remove in production)
Route::get('/_debug/env', function () {
    // Only show safe, non-sensitive info
    return response()->json([
        'app' => [
            'env' => config('app.env'),
            'debug' => config('app.debug'),
            'url' => config('app.url'),
        ],
        'sanctum' => [
            'stateful_domains' => config('sanctum.stateful'),
            'expiration' => config('sanctum.expiration'),
        ],
        'session' => [
            'driver' => config('session.driver'),
            'domain' => config('session.domain'),
            'secure' => config('session.secure'),
            'same_site' => config('session.same_site'),
            'path' => config('session.path'),
        ],
        'cors' => [
            'paths' => config('cors.paths'),
            'allowed_origins' => config('cors.allowed_origins'),
            'supports_credentials' => config('cors.supports_credentials'),
        ],
        'headers' => collect(request()->headers->all())
            ->filter(fn($v, $k) => in_array(strtolower($k), ['origin', 'host', 'user-agent', 'accept', 'referer']))
            ->toArray(),
    ]);
});

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// Temporary debug route: captures and displays Sanctum CSRF cookie errors
// REMOVE THIS IMMEDIATELY AFTER DEBUGGING!
Route::get('/_debug/sanctum-csrf', function (\Illuminate\Http\Request $request) {
    try {
        // Use the actual route handling logic for sanctum/csrf-cookie
        $reflector = new \ReflectionClass('Laravel\Sanctum\Http\Controllers\CsrfCookieController');
        $instance = $reflector->newInstance();
        
        // Try different methods - '__invoke' is common for single-action controllers
        if ($reflector->hasMethod('__invoke')) {
            return $instance->__invoke($request);
        } elseif ($reflector->hasMethod('show')) {
            return $instance->show($request);
        } else {
            throw new \Exception('Could not find the correct method on CsrfCookieController');
        }
    } catch (\Throwable $e) {
        \Log::error('Debug sanctum csrf error', ['exception' => (string) $e]);
        return response()->json([
            'error' => 'sanctum.csrf.error',
            'message' => $e->getMessage(),
            'type' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});

require __DIR__.'/auth.php';

require __DIR__.'/auth.php';
