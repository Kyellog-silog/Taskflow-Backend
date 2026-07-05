<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Label;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\Transition;
use App\Services\PerformanceMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

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
                'filters' => $request->all(),
            ]);

            $query = Task::query();

            $relationships = $request->boolean('only_count') ? [] : [
                'assignee:id,name,avatar',
                'createdBy:id,name',
            ];

            if (! $request->boolean('only_count')) {
                $request->get('include_board') && $relationships[] = 'board:id,name,team_id,created_by';
                $request->get('include_column') && $relationships[] = 'column:id,board_id,name';
                if ($request->get('include_comments')) {
                    $relationships[] = 'comments:id,task_id,user_id,content,created_at';
                    $relationships[] = 'comments.user:id,name,avatar';
                }
            }

            $relationships && $query->with($relationships);

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
                $query->where('title', 'like', '%'.$request->search.'%');
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
                        if ($days < 0) {
                            $days = 0;
                        }
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
                    'data' => ['count' => $count],
                ]);
                $response->headers->set('Cache-Control', 'private, max-age=30');

                PerformanceMonitor::logRequestSummary('GET /tasks (count)', microtime(true) - $requestStartTime, strlen($response->getContent()));

                return $response;
            }

            // Execute main query with timing
            PerformanceMonitor::startTimer('task_main_query', [
                'limit' => $limit,
                'page' => $page,
                'relationships' => count($relationships),
            ]);

            $tasks = $query->orderBy('position')
                ->forPage($page, $limit)
                ->get();

            PerformanceMonitor::endTimer('task_main_query', [
                'tasks_count' => $tasks->count(),
                'relationships_loaded' => count($relationships),
            ]);

            PerformanceMonitor::logQueryStats('task_index');

            Log::info('Tasks fetched successfully', ['count' => $tasks->count()]);

            $response = response()->json([
                'success' => true,
                'data' => \App\Http\Resources\TaskResource::collection($tasks),
            ]);
            $response->headers->set('Cache-Control', 'private, max-age=15');

            PerformanceMonitor::logRequestSummary('GET /tasks', microtime(true) - $requestStartTime, strlen($response->getContent()));

            return $response;
        } catch (\Exception $e) {
            PerformanceMonitor::logQueryStats('task_index_error');
            Log::error('Error fetching tasks', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch tasks: '.$e->getMessage(),
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
                'request_size' => strlen(json_encode($request->all())),
            ]);

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'board_id' => 'required|exists:boards,id',
                'column_id' => 'required|exists:board_columns,id',
                'assignee_id' => 'nullable|exists:users,id',
                'priority' => 'nullable|in:'.implode(',', Task::PRIORITIES),
                'due_date' => 'nullable|date',
                'issue_type' => 'nullable|in:'.implode(',', Task::ISSUE_TYPES),
                'story_points' => 'nullable|integer|min:0|max:100',
                'parent_id' => 'nullable|integer|exists:tasks,id',
                'epic_id' => 'nullable|integer|exists:tasks,id',
                'labels' => 'nullable|array|max:20',
                'labels.*' => 'integer|exists:labels,id',
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

            // Validate that the assignee is a member of the board's team
            if (! empty($validated['assignee_id'])) {
                $assignee = \App\Models\User::findOrFail($validated['assignee_id']);
                if ($board->team_id && ! $board->team->isMember($assignee)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Assignee must be a member of the board\'s team.',
                    ], 422);
                }
            }

            PerformanceMonitor::endTimer('task_authorization_checks');

            // Validate Jira-style relations against the board's project
            if ($error = $this->validateIssueRelations($board->project_id, $validated)) {
                return response()->json(['success' => false, 'message' => $error], 422);
            }
            if ($error = $this->validateLabels($validated['labels'] ?? null, $board->project_id)) {
                return response()->json(['success' => false, 'message' => $error], 422);
            }

            // Get position and create task
            PerformanceMonitor::startTimer('task_creation_transaction');

            $position = Task::where('column_id', $validated['column_id'])->count();

            DB::beginTransaction();

            $task = Task::create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'board_id' => $validated['board_id'],
                'column_id' => $validated['column_id'],
                'assignee_id' => $validated['assignee_id'] ?? $request->user()->id, // Default to creator
                'created_by' => $request->user()->id,
                'priority' => $validated['priority'] ?? 'medium',
                'due_date' => $validated['due_date'] ?? null,
                'position' => $position,
                'issue_type' => $validated['issue_type'] ?? 'task',
                'story_points' => $validated['story_points'] ?? null,
                'parent_id' => $validated['parent_id'] ?? null,
                'epic_id' => $validated['epic_id'] ?? null,
            ]);

            if (! empty($validated['labels'])) {
                $task->labels()->sync($validated['labels']);
            }

            // Log activity
            TaskActivity::create([
                'task_id' => $task->id,
                'user_id' => $request->user()->id,
                'action' => 'created',
                'description' => 'Task created',
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
            } catch (\Throwable $e) {
            }

            $response = response()->json([
                'success' => true,
                'data' => $task,
            ], 201);

            PerformanceMonitor::logRequestSummary('POST /tasks', microtime(true) - $requestStartTime, strlen($response->getContent()));

            return $response;
        } catch (\Illuminate\Validation\ValidationException|\Illuminate\Auth\Access\AuthorizationException $e) {
            DB::rollback();
            throw $e; // Let the framework render proper 422/403 responses
        } catch (\Exception $e) {
            DB::rollback();
            PerformanceMonitor::logQueryStats('task_store_error');
            Log::error('Error creating task', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create task',
            ], 500);
        }
    }

    /**
     * List the workflow transitions available to the current user from this
     * task's status (wildcards included, role-filtered).
     */
    public function transitions(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('view', $task->board);

        $project = $task->project_id ? Project::find($task->project_id) : null;
        if (! $project) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $role = $project->userRole($request->user());
        $available = $project->availableTransitionsFor($task->status_id, $role);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\TransitionResource::collection($available),
        ]);
    }

    /**
     * Execute a workflow transition: validates the edge and the user's role,
     * then moves the task to the first column on its board mapped to the
     * target status.
     */
    public function transition(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('move', $task);

        $validated = $request->validate([
            'transition_id' => 'required|integer|exists:transitions,id',
        ]);

        $transitionModel = Transition::with('toStatus')->findOrFail($validated['transition_id']);

        if ($transitionModel->project_id !== $task->project_id) {
            return response()->json([
                'success' => false,
                'message' => 'Transition does not belong to this task\'s project.',
            ], 422);
        }

        if ($transitionModel->from_status_id !== null && $transitionModel->from_status_id !== $task->status_id) {
            return response()->json([
                'success' => false,
                'message' => 'Transition is not available from the task\'s current status.',
            ], 422);
        }

        $project = Project::findOrFail($task->project_id);
        $role = $project->userRole($request->user());
        if (! $transitionModel->roleAllowed($role)) {
            return response()->json([
                'success' => false,
                'message' => 'Your role is not allowed to use this transition.',
            ], 403);
        }

        $targetColumn = BoardColumn::where('board_id', $task->board_id)
            ->where('status_id', $transitionModel->to_status_id)
            ->orderBy('position')
            ->first();

        if (! $targetColumn) {
            return response()->json([
                'success' => false,
                'message' => 'No column on this board is mapped to the target status.',
            ], 422);
        }

        $oldStatusId = $task->status_id;

        DB::transaction(function () use ($task, $targetColumn, $transitionModel, $request, $oldStatusId) {
            $position = Task::where('column_id', $targetColumn->id)->count();
            $task->moveToColumn($targetColumn->id, $position, $request->user());
            $task->update(['status_id' => $transitionModel->to_status_id]);

            TaskActivity::create([
                'task_id' => $task->id,
                'user_id' => $request->user()->id,
                'action' => 'transitioned',
                'description' => 'Task transitioned to '.$transitionModel->toStatus->name,
                'old_values' => ['status_id' => $oldStatusId],
                'new_values' => ['status_id' => $transitionModel->to_status_id],
            ]);
        });

        try {
            EventsController::queueEvent('task.moved', [
                'boardId' => $task->board_id,
                'taskId' => $task->id,
                'toColumn' => $targetColumn->id,
                'userId' => Auth::id(),
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json([
            'success' => true,
            'data' => new \App\Http\Resources\TaskResource($task->fresh(['assignee', 'createdBy', 'board', 'column', 'labels'])),
        ]);
    }

    /**
     * Resolve a task by its immutable issue key (e.g. "TF-123").
     */
    public function showByKey(string $issueKey): JsonResponse
    {
        $task = Task::where('issue_key', strtoupper($issueKey))->firstOrFail();

        return $this->show($task);
    }

    /**
     * Ensure parent/epic references stay inside the task's project and
     * respect issue-type semantics. Returns an error message or null.
     */
    private function validateIssueRelations(?int $projectId, array $validated, ?Task $current = null): ?string
    {
        if (! empty($validated['parent_id'])) {
            $parent = Task::find($validated['parent_id']);
            if (! $parent || ! $projectId || $parent->project_id !== $projectId) {
                return 'Parent task must belong to the same project.';
            }
            if ($parent->issue_type === 'subtask') {
                return 'Subtasks cannot be nested.';
            }
            if ($current && $parent->id === $current->id) {
                return 'A task cannot be its own parent.';
            }
        }

        if (! empty($validated['epic_id'])) {
            $epic = Task::find($validated['epic_id']);
            if (! $epic || ! $projectId || $epic->project_id !== $projectId) {
                return 'Epic must belong to the same project.';
            }
            if ($epic->issue_type !== 'epic') {
                return 'epic_id must reference a task of type epic.';
            }
            if ($current && $epic->id === $current->id) {
                return 'A task cannot be its own epic.';
            }
        }

        return null;
    }

    /**
     * Ensure every attached label belongs to the task's project.
     */
    private function validateLabels(?array $labelIds, ?int $projectId): ?string
    {
        if (empty($labelIds)) {
            return null;
        }
        if (! $projectId) {
            return 'This board has no project; labels are unavailable.';
        }

        $validCount = Label::whereIn('id', $labelIds)->where('project_id', $projectId)->count();
        if ($validCount !== count(array_unique($labelIds))) {
            return 'All labels must belong to the task\'s project.';
        }

        return null;
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
                'attachments',
                'labels',
            ]);

            Log::info('Task fetched successfully', ['task_id' => $task->id]);

            $response = response()->json([
                'success' => true,
                'data' => new \App\Http\Resources\TaskResource($task),
            ]);
            $response->headers->set('Cache-Control', 'private, max-age=30');

            return $response;
        } catch (\Exception $e) {
            Log::error('Error fetching task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch task',
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
                'priority' => 'sometimes|in:'.implode(',', Task::PRIORITIES),
                'due_date' => 'nullable|date',
                'completed_at' => 'nullable|date',
                'issue_type' => 'sometimes|in:'.implode(',', Task::ISSUE_TYPES),
                'story_points' => 'nullable|integer|min:0|max:100',
                'parent_id' => 'nullable|integer|exists:tasks,id',
                'epic_id' => 'nullable|integer|exists:tasks,id',
                'labels' => 'sometimes|array|max:20',
                'labels.*' => 'integer|exists:labels,id',
            ]);

            // Validate Jira-style relations against the task's project
            if ($error = $this->validateIssueRelations($task->project_id, $validated, $task)) {
                return response()->json(['success' => false, 'message' => $error], 422);
            }
            if (array_key_exists('labels', $validated)) {
                if ($error = $this->validateLabels($validated['labels'], $task->project_id)) {
                    return response()->json(['success' => false, 'message' => $error], 422);
                }
            }

            // Validate that the assignee is a member of the board's team
            if (! empty($validated['assignee_id'])) {
                $assignee = \App\Models\User::findOrFail($validated['assignee_id']);
                if ($task->board->team_id && ! $task->board->team->isMember($assignee)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Assignee must be a member of the board\'s team.',
                    ], 422);
                }
            }

            $oldValues = $task->only(array_keys($validated));

            DB::beginTransaction();

            $labelIds = null;
            if (array_key_exists('labels', $validated)) {
                $labelIds = $validated['labels'];
                unset($validated['labels']);
            }

            $task->update($validated);

            if ($labelIds !== null) {
                $task->labels()->sync($labelIds);
            }

            // Log activity
            TaskActivity::create([
                'task_id' => $task->id,
                'user_id' => $request->user()->id,
                'action' => 'updated',
                'description' => 'Task updated',
                'old_values' => $oldValues,
                'new_values' => $validated,
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
            } catch (\Throwable $e) {
            }

            $fresh = $task->fresh(['assignee', 'createdBy', 'comments.user', 'board', 'column', 'labels']);

            return response()->json([
                'success' => true,
                'data' => new \App\Http\Resources\TaskResource($fresh),
            ]);
        } catch (\Illuminate\Validation\ValidationException|\Illuminate\Auth\Access\AuthorizationException $e) {
            DB::rollback();
            throw $e; // Let the framework render proper 422/403 responses
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error updating task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update task',
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
            } catch (\Throwable $e) {
            }

            return response()->json([
                'success' => true,
                'message' => 'Task deleted successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error deleting task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete task: '.$e->getMessage(),
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
                        'time_difference' => $timeDifference,
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

            // Drag-and-drop is a workflow transition: the server re-validates
            // against the project's transition graph regardless of what the
            // client pre-filtered
            if ($task->project_id && $column->status_id && $task->status_id !== $column->status_id) {
                $project = Project::find($task->project_id);
                $role = $project?->userRole($request->user());
                if ($project && ! $project->allowsTransition($task->status_id, $column->status_id, $role)) {
                    $allowed = $project->availableTransitionsFor($task->status_id, $role);

                    return response()->json([
                        'success' => false,
                        'message' => 'This move is not allowed by the project workflow.',
                        'allowed_status_ids' => $allowed->pluck('to_status_id'),
                    ], 422);
                }
            }

            $oldColumnId = $task->column_id;
            $oldPosition = $task->position;

            PerformanceMonitor::startTimer('task_move_position_updates', [
                'old_column' => $oldColumnId,
                'new_column' => $validated['column_id'],
                'old_position' => $oldPosition,
                'new_position' => $validated['position'],
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
                } elseif ($validated['position'] < $task->position) {
                    Task::where('column_id', $task->column_id)
                        ->whereBetween('position', [$validated['position'], $task->position - 1])
                        ->increment('position');
                }
            }

            // Update task position (status follows the destination column)
            $task->update([
                'column_id' => $validated['column_id'],
                'position' => $validated['position'],
                'status_id' => $column->status_id ?? $task->status_id,
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
                'new_values' => $validated,
            ]);

            DB::commit();
            PerformanceMonitor::endTimer('task_move_position_updates');

            PerformanceMonitor::logQueryStats('task_move');

            Log::info('Task moved successfully', [
                'task_id' => $task->id,
                'from' => ['column' => $oldColumnId, 'position' => $oldPosition],
                'to' => ['column' => $validated['column_id'], 'position' => $validated['position']],
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
            } catch (\Throwable $e) {
            }

            $response = response()->json([
                'success' => true,
                'data' => $task->fresh(['assignee', 'createdBy', 'comments.user', 'board', 'column']),
                'server_timestamp' => now()->timestamp * 1000,
                'operation_id' => $validated['operation_id'] ?? null,
            ]);

            PerformanceMonitor::logRequestSummary('POST /tasks/{id}/move', microtime(true) - $requestStartTime, strlen($response->getContent()));

            return $response;
        } catch (\Exception $e) {
            DB::rollback();
            PerformanceMonitor::logQueryStats('task_move_error');
            Log::error('Error moving task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to move task',
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
                'description' => 'Task marked as completed',
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
                    ->reject(fn ($id) => (int) $id === $actorId);
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
            } catch (\Throwable $e) {
                Log::warning('Failed to create task.completed notifications', ['task_id' => $task->id, 'error' => $e->getMessage()]);
            }

            $fresh = $task->fresh(['assignee', 'createdBy', 'comments.user', 'board', 'column']);

            return response()->json([
                'success' => true,
                'data' => new \App\Http\Resources\TaskResource($fresh),
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error completing task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to complete task: '.$e->getMessage(),
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
                'user_id' => 'required|exists:users,id',
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
                'new_values' => ['assignee_id' => $validated['user_id']],
            ]);

            DB::commit();

            Log::info('Task assigned successfully', [
                'task_id' => $task->id,
                'assignee_id' => $validated['user_id'],
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
            } catch (\Throwable $e) {
                Log::warning('Failed to create task.assigned notification', ['task_id' => $task->id, 'error' => $e->getMessage()]);
            }

            $fresh = $task->fresh(['assignee', 'createdBy', 'comments.user', 'board', 'column']);

            return response()->json([
                'success' => true,
                'data' => new \App\Http\Resources\TaskResource($fresh),
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error assigning task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to assign task: '.$e->getMessage(),
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
                'description' => $oldAssignee ? "Task unassigned from {$oldAssignee->name}" : 'Task unassigned',
                'old_values' => ['assignee_id' => $oldAssignee?->id],
                'new_values' => ['assignee_id' => null],
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
            } catch (\Throwable $e) {
            }

            $fresh = $task->fresh(['assignee', 'createdBy', 'comments.user', 'board', 'column']);

            return response()->json([
                'success' => true,
                'data' => new \App\Http\Resources\TaskResource($fresh),
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error unassigning task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to unassign task: '.$e->getMessage(),
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
            $newTask->title = $task->title.' (Copy)';
            $newTask->position = Task::where('column_id', $task->column_id)->count();
            $newTask->created_by = Auth::id();
            $newTask->completed_at = null;
            $newTask->save();

            // Log activity for new task
            TaskActivity::create([
                'task_id' => $newTask->id,
                'user_id' => Auth::id(),
                'action' => 'created',
                'description' => "Task duplicated from #{$task->id}",
            ]);

            DB::commit();

            Log::info('Task duplicated successfully', [
                'original_task_id' => $task->id,
                'new_task_id' => $newTask->id,
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
            } catch (\Throwable $e) {
            }

            $fresh = $newTask->load(['assignee', 'createdBy', 'comments.user', 'board', 'column']);

            return response()->json([
                'success' => true,
                'data' => new \App\Http\Resources\TaskResource($fresh),
            ], 201);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error duplicating task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to duplicate task: '.$e->getMessage(),
            ], 500);
        }
    }
}
