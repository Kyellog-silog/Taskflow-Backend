<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use App\Services\PerformanceMonitor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requestStartTime = microtime(true);
        PerformanceMonitor::enable();
        PerformanceMonitor::enableQueryLogging();
        
        try {
            $user = $request->user();
            Log::info('Fetching teams', ['user_id' => $user->id]);
            
            PerformanceMonitor::startTimer('teams_query_building', [
                'user_id' => $user->id
            ]);

            // Single efficient query with proper aggregation
            $teams = Team::forUser($user->id)
                        ->with([
                            'owner:id,name,avatar', 
                            'members' => function($q){ 
                                $q->select('users.id','users.name','users.avatar'); 
                            }
                        ])
                        ->withCount(['members', 'boards'])
                        ->get();

            PerformanceMonitor::endTimer('teams_query_building');

            PerformanceMonitor::startTimer('teams_tasks_count_aggregation', [
                'teams_count' => $teams->count()
            ]);

            // Efficiently get task counts for all teams at once
            $teamIds = $teams->pluck('id');
            $taskCounts = DB::table('tasks')
                ->join('boards', 'tasks.board_id', '=', 'boards.id')
                ->whereIn('boards.team_id', $teamIds)
                ->whereNull('tasks.deleted_at')
                ->whereNull('boards.deleted_at')
                ->select('boards.team_id', DB::raw('count(*) as task_count'))
                ->groupBy('boards.team_id')
                ->pluck('task_count', 'team_id');

            PerformanceMonitor::endTimer('teams_tasks_count_aggregation');

            PerformanceMonitor::startTimer('teams_response_formatting');

            $formattedTeams = $teams->map(function ($team) use ($taskCounts) {
                return [
                    'id' => $team->id,
                    'name' => $team->name,
                    'description' => $team->description,
                    'owner' => [ 
                        'id' => $team->owner->id, 
                        'name' => $team->owner->name, 
                        'avatar' => $team->owner->avatar 
                    ],
                    'members' => $team->members->map(function ($member) {
                        return [
                            'id' => $member->id,
                            'name' => $member->name,
                            'avatar' => $member->avatar ?? null,
                            'role' => $member->pivot->role,
                            'joined_at' => $member->pivot->joined_at,
                        ];
                    }),
                    'boards' => $team->boards_count,
                    'tasks' => $taskCounts[$team->id] ?? 0,
                    'created_at' => $team->created_at,
                    'updated_at' => $team->updated_at,
                ];
            });

            PerformanceMonitor::endTimer('teams_response_formatting');
            PerformanceMonitor::logQueryStats('teams_index');

            Log::info('Teams fetched successfully', ['count' => $teams->count()]);

            $response = response()->json([
                'success' => true,
                'data' => $formattedTeams
            ]);

            PerformanceMonitor::logRequestSummary('GET /teams', microtime(true) - $requestStartTime, strlen($response->getContent()));
            return $response;
        } catch (\Exception $e) {
            PerformanceMonitor::logQueryStats('teams_index_error');
            Log::error('Error fetching teams', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch teams: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $team = Team::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'owner_id' => $request->user()->id,
        ]);

        // Add the owner as a member with admin role
        $team->addMember($request->user(), 'admin');

        return response()->json([
            'success' => true,
            'data' => $team->load(['owner', 'members', 'boards'])
        ], 201);
    }

    public function show(Team $team): JsonResponse
    {
        Gate::authorize('view', $team);

    $team->load(['owner:id,name,avatar', 'members' => function($q){ $q->select('users.id','users.name','users.avatar'); }, 'boards']);
        $tasksCount = $team->boards()->withCount('tasks')->get()->sum('tasks_count');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $team->id,
                'name' => $team->name,
                'description' => $team->description,
                'owner' => [ 'id' => $team->owner->id, 'name' => $team->owner->name, 'avatar' => $team->owner->avatar ],
                'members' => $team->members->map(function ($member) {
                    return [
                        'id' => $member->id,
                        'name' => $member->name,
                        'avatar' => $member->avatar ?? null,
                        'role' => $member->pivot->role,
                        'joined_at' => $member->pivot->joined_at,
                    ];
                }),
                'boards' => $team->boards->count(),
                'tasks' => $tasksCount,
                'created_at' => $team->created_at,
                'updated_at' => $team->updated_at,
            ]
        ]);
    }

    public function update(Request $request, Team $team): JsonResponse
    {
        Gate::authorize('update', $team);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $team->update($validated);

        $fresh = $team->fresh(['owner:id,name,avatar', 'members' => function($q){ $q->select('users.id','users.name','users.avatar'); }, 'boards']);
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $fresh->id,
                'name' => $fresh->name,
                'description' => $fresh->description,
                'owner' => [ 'id' => $fresh->owner->id, 'name' => $fresh->owner->name, 'avatar' => $fresh->owner->avatar ],
                'members' => $fresh->members->map(function ($member) {
                    return [
                        'id' => $member->id,
                        'name' => $member->name,
                        'avatar' => $member->avatar ?? null,
                        'role' => $member->pivot->role,
                        'joined_at' => $member->pivot->joined_at,
                    ];
                }),
                'boards' => $fresh->boards->count(),
                'created_at' => $fresh->created_at,
                'updated_at' => $fresh->updated_at,
            ]
        ]);
    }

    public function destroy(Team $team): JsonResponse
    {
        Gate::authorize('delete', $team);

        $team->delete();

        return response()->json([
            'success' => true,
            'message' => 'Team deleted successfully'
        ]);
    }

    public function addMember(Request $request, Team $team): JsonResponse
    {
        Gate::authorize('manage', $team);

        $validated = $request->validate([
            'email' => 'required|email|exists:users,email',
            'role' => 'in:admin,member',
        ]);

        $user = User::where('email', $validated['email'])->first();
        $role = $validated['role'] ?? 'member';

        if ($team->isMember($user)) {
            return response()->json([
                'success' => false,
                'message' => 'User is already a member of this team'
            ], 400);
        }

        $team->addMember($user, $role);

        $fresh = $team->fresh(['members' => function($q){ $q->select('users.id','users.name','users.avatar'); }]);
        return response()->json([
            'success' => true,
            'message' => 'Member added successfully',
            'data' => [
                'id' => $fresh->id,
                'members' => $fresh->members->map(function ($member) {
                    return [
                        'id' => $member->id,
                        'name' => $member->name,
                        'avatar' => $member->avatar ?? null,
                        'role' => $member->pivot->role,
                        'joined_at' => $member->pivot->joined_at,
                    ];
                }),
            ]
        ]);
    }

    public function removeMember(Team $team, User $user): JsonResponse
    {
        Gate::authorize('manage', $team);

        if ($team->isOwner($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot remove team owner'
            ], 400);
        }

        if (!$team->isMember($user)) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a member of this team'
            ], 400);
        }

        $team->removeMember($user);

        return response()->json([
            'success' => true,
            'message' => 'Member removed successfully'
        ]);
    }

    public function updateMemberRole(Request $request, Team $team, User $user): JsonResponse
    {
        Gate::authorize('manage', $team);

        $validated = $request->validate([
            'role' => 'required|in:admin,member,viewer'
        ]);

        if ($team->isOwner($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot change owner role'
            ], 400);
        }

        if (!$team->isMember($user)) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a member of this team'
            ], 400);
        }

        // Update the member's role in the pivot table
        $team->members()->updateExistingPivot($user->id, ['role' => $validated['role']]);

        // Notify via SSE so other sessions can refresh
        try {
            \App\Http\Controllers\EventsController::queueEvent('team.updated', [
                'teamId' => $team->id,
                'userId' => $user->id,
                'role' => $validated['role'],
                'timestamp' => now()->timestamp,
            ]);
        } catch (\Throwable $e) {
            // Don't fail the request if SSE queueing fails
        }

        $fresh = $team->fresh(['members' => function($q){ $q->select('users.id','users.name','users.avatar'); }]);
        return response()->json([
            'success' => true,
            'message' => 'Member role updated successfully',
            'data' => [
                'id' => $fresh->id,
                'members' => $fresh->members->map(function ($member) {
                    return [
                        'id' => $member->id,
                        'name' => $member->name,
                        'avatar' => $member->avatar ?? null,
                        'role' => $member->pivot->role,
                        'joined_at' => $member->pivot->joined_at,
                    ];
                }),
            ]
        ]);
    }

    public function getTeamBoards(Team $team): JsonResponse
    {
        Gate::authorize('view', $team);

        $boards = $team->boards()
                      ->with(['columns', 'createdBy'])
                      ->withCount(['tasks', 'columns'])
                      ->get()
                      ->map(function ($board) {
                          return [
                              'id' => $board->id,
                              'name' => $board->name,
                              'description' => $board->description,
                              'created_by' => $board->createdBy,
                              'tasks_count' => $board->tasks_count,
                              'columns_count' => $board->columns_count,
                              'created_at' => $board->created_at,
                              'updated_at' => $board->updated_at,
                          ];
                      });

        return response()->json([
            'success' => true,
            'data' => $boards
        ]);
    }
}
