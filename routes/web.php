<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// Debug route to test CSRF cookie functionality
Route::get('/debug-csrf', function () {
    try {
        $token = csrf_token();
        return response()->json([
            'status' => 'success',
            'csrf_token' => $token,
            'session_id' => session()->getId(),
            'session_driver' => config('session.driver'),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

require __DIR__.'/auth.php';
