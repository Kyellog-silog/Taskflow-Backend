<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Notification;
use App\Http\Controllers\EventsController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth as AuthFacade;

class CommentController extends Controller
{
    public function index(Task $task): JsonResponse
    {
        Gate::authorize('view', $task);

        // Return only top-level comments with nested replies
        $comments = $task->comments()
            ->whereNull('parent_id')
            ->with(['user', 'replies.user'])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\TaskCommentResource::collection($comments)
        ]);
    }

    public function store(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('view', $task);

        $validated = $request->validate([
            'content' => 'required|string|max:1000',
            'parent_id' => 'nullable|exists:task_comments,id',
        ]);

        $comment = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
            'parent_id' => $validated['parent_id'] ?? null,
        ]);

        // Log activity
        $task->activities()->create([
            'user_id' => $request->user()->id,
            'action' => 'commented',
            'description' => 'Added a comment to the task'
        ]);

        // Push SSE event for real-time updates
        try {
            EventsController::queueEvent('comment.created', [
                'taskId' => $task->id,
                'commentId' => $comment->id,
                'parentId' => $comment->parent_id,
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Throwable $e) {}

        // Create notifications for task assignee and creator (excluding the actor)
        try {
            $actorId = $request->user()->id;
            $targets = collect([$task->assignee_id, $task->created_by])
                ->filter()
                ->unique()
                ->reject(fn ($id) => (int)$id === (int)$actorId);

            foreach ($targets as $uid) {
                Notification::create([
                    'user_id' => $uid,
                    'type' => 'comment.created',
                    'data' => [
                        'task_id' => $task->id,
                        'comment_id' => $comment->id,
                        'board_id' => $task->board_id,
                        'actor_id' => $actorId,
                    ],
                ]);
            }
            if ($targets->isNotEmpty()) {
                EventsController::queueEvent('notification.created', [
                    'timestamp' => now()->toISOString(),
                ]);
            }
        } catch (\Throwable $e) {}

        $fresh = $comment->load(['user', 'replies.user']);
        return response()->json([
            'success' => true,
            'data' => new \App\Http\Resources\TaskCommentResource($fresh)
        ], 201);
    }

    public function destroy(Task $task, TaskComment $comment): JsonResponse
    {
        Gate::authorize('view', $task);
        
    if ((int)$comment->user_id !== (int)AuthFacade::id()) {
            return response()->json([
                'success' => false,
                'message' => 'You can only delete your own comments'
            ], 403);
        }

        $comment->delete();

    // Push SSE event for real-time updates
        try {
            EventsController::queueEvent('comment.deleted', [
                'taskId' => $task->id,
                'commentId' => $comment->id,
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Throwable $e) {}

        return response()->json([
            'success' => true,
            'message' => 'Comment deleted successfully'
        ]);
    }
}
