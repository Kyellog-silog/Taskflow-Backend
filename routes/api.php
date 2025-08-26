<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use App\Models\Task;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public authentication routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [RegisteredUserController::class, 'store']);
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store']);
    Route::post('/reset-password', [NewPasswordController::class, 'store']);
});

// Public team invitation routes (no auth required for viewing/accepting)
Route::get('/invitations/{token}', [TeamInvitationController::class, 'show']);
Route::post('/invitations/{token}/accept', [TeamInvitationController::class, 'accept'])->middleware('auth:sanctum');
Route::post('/invitations/{token}/reject', [TeamInvitationController::class, 'reject']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);
        Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store']);
    });
    
    // User routes
    Route::get('/user', [UserController::class, 'show']);
    
    Route::put('/user/profile', [RegisteredUserController::class, 'updateProfile']);
    

    // Team routes
    Route::apiResource('teams', TeamController::class);
    Route::post('/teams/{team}/members', [TeamController::class, 'addMember']);
    Route::delete('/teams/{team}/members/{user}', [TeamController::class, 'removeMember']);
    Route::put('/teams/{team}/members/{user}/role', [TeamController::class, 'updateMemberRole']);
    
    // Team invitation routes
    Route::post('/teams/{team}/invite', [TeamInvitationController::class, 'invite']);
    Route::get('/teams/{team}/invitations', [TeamInvitationController::class, 'index']);
    Route::delete('/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy']);

    // Board routes
    Route::apiResource('boards', BoardController::class);
    Route::get('/teams/{team}/boards', [BoardController::class, 'byTeam']);
    Route::get('/boards', [BoardController::class, 'index']);
    Route::get('/boards/{board}/teams', [BoardController::class, 'getTeams']);
    Route::post('/boards/{board}/teams/{team}', [BoardController::class, 'addTeam']);
    Route::delete('/boards/{board}/teams/{team}', [BoardController::class, 'removeTeam']);
    
    // Board archiving functionality
    Route::post('boards/{board}/archive', [BoardController::class, 'archive']);
    Route::post('boards/{board}/unarchive', [BoardController::class, 'unarchive']);
    Route::post('boards/{id}/restore', [BoardController::class, 'restore']);

    // Task routes
    Route::apiResource('tasks', TaskController::class);
    Route::post('/tasks/{task}/move', [TaskController::class, 'move']);
    Route::post('/tasks/{task}/complete', [TaskController::class, 'complete']);
    Route::post('/tasks/{task}/assign', [TaskController::class, 'assignTask']);
    Route::delete('/tasks/{task}/assign', [TaskController::class, 'unassignTask']);
    Route::post('/tasks/{task}/duplicate', [TaskController::class, 'duplicate']);

    // Comment routes
    Route::get('/tasks/{task}/comments', [CommentController::class, 'index']);
    Route::post('/tasks/{task}/comments', [CommentController::class, 'store']);
    Route::delete('/tasks/{task}/comments/{comment}', [CommentController::class, 'destroy']);

    // Task activities
    Route::get('/tasks/{task}/activities', function(Task $task) {
        Gate::authorize('view', $task);
        return response()->json([
            'success' => true,
            'data' => $task->activities()->with('user')->latest()->get()
        ]);
    });

    // Server-Sent Events stream (lightweight real-time updates)
    Route::get('/events/stream', [EventsController::class, 'stream']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    // Profile stats and activity
    Route::get('/profile/stats', [ProfileController::class, 'stats']);
    Route::get('/profile/activity', [ProfileController::class, 'activity']);
    Route::get('/profile/achievements', [ProfileController::class, 'achievements']);
    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);

    // File attachment routes
    Route::post('/tasks/{task}/attachments', function() {
        return response()->json(['message' => 'File upload not implemented yet']);
    });
});

// Email verification endpoint
Route::get('/email/verify/{id}/{hash}', [\App\Http\Controllers\Auth\VerifyEmailController::class, '__invoke'])
    ->middleware(['auth:sanctum', 'signed', 'throttle:6,1'])
    ->name('verification.verify');

// Application health check
Route::get('/health', function () {
    return response()->json([
        'status' => 'OK',
        'timestamp' => now(),
        'version' => '1.0.0',
        'laravel_version' => app()->version(),
    ]);
});

// CSRF token endpoints for SPA authentication
Route::get('/csrf-token', function () {
    return response()->json(['csrf_token' => csrf_token()]);
});
