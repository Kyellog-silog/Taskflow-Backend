<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\PerformanceMonitor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requestStartTime = microtime(true);
        PerformanceMonitor::enable();
        PerformanceMonitor::enableQueryLogging();
        
        try {
            $user = $request->user();
            $limit = (int) ($request->get('limit', 20));
            
            PerformanceMonitor::startTimer('notification_query', [
                'user_id' => $user->id,
                'limit' => $limit
            ]);
            
            $notifications = Notification::where('user_id', $user->id)
                ->latest()
                ->limit($limit)
                ->get();
                
            PerformanceMonitor::endTimer('notification_query', [
                'notification_count' => $notifications->count()
            ]);
            
            PerformanceMonitor::logQueryStats('notification_index');
            
            $response = response()->json([
                'success' => true,
                'data' => \App\Http\Resources\NotificationResource::collection($notifications),
            ]);
            
            PerformanceMonitor::logRequestSummary('GET /notifications', microtime(true) - $requestStartTime, strlen($response->getContent()));
            return $response;
        } catch (\Exception $e) {
            PerformanceMonitor::logQueryStats('notification_index_error');
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notifications: ' . $e->getMessage()
            ], 500);
        }
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $requestStartTime = microtime(true);
        PerformanceMonitor::enable();
        PerformanceMonitor::enableQueryLogging();
        
        try {
            $user = $request->user();
            
            PerformanceMonitor::startTimer('notification_unread_count_query', [
                'user_id' => $user->id
            ]);
            
            $count = Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->count();
                
            PerformanceMonitor::endTimer('notification_unread_count_query', [
                'unread_count' => $count
            ]);
            
            PerformanceMonitor::logQueryStats('notification_unread_count');
            
            $response = response()->json([
                'success' => true,
                'data' => ['count' => $count],
            ]);
            
            $response->headers->set('Cache-Control', 'private, max-age=10'); // Cache for 10 seconds
            
            PerformanceMonitor::logRequestSummary('GET /notifications/unread-count', microtime(true) - $requestStartTime, strlen($response->getContent()));
            return $response;
        } catch (\Exception $e) {
            PerformanceMonitor::logQueryStats('notification_unread_count_error');
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch unread count: ' . $e->getMessage()
            ], 500);
        }
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Not authorized to modify this notification'
            ], 403);
        }
        $notification->update(['read_at' => now()]);
        return response()->json([
            'success' => true,
            'data' => new \App\Http\Resources\NotificationResource($notification),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
        ]);
    }
}
