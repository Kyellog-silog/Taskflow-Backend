<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use App\Services\PerformanceMonitor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requestStartTime = microtime(true);
        PerformanceMonitor::enable();
        PerformanceMonitor::enableQueryLogging();
        
        try {
            $user   = $request->user();
            $limit  = min((int) $request->get('limit', 20), 50);
            $afterId = $request->get('after_id');
            $type   = $request->get('type');

            PerformanceMonitor::startTimer('notification_query', [
                'user_id' => $user->id,
                'limit'   => $limit,
            ]);

            $query = Notification::where('user_id', $user->id)->latest();

            if ($afterId) {
                $cursor = Notification::find($afterId);
                if ($cursor) {
                    $query->where('created_at', '<', $cursor->created_at)
                          ->where('id', '!=', $cursor->id);
                }
            }

            if ($type) {
                $query->where('type', $type);
            }

            // Fetch one extra to determine has_more without a second COUNT query
            $notifications = $query->limit($limit + 1)->get();
            $hasMore       = $notifications->count() > $limit;
            $notifications = $notifications->take($limit);
            $nextCursor    = $hasMore ? $notifications->last()?->id : null;

            // Preload actors in a single query to avoid N+1
            $actorIds   = $notifications->pluck('data.actor_id')->filter()->unique();
            $actorNames = User::whereIn('id', $actorIds)->pluck('name', 'id');
            $notifications->each(function ($n) use ($actorNames) {
                $n->actor_name = $actorNames[$n->data['actor_id'] ?? null] ?? null;
            });

            PerformanceMonitor::endTimer('notification_query', [
                'notification_count' => $notifications->count(),
            ]);

            PerformanceMonitor::logQueryStats('notification_index');

            $response = response()->json([
                'success'     => true,
                'data'        => \App\Http\Resources\NotificationResource::collection($notifications),
                'has_more'    => $hasMore,
                'next_cursor' => $nextCursor,
            ]);
            
            PerformanceMonitor::logRequestSummary('GET /notifications', microtime(true) - $requestStartTime, strlen($response->getContent()));
            return $response;
        } catch (\Exception $e) {
            PerformanceMonitor::logQueryStats('notification_index_error');
            Log::error('Failed to fetch notifications', ['user_id' => $request->user()?->id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notifications'
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
            
            $response->headers->set('Cache-Control', 'private, max-age=3');
            
            PerformanceMonitor::logRequestSummary('GET /notifications/unread-count', microtime(true) - $requestStartTime, strlen($response->getContent()));
            return $response;
        } catch (\Exception $e) {
            PerformanceMonitor::logQueryStats('notification_unread_count_error');
            Log::error('Failed to fetch unread count', ['user_id' => $request->user()?->id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch unread count'
            ], 500);
        }
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        $notification = Notification::where('id', $notification)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

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

    public function destroy(Request $request, int $notification): JsonResponse
    {
        $notification = Notification::where('id', $notification)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $notification->delete();

        return response()->json(['success' => true], 204);
    }
}
