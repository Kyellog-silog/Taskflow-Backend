<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransitionRequest;
use App\Http\Resources\TransitionResource;
use App\Models\Project;
use App\Models\Transition;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TransitionController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            'success' => true,
            'data' => TransitionResource::collection(
                $project->transitions()->with(['fromStatus', 'toStatus'])->get()
            ),
        ]);
    }

    public function store(StoreTransitionRequest $request, Project $project): JsonResponse
    {
        $validated = $request->validated();

        $transition = $project->transitions()->create([
            'from_status_id' => $validated['from_status_id'] ?? null,
            'to_status_id' => $validated['to_status_id'],
            'name' => $validated['name'] ?? null,
            'allowed_roles' => $validated['allowed_roles'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => new TransitionResource($transition->load(['fromStatus', 'toStatus'])),
        ], 201);
    }

    public function destroy(Transition $transition): JsonResponse
    {
        Gate::authorize('manageWorkflow', $transition->project);

        $transition->delete();

        return response()->json([
            'success' => true,
            'message' => 'Transition deleted',
        ]);
    }
}
