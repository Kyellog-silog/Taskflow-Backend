<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// Temporary debug route: captures and displays Sanctum CSRF cookie errors
// REMOVE THIS IMMEDIATELY AFTER DEBUGGING!
Route::get('/_debug/sanctum-csrf', function (\Illuminate\Http\Request $request) {
    try {
        // Create instance of the controller and call handle method
        $controller = new \Laravel\Sanctum\Http\Controllers\CsrfCookieController();
        return $controller->show($request);
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
