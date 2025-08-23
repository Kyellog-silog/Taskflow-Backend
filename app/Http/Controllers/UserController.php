<?php

namespace App\Http\Controllers;

use App\Services\PerformanceMonitor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    /**
     * Get the authenticated user's profile
     */
    public function show(Request $request): JsonResponse
    {
        $requestStartTime = microtime(true);
        PerformanceMonitor::enable();
        PerformanceMonitor::enableQueryLogging();
        
        try {
            $user = $request->user();
            Log::info('Fetching user profile', ['user_id' => $user->id]);
            
            PerformanceMonitor::startTimer('user_profile_loading', [
                'user_id' => $user->id
            ]);

            // Load user with minimal required relationships
            $user = $user->load(['teams:id,name', 'ownedTeams:id,name']);
            
            PerformanceMonitor::endTimer('user_profile_loading');
            PerformanceMonitor::logQueryStats('user_show');

            Log::info('User profile fetched successfully', ['user_id' => $user->id]);

            $response = response()->json([
                'success' => true,
                'data' => [
                    'user' => new \App\Http\Resources\UserResource($user)
                ]
            ]);

            $response->headers->set('Cache-Control', 'private, max-age=300'); // Cache for 5 minutes

            PerformanceMonitor::logRequestSummary('GET /user', microtime(true) - $requestStartTime, strlen($response->getContent()));
            return $response;
        } catch (\Exception $e) {
            PerformanceMonitor::logQueryStats('user_show_error');
            Log::error('Error fetching user profile', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user profile: ' . $e->getMessage()
            ], 500);
        }
    }
}
