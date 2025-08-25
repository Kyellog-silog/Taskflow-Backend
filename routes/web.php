<?php

use Illuminate\Support\Facades\Route;

// Simple test route
Route::get('/test', function () {
    return response()->json(['message' => 'Basic routing works']);
});

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// Minimal health check - no dependencies
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok', 
        'timestamp' => now(),
        'app_env' => app()->environment()
    ]);
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
