<?php

use Illuminate\Support\Facades\Route;

// Simple test route
Route::get('/test', function () {
    return response()->json(['message' => 'Basic routing works']);
});

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// Debug registration endpoint
Route::post('/debug-register', function (\Illuminate\Http\Request $request) {
    return response()->json([
        'received_data' => $request->all(),
        'validation_rules' => [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', 'min:8'],
        ],
        'headers' => $request->headers->all(),
        'cookies' => $request->cookies->all(),
    ]);
});

// Debug session endpoint
Route::get('/debug-session', function (\Illuminate\Http\Request $request) {
    return response()->json([
        'session_id' => $request->session()->getId(),
        'session_data' => $request->session()->all(),
        'auth_user' => $request->user(),
        'cookies' => $request->cookies->all(),
        'headers' => [
            'User-Agent' => $request->header('User-Agent'),
            'X-Requested-With' => $request->header('X-Requested-With'),
            'Accept' => $request->header('Accept'),
        ],
        'is_authenticated' => Auth::check(),
        'auth_id' => Auth::id(),
    ]);
});

// Emergency cache clear route - call this once then remove
Route::get('/clear-all-cache', function () {
    try {
        Artisan::call('config:clear');
        Artisan::call('cache:clear');
        Artisan::call('route:clear');
        return response()->json([
            'status' => 'All caches cleared successfully',
            'artisan_output' => Artisan::output(),
            'app_env' => env('APP_ENV'),
            'db_host' => env('DB_HOST'),
            'db_username' => env('DB_USERNAME')
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

// Emergency diagnostic route
Route::get('/debug-clear', function () {
    try {
        Artisan::call('config:clear');
        Artisan::call('cache:clear');
        Artisan::call('route:clear');
        return response()->json([
            'status' => 'Cache cleared successfully',
            'config_clear' => Artisan::output(),
            'app_env' => env('APP_ENV'),
            'db_host' => env('DB_HOST'),
            'db_username' => env('DB_USERNAME')
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
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
