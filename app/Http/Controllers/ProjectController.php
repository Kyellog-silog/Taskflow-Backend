<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\BoardResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ProjectController extends Controller
{
    /**
     * List all projects visible to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $projects = Project::forUser($request->user()->id)
            ->with('lead:id,name,email')
            ->withCount(['boards', 'tasks'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => ProjectResource::collection($projects),
        ]);
    }

    /**
     * Create a project (team project when team_id given, personal otherwise).
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $project = Project::create([
            'team_id' => $validated['team_id'] ?? null,
            'name' => $validated['name'],
            'key' => $validated['key'],
            'description' => $validated['description'] ?? null,
            'lead_user_id' => $request->user()->id,
        ]);

        Log::info('Project created', ['project_id' => $project->id, 'key' => $project->key]);

        return response()->json([
            'success' => true,
            'data' => new ProjectResource($project->load('lead:id,name,email')),
        ], 201);
    }

    /**
     * Show a single project with its labels.
     */
    public function show(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $project->load(['lead:id,name,email', 'team', 'labels'])->loadCount(['boards', 'tasks']);

        return response()->json([
            'success' => true,
            'data' => new ProjectResource($project),
        ]);
    }

    /**
     * Update project details (key is immutable).
     */
    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $project->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => new ProjectResource($project->fresh()->load('lead:id,name,email')),
        ]);
    }

    /**
     * Soft-delete a project. Boards and tasks keep their issue keys.
     */
    public function destroy(Project $project): JsonResponse
    {
        Gate::authorize('delete', $project);

        $project->delete();

        Log::info('Project deleted', ['project_id' => $project->id]);

        return response()->json([
            'success' => true,
            'message' => 'Project deleted',
        ]);
    }

    /**
     * List active boards belonging to a project.
     */
    public function boards(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $boards = $project->boards()->active()->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => BoardResource::collection($boards),
        ]);
    }
}
