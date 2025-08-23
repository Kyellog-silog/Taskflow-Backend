<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\Board;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class OptimizedTaskController extends Controller
{
    /**
     * Get tasks for a board with optimized loading
     */
    public function getBoardTasks(Request $request, $boardId): JsonResponse
    {
        $board = Board::findOrFail($boardId);
        $this->authorize('view', $board);
        
        $cacheKey = "board_tasks_{$boardId}_" . md5($request->getQueryString() ?? '');
        
        $data = Cache::remember($cacheKey, 60, function() use ($boardId, $request) {
            $columns = \App\Models\BoardColumn::where('board_id', $boardId)
                ->orderBy('position')
                ->select('id', 'name', 'position', 'color')
                ->get();
                
            $tasks = Task::where('board_id', $boardId)
                ->with(['assignee:id,name,avatar', 'createdBy:id,name'])
                ->orderBy('position')
                ->get()
                ->groupBy('column_id');
                
            return [
                'columns' => $columns->map(function($column) use ($tasks) {
                    return [
                        'id' => $column->id,
                        'name' => $column->name,
                        'position' => $column->position,
                        'color' => $column->color,
                        'tasks' => $tasks->get($column->id, collect())->values()
                    ];
                })
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => $data
        ])->header('Cache-Control', 'private, max-age=60');
    }
}
