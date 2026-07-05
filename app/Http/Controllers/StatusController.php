<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStatusRequest;
use App\Http\Requests\UpdateStatusRequest;
use App\Http\Resources\StatusResource;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\Status;
use App\Models\Task;
use App\Models\Transition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StatusController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            'success' => true,
            'data' => StatusResource::collection($project->statuses),
        ]);
    }

    public function store(StoreStatusRequest $request, Project $project): JsonResponse
    {
        $validated = $request->validated();

        $status = $project->statuses()->create([
            'name' => $validated['name'],
            'category' => $validated['category'],
            'position' => $validated['position'] ?? ((int) $project->statuses()->max('position') + 1),
        ]);

        // Preserve the open-workflow default: new statuses are reachable
        // from anywhere until the workflow is explicitly tightened
        $project->transitions()->create(['from_status_id' => null, 'to_status_id' => $status->id]);

        return response()->json([
            'success' => true,
            'data' => new StatusResource($status),
        ], 201);
    }

    public function update(UpdateStatusRequest $request, Status $status): JsonResponse
    {
        $status->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => new StatusResource($status->fresh()),
        ]);
    }

    /**
     * Delete a status. Tasks and columns still on it must be remapped, so a
     * same-project move_to_status_id is required — no orphaned statuses.
     */
    public function destroy(Request $request, Status $status): JsonResponse
    {
        Gate::authorize('manageWorkflow', $status->project);

        $validated = $request->validate([
            'move_to_status_id' => [
                'required',
                'integer',
                'different:'.$status->id,
                \Illuminate\Validation\Rule::exists('statuses', 'id')->where('project_id', $status->project_id),
            ],
        ]);

        if ($status->is_default) {
            return response()->json([
                'success' => false,
                'message' => 'The default status cannot be deleted. Mark another status as default first.',
            ], 422);
        }

        DB::transaction(function () use ($status, $validated) {
            Task::where('status_id', $status->id)->update(['status_id' => $validated['move_to_status_id']]);
            BoardColumn::where('status_id', $status->id)->update(['status_id' => $validated['move_to_status_id']]);
            Transition::where('from_status_id', $status->id)
                ->orWhere('to_status_id', $status->id)
                ->delete();
            $status->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Status deleted; tasks and columns were moved.',
        ]);
    }
}
