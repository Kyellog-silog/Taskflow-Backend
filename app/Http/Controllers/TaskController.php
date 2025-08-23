<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\TaskActivity;
use App\Models\Notification;
use App\Http\Controllers\EventsController;
use App\Services\PerformanceMonitor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    /**
     * List tasks with optional filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $requestStartTime = microtime(true);
        PerformanceMonitor::enable();
        PerformanceMonitor::enableQueryLogging();
        
        try {
            $user = $request->user();
            Log::info('Fetching tasks', ['user_id' => $user->id, 'filters' => $request->all()]);
            
            PerformanceMonitor::startTimer('task_query_building', [
                'user_id' => $user->id,
                'filters' => $request->all()
            ]);

            $query = Task::query();
            
            // Only load relationships when needed
            $relationships = ['assignee:id,name,avatar', 'createdBy:id,name'];
            
            if (!$request->boolean('only_count')) {
                if ($request->get('include_board')) {
                    $relationships[] = 'board:id,name,team_id,created_by';
                }
                if ($request->get('include_column')) {
                    $relationships[] = 'column:id,board_id,name';
                }
                if ($request->get('include_comments')) {
                    $relationships[] = 'comments:id,task_id,user_id,content,created_at';
                    $relationships[] = 'comments.user:id,name,avatar';
                }
            }
            
            $query->with($relationships);

            // Filter by board
            if ($request->has('board_id')) {
                PerformanceMonitor::startTimer('board_authorization_check');
                $board = Board::findOrFail($request->board_id);
                Gate::authorize('view', $board);
                PerformanceMonitor::endTimer('board_authorization_check');
                
                $query->byBoard($request->board_id);
            } else {
                // Only show tasks from boards the user can access and that are active (not archived)
                $query->whereHas('board', function ($q) use ($user) {
                    $q->forUser($user->id)->active();
                });
            }

            // Apply all filters
            if ($request->has('column_id')) {
                $query->byColumn($request->column_id);
            }

            if ($request->has('assignee_id')) {
                $query->byAssignee($request->assignee_id);
            }

            if ($request->has('priority')) {
                $query->byPriority($request->priority);
            }

            if ($request->has('search')) {
                $query->where('title', 'like', '%' . $request->search . '%');
            }

            if ($request->has('status')) {
                $query->where('column_id', $request->status);
            }

            if ($request->boolean('uncompleted')) {
                $query->whereNull('completed_at');
                $query->whereHas('column', function ($cq) {
                    $cq->whereRaw('LOWER(name) NOT LIKE ?', ['%done%'])
                       ->whereRaw('LOWER(name) NOT LIKE ?', ['%complete%']);
                });
            }

            // Due date based filters
            if ($request->has('due')) {
                $due = $request->get('due');
                $query->whereNotNull('due_date');
                switch ($due) {
                    case 'today':
                        $query->whereDate('due_date', now()->toDateString());
                        break;
                    case 'tomorrow':
                        $query->whereDate('due_date', now()->addDay()->toDateString());
                        break;
                    case 'overdue':
                        $query->whereDate('due_date', '<', now()->toDateString());
                        break;
                    case 'soon':
                        $days = (int) $request->get('days', 3);
                        if ($days < 0) { $days = 0; }
                        $start = now()->startOfDay();
                        $end = now()->addDays($days)->endOfDay();
                        $query->whereBetween('due_date', [$start, $end]);
                        break;
                }
            }

            PerformanceMonitor::endTimer('task_query_building');

            // Pagination and limits
            $limit = (int) ($request->get('limit', 200));
            $limit = max(1, min($limit, 500));
            $page = (int) ($request->get('page', 1));

            // Only return a count when requested
            if ($request->boolean('only_count')) {
                PerformanceMonitor::startTimer('task_count_query');
                $count = $query->count();
                PerformanceMonitor::endTimer('task_count_query', ['count' => $count]);
                
                PerformanceMonitor::logQueryStats('task_count');
                
                Log::info('Tasks count fetched successfully', ['count' => $count]);
                $response = response()->json([
                    'success' => true,
                    'data' => ['count' => $count]
                ]);
                $response->headers->set('Cache-Control', 'private, max-age=30');
                
                PerformanceMonitor::logRequestSummary('GET /tasks (count)', microtime(true) - $requestStartTime, strlen($response->getContent()));
                return $response;
            }

            // Execute main query with timing
            PerformanceMonitor::startTimer('task_main_query', [
                'limit' => $limit,
                'page' => $page,
                'relationships' => count($relationships)
            ]);
            
            $tasks = $query->orderBy('position')
                ->forPage($page, $limit)
                ->get();
            
            PerformanceMonitor::endTimer('task_main_query', [
                'tasks_count' => $tasks->count(),
                'relationships_loaded' => count($relationships)
            ]);
            
            PerformanceMonitor::logQueryStats('task_index');
            
            Log::info('Tasks fetched successfully', ['count' => $tasks->count()]);
            
            $response = response()->json([
                'success' => true,
                'data' => \App\Http\Resources\TaskResource::collection($tasks)
            ]);
            $response->headers->set('Cache-Control', 'private, max-age=15');
            
            PerformanceMonitor::logRequestSummary('GET /tasks', microtime(true) - $requestStartTime, strlen($response->getContent()));
            return $response;
        } catch (\Exception $e) {
            PerformanceMonitor::logQueryStats('task_index_error');
            Log::error('Error fetching tasks', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch tasks: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new task.
     */
    public function store(Request $request): JsonResponse
    {
        $requestStartTime = microtime(true);
        PerformanceMonitor::enable();
        PerformanceMonitor::enableQueryLogging();
        
        try {
            Log::info('Creating task with request data:', $request->all());
            
            PerformanceMonitor::startTimer('task_validation', [
                'request_size' => strlen(json_encode($request->all()))
            ]);
            
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'board_id' => 'required|exists:boards,id',
                'column_id' => 'required|exists:board_columns,id',
                'assignee_id' => 'nullable|exists:users,id',
                'priority' => 'nullable|in:low,medium,high',
                'due_date' => 'nullable|date',
            ]);
            
            PerformanceMonitor::endTimer('task_validation');
            Log::info('Validated task data:', $validated);

            // Check permissions and verify relationships
            PerformanceMonitor::startTimer('task_authorization_checks');
            
            $board = Board::findOrFail($validated['board_id']);
            Gate::authorize('createTasks', $board);

            $column = BoardColumn::where('id', $validated['column_id'])
                ->where('board_id', $validated['board_id'])
                ->firstOrFail();

            PerformanceMonitor::endTimer('task_authorization_checks');

            // Get position and create task
            PerformanceMonitor::startTimer('task_creation_transaction');
            
            $position = Task::where('column_id', $validated['column_id'])->count();

            DB::beginTransaction();
            
            $task = Task::create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'board_id' => $validated['board_id'],
                'column_id' => $validated['column_id'],
                'assignee_id' => $validated['assignee_id'] ?? null,
                'created_by' => $request->user()->id,
                'priority' => $validated['priority'] ?? 'medium',
                'due_date' => $validated['due_date'] ?? null,
                'position' => $position,
            ]);

            // Log activity
            TaskActivity::create([
                'task_id' => $task->id,
                'user_id' => $request->user()->id,
                'action' => 'created',
                'description' => 'Task created'
            ]);

            DB::commit();
            PerformanceMonitor::endTimer('task_creation_transaction', ['task_id' => $task->id]);

            // Load relationships
            PerformanceMonitor::startTimer('task_relationship_loading');
            $task = $task->fresh(['assignee', 'createdBy', 'comments.user', 'board', 'column']);
            PerformanceMonitor::endTimer('task_relationship_loading');
            
            PerformanceMonitor::logQueryStats('task_store');
            Log::info('Task created successfully', ['task_id' => $task->id]);

            // Emit SSE event for real-time updates
            try {
                EventsController::queueEvent('task.created', [
                    'boardId' => $task->board_id,
                    'taskId' => $task->id,
                    'columnId' => $task->column_id,
                    'position' => $task->position,
                    'userId' => Auth::id(),
                    'timestamp' => now()->toISOString(),
                ]);
            } catch (\Throwable $e) {}
            
            $response = response()->json([
                'success' => true,
                'data' => $task
            ], 201);
            
            PerformanceMonitor::logRequestSummary('POST /tasks', microtime(true) - $requestStartTime, strlen($response->getContent()));
            return $response;
        } catch (\Exception $e) {
            DB::rollback();
            PerformanceMonitor::logQueryStats('task_store_error');
            Log::error('Error creating task', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific task with its relationships.
     */
    public function show(Task $task): JsonResponse
    {
        try {
            Gate::authorize('view', $task->board);
            Log::info('Fetching task details', ['task_id' => $task->id]);
            
            $task->load([
                'assignee', 
                'createdBy', 
                'comments.user', 
                'activities.user', 
                'board', 
                'column',
                'attachments'
            ]);
            
            Log::info('Task fetched successfully', ['task_id' => $task->id]);
            
            $response = response()->json([
                'success' => true,
                'data' => new \App\Http\Resources\TaskResource($task)
            ]);
            $response->headers->set('Cache-Control', 'private, max-age=30');
            return $response;
        } catch (\Exception $e) {
            Log::error('Error fetching task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a task.
     */
    public function update(Request $request, Task $task): JsonResponse
    {
        try {
            Gate::authorize('update', $task);
            Log::info('Updating task', ['task_id' => $task->id, 'data' => $request->all()]);
            
            $validated = $request->validate([
                'title' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'assignee_id' => 'nullable|exists:users,id',
                'priority' => 'sometimes|in:low,medium,high',
                'due_date' => 'nullable|date',
                'completed_at' => 'nullable|date',
            ]);

            $oldValues = $task->only(array_keys($validated));
            
            DB::beginTransaction();
            
            $task->update($validated);

            // Log activity
            TaskActivity::create([
                'task_id' => $task->id,
                'user_id' => $request->user()->id,
                'action' => 'updated',
                'description' => 'Task updated',
                'old_values' => $oldValues,
                'new_values' => $validated
            ]);

            DB::commit();
            
            Log::info('Task updated successfully', ['task_id' => $task->id]);

            // Emit SSE event for real-time updates
            try {
                EventsController::queueEvent('task.updated', [
                    'boardId' => $task->board_id,
                    'taskId' => $task->id,
                    'userId' => Auth::id(),
                    'timestamp' => now()->toISOString(),
                ]);
            } catch (\Throwable $e) {}
            
            $fresh = $task->fresh(['assignee', 'createdBy', 'comments.user', 'board', 'column']);
            return response()->json([
                'success' => true,
                'data' => new \App\Http\Resources\TaskResource($fresh)
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error updating task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a task.
     */
    public function destroy(Task $task): JsonResponse
    {
        try {
            Gate::authorize('delete', $task);
            Log::info('Deleting task', ['task_id' => $task->id]);
            
            DB::beginTransaction();
            // Capture key fields before delete
            $actorId = Auth::id();
            $taskTitle = $task->title;
            
            // Log activity prior to soft delete so it remains visible in profile feed
            try {
                TaskActivity::create([
                    'task_id' => $task->id,
                    'user_id' => $actorId,
                    'action' => 'deleted',
                    'description' => 'Task deleted'.($taskTitle ? ': '.$taskTitle : ''),
                ]);
            } catch (\Throwable $e) {
                // non-fatal
            }
            
            // The position updates are handled by the Task model's boot method
            $task->delete();
            
            DB::commit();
            
            Log::info('Task deleted successfully', ['task_id' => $task->id]);

            // Emit SSE event for real-time updates
            try {
                EventsController::queueEvent('task.deleted', [
                    'boardId' => $task->board_id,
                    'taskId' => $task->id,
                    'userId' => $actorId,
                    'timestamp' => now()->toISOString(),
                ]);
            } catch (\Throwable $e) {}
            
            return response()->json([
                'success' => true,
                'message' => 'Task deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error deleting task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Move a task to a different column or position.
     */
    public function move(Request $request, Task $task): JsonResponse
    {
        $requestStartTime = microtime(true);
        PerformanceMonitor::enable();
        PerformanceMonitor::enableQueryLogging();
        
        try {
            Gate::authorize('move', $task);
            Log::info('Moving task', ['task_id' => $task->id, 'data' => $request->all()]);
            
            PerformanceMonitor::startTimer('task_move_validation');
            $validated = $request->validate([
                'column_id' => 'required|exists:board_columns,id',
                'position' => 'required|integer|min:0',
                'operation_id' => 'nullable|string',
                'client_timestamp' => 'nullable|integer',
            ]);
            PerformanceMonitor::endTimer('task_move_validation');

            // Conflict detection with timing
            if (isset($validated['client_timestamp'])) {
                PerformanceMonitor::startTimer('task_conflict_check');
                $timeDifference = ($task->updated_at->timestamp * 1000) - $validated['client_timestamp'];
                
                if ($timeDifference > 2000) {
                    PerformanceMonitor::endTimer('task_conflict_check', ['conflict_detected' => true]);
                    return response()->json([
                        'success' => false,
                        'conflict' => true,
                        'message' => 'Task was modified by another user',
                        'current_state' => $task->fresh(['assignee', 'createdBy', 'column']),
                        'time_difference' => $timeDifference
                    ], 409);
                }
                PerformanceMonitor::endTimer('task_conflict_check', ['conflict_detected' => false]);
            }

            // Verify column belongs to same board
            PerformanceMonitor::startTimer('task_move_authorization');
            $column = BoardColumn::where('id', $validated['column_id'])
                ->where('board_id', $task->board_id)
                ->firstOrFail();
            PerformanceMonitor::endTimer('task_move_authorization');

            $oldColumnId = $task->column_id;
            $oldPosition = $task->position;
            
            PerformanceMonitor::startTimer('task_move_position_updates', [
                'old_column' => $oldColumnId,
                'new_column' => $validated['column_id'],
                'old_position' => $oldPosition,
                'new_position' => $validated['position']
            ]);
            
            DB::beginTransaction();
            
            // Update positions of other tasks - this is often the slowest part
            if ($task->column_id != $validated['column_id']) {
                // Moving to different column
                Task::where('column_id', $task->column_id)
                    ->where('position', '>', $task->position)
                    ->decrement('position');
                    
                Task::where('column_id', $validated['column_id'])
                    ->where('position', '>=', $validated['position'])
                    ->increment('position');
            } else {
                // Moving within same column
                if ($validated['position'] > $task->position) {
                    Task::where('column_id', $task->column_id)
                        ->whereBetween('position', [$task->position + 1, $validated['position']])
                        ->decrement('position');
                } else if ($validated['position'] < $task->position) {
                    Task::where('column_id', $task->column_id)
                        ->whereBetween('position', [$validated['position'], $task->position - 1])
                        ->increment('position');
                }
            }

            // Update task position
            $task->update([
                'column_id' => $validated['column_id'],
                'position' => $validated['position'],
            ]);

            // Log activity
            $oldColumn = $task->board->columns->firstWhere('id', $oldColumnId);
            $newColumn = $task->board->columns->firstWhere('id', $validated['column_id']);
            
            TaskActivity::create([
                'task_id' => $task->id,
                'user_id' => $request->user()->id,
                'action' => 'moved',
                'description' => "Task moved from {$oldColumn->name} to {$newColumn->name}",
                'old_values' => ['column_id' => $oldColumnId, 'position' => $oldPosition],
                'new_values' => $validated
            ]);

            DB::commit();
            PerformanceMonitor::endTimer('task_move_position_updates');
            
            PerformanceMonitor::logQueryStats('task_move');
            
            Log::info('Task moved successfully', [
                'task_id' => $task->id, 
                'from' => ['column' => $oldColumnId, 'position' => $oldPosition],
                'to' => ['column' => $validated['column_id'], 'position' => $validated['position']]
            ]);

            // Emit SSE event for real-time updates
            try {
                EventsController::queueEvent('task.moved', [
                    'boardId' => $task->board_id,
                    'taskId' => $task->id,
                    'fromColumn' => $oldColumnId,
                    'toColumn' => $validated['column_id'],
                    'position' => $validated['position'],
                    'userId' => Auth::id(),
                    'timestamp' => now()->toISOString(),
                ]);
            } catch (\Throwable $e) {}
            
            $response = response()->json([
                'success' => true,
                'data' => $task->fresh(['assignee', 'createdBy', 'comments.user', 'board', 'column']),
                'server_timestamp' => now()->timestamp * 1000,
                'operation_id' => $validated['operation_id'] ?? null
            ]);
            
            PerformanceMonitor::logRequestSummary('POST /tasks/{id}/move', microtime(true) - $requestStartTime, strlen($response->getContent()));
            return $response;
        } catch (\Exception $e) {
            DB::rollback();
            PerformanceMonitor::logQueryStats('task_move_error');
            Log::error('Error moving task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to move task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark a task as completed.
     */
    public function complete(Task $task): JsonResponse
    {
        try {
            Gate::authorize('update', $task);
            Log::info('Marking task as completed', ['task_id' => $task->id]);
            
            DB::beginTransaction();
            
            $task->update(['completed_at' => now()]);

            // Log activity
            TaskActivity::create([
                'task_id' => $task->id,
                'user_id' => Auth::id(),
                'action' => 'completed',
                'description' => 'Task marked as completed'
            ]);

            DB::commit();
            
            Log::info('Task marked as completed successfully', ['task_id' => $task->id]);

            // Emit SSE event for real-time updates
            try {
                EventsController::queueEvent('task.updated', [
                    'boardId' => $task->board_id,
                    'taskId' => $task->id,
                    'userId' => Auth::id(),
                    'timestamp' => now()->toISOString(),
                ]);
                // Notify assignee and creator (excluding actor)
                $actorId = (int) Auth::id();
                $targets = collect([$task->assignee_id, $task->created_by])
                    ->filter()
                    ->unique()
                    ->reject(fn ($id) => (int)$id === $actorId);
                foreach ($targets as $uid) {
                    Notification::create([
                        'user_id' => $uid,
                        'type' => 'task.completed',
                        'data' => [
                            'task_id' => $task->id,
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
            
            $fresh = $task->fresh(['assignee', 'createdBy', 'comments.user', 'board', 'column']);
            return response()->json([
                'success' => true,
                'data' => new \App\Http\Resources\TaskResource($fresh)
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error completing task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign a task to a user.
     */
    public function assignTask(Request $request, Task $task): JsonResponse
    {
        try {
            Gate::authorize('update', $task);
            Log::info('Assigning task', ['task_id' => $task->id, 'data' => $request->all()]);
            
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id'
            ]);

            $oldAssignee = $task->assignee;
            
            DB::beginTransaction();
            
            $task->update(['assignee_id' => $validated['user_id']]);

            // Log activity
            TaskActivity::create([
                'task_id' => $task->id,
                'user_id' => Auth::id(),
                'action' => 'assigned',
                'description' => "Task assigned to {$task->fresh()->assignee->name}",
                'old_values' => ['assignee_id' => $oldAssignee?->id],
                'new_values' => ['assignee_id' => $validated['user_id']]
            ]);

            DB::commit();
            
            Log::info('Task assigned successfully', [
                'task_id' => $task->id, 
                'assignee_id' => $validated['user_id']
            ]);

            // Emit SSE event for real-time updates
            try {
                EventsController::queueEvent('task.updated', [
                    'boardId' => $task->board_id,
                    'taskId' => $task->id,
                    'userId' => Auth::id(),
                    'timestamp' => now()->toISOString(),
                ]);
                // Notify newly assigned user (if not the actor)
                $actorId = (int) Auth::id();
                $assigneeId = (int) $validated['user_id'];
                if ($assigneeId !== $actorId) {
                    Notification::create([
                        'user_id' => $assigneeId,
                        'type' => 'task.assigned',
                        'data' => [
                            'task_id' => $task->id,
                            'board_id' => $task->board_id,
                            'actor_id' => $actorId,
                        ],
                    ]);
                    EventsController::queueEvent('notification.created', [
                        'timestamp' => now()->toISOString(),
                    ]);
                }
            } catch (\Throwable $e) {}
            
            $fresh = $task->fresh(['assignee', 'createdBy', 'comments.user', 'board', 'column']);
            return response()->json([
                'success' => true,
                'data' => new \App\Http\Resources\TaskResource($fresh)
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error assigning task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unassign a task.
     */
    public function unassignTask(Task $task): JsonResponse
    {
        try {
            Gate::authorize('update', $task);
            Log::info('Unassigning task', ['task_id' => $task->id]);
            
            $oldAssignee = $task->assignee;

            DB::beginTransaction();
            
            $task->update(['assignee_id' => null]);

            // Log activity
            TaskActivity::create([
                'task_id' => $task->id,
                'user_id' => Auth::id(),
                'action' => 'unassigned',
                'description' => $oldAssignee ? "Task unassigned from {$oldAssignee->name}" : "Task unassigned",
                'old_values' => ['assignee_id' => $oldAssignee?->id],
                'new_values' => ['assignee_id' => null]
            ]);

            DB::commit();
            
            Log::info('Task unassigned successfully', ['task_id' => $task->id]);

            // Emit SSE event for real-time updates
            try {
                EventsController::queueEvent('task.updated', [
                    'boardId' => $task->board_id,
                    'taskId' => $task->id,
                    'userId' => Auth::id(),
                    'timestamp' => now()->toISOString(),
                ]);
            } catch (\Throwable $e) {}
            
            $fresh = $task->fresh(['assignee', 'createdBy', 'comments.user', 'board', 'column']);
            return response()->json([
                'success' => true,
                'data' => new \App\Http\Resources\TaskResource($fresh)
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error unassigning task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to unassign task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Duplicate a task.
     */
    public function duplicate(Task $task): JsonResponse
    {
        try {
            Gate::authorize('view', $task->board);
            Log::info('Duplicating task', ['task_id' => $task->id]);
            
            DB::beginTransaction();
            
            $newTask = $task->replicate();
            $newTask->title = $task->title . ' (Copy)';
            $newTask->position = Task::where('column_id', $task->column_id)->count();
            $newTask->created_by = Auth::id();
            $newTask->completed_at = null;
            $newTask->save();

            // Log activity for new task
            TaskActivity::create([
                'task_id' => $newTask->id,
                'user_id' => Auth::id(),
                'action' => 'created',
                'description' => "Task duplicated from #{$task->id}"
            ]);

            DB::commit();
            
            Log::info('Task duplicated successfully', [
                'original_task_id' => $task->id,
                'new_task_id' => $newTask->id
            ]);

            // Emit SSE event for real-time updates
            try {
                EventsController::queueEvent('task.created', [
                    'boardId' => $newTask->board_id,
                    'taskId' => $newTask->id,
                    'columnId' => $newTask->column_id,
                    'position' => $newTask->position,
                    'userId' => Auth::id(),
                    'timestamp' => now()->toISOString(),
                ]);
            } catch (\Throwable $e) {}
            
            $fresh = $newTask->load(['assignee', 'createdBy', 'comments.user', 'board', 'column']);
            return response()->json([
                'success' => true,
                'data' => new \App\Http\Resources\TaskResource($fresh)
            ], 201);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error duplicating task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to duplicate task: ' . $e->getMessage()
            ], 500);
        }
    }
}