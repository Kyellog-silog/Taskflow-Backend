<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLabelRequest;
use App\Http\Requests\UpdateLabelRequest;
use App\Http\Resources\LabelResource;
use App\Models\Label;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class LabelController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            'success' => true,
            'data' => LabelResource::collection($project->labels),
        ]);
    }

    public function store(StoreLabelRequest $request, Project $project): JsonResponse
    {
        $label = $project->labels()->create([
            'name' => $request->validated('name'),
            'color' => $request->validated('color') ?? '#6B7280',
        ]);

        return response()->json([
            'success' => true,
            'data' => new LabelResource($label),
        ], 201);
    }

    public function update(UpdateLabelRequest $request, Label $label): JsonResponse
    {
        $label->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => new LabelResource($label->fresh()),
        ]);
    }

    public function destroy(Label $label): JsonResponse
    {
        Gate::authorize('manageLabels', $label->project);

        $label->delete();

        return response()->json([
            'success' => true,
            'message' => 'Label deleted',
        ]);
    }
}
