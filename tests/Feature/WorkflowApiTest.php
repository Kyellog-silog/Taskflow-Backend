<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_project_seeds_default_open_workflow(): void
    {
        $project = Project::factory()->create();

        $this->assertSame(
            ['To Do', 'In Progress', 'Done'],
            $project->statuses()->pluck('name')->all()
        );
        $this->assertTrue((bool) $project->statuses()->where('name', 'To Do')->value('is_default'));
        // One wildcard transition into each status
        $this->assertSame(3, $project->transitions()->whereNull('from_status_id')->count());
    }

    public function test_new_column_maps_to_matching_or_new_status(): void
    {
        [, $board, , $project] = $this->makeProjectBoard();

        $existing = BoardColumn::factory()->create(['board_id' => $board->id, 'name' => 'To Do']);
        $this->assertSame(
            $project->statuses()->where('name', 'To Do')->value('id'),
            $existing->status_id
        );

        $novel = BoardColumn::factory()->create(['board_id' => $board->id, 'name' => 'Code Review']);
        $status = $novel->status()->first();
        $this->assertNotNull($status);
        $this->assertSame('Code Review', $status->name);
        $this->assertSame('in_progress', $status->category);
    }

    public function test_task_inherits_status_from_column(): void
    {
        [$owner, $board, $column, $project] = $this->makeProjectBoard();
        Sanctum::actingAs($owner);

        $task = $this->postJson('/api/tasks', [
            'title' => 'Workflow task',
            'board_id' => $board->id,
            'column_id' => $column->id,
        ])->json('data');

        $this->assertSame($column->fresh()->status_id, $task['status_id']);
    }

    public function test_move_allowed_under_open_workflow_and_syncs_status(): void
    {
        [$owner, $board, $todoColumn, $project] = $this->makeProjectBoard();
        $doneColumn = BoardColumn::factory()->create(['board_id' => $board->id, 'name' => 'Done', 'position' => 2]);
        Sanctum::actingAs($owner);

        $task = Task::factory()->create([
            'board_id' => $board->id,
            'column_id' => $todoColumn->id,
            'created_by' => $owner->id,
        ]);

        $this->postJson("/api/tasks/{$task->id}/move", [
            'column_id' => $doneColumn->id,
            'position' => 0,
        ])->assertStatus(200);

        $this->assertSame($doneColumn->status_id, $task->fresh()->status_id);
    }

    public function test_restricted_workflow_blocks_disallowed_move(): void
    {
        [$owner, $board, $todoColumn, $project] = $this->makeProjectBoard();
        $progressColumn = BoardColumn::factory()->create(['board_id' => $board->id, 'name' => 'In Progress', 'position' => 1]);
        $doneColumn = BoardColumn::factory()->create(['board_id' => $board->id, 'name' => 'Done', 'position' => 2]);
        Sanctum::actingAs($owner);

        $task = Task::factory()->create([
            'board_id' => $board->id,
            'column_id' => $todoColumn->id,
            'created_by' => $owner->id,
        ]);

        // Tighten the workflow: only To Do → In Progress
        $project->transitions()->delete();
        $project->transitions()->create([
            'from_status_id' => $todoColumn->status_id,
            'to_status_id' => $progressColumn->status_id,
        ]);

        $blocked = $this->postJson("/api/tasks/{$task->id}/move", [
            'column_id' => $doneColumn->id,
            'position' => 0,
        ]);
        $blocked->assertStatus(422);
        $this->assertContains($progressColumn->status_id, $blocked->json('allowed_status_ids'));
        $this->assertSame($todoColumn->id, $task->fresh()->column_id);

        $this->postJson("/api/tasks/{$task->id}/move", [
            'column_id' => $progressColumn->id,
            'position' => 0,
        ])->assertStatus(200);
    }

    public function test_transition_endpoint_moves_task_to_mapped_column(): void
    {
        [$owner, $board, $todoColumn, $project] = $this->makeProjectBoard();
        $doneColumn = BoardColumn::factory()->create(['board_id' => $board->id, 'name' => 'Done', 'position' => 2]);
        Sanctum::actingAs($owner);

        $task = Task::factory()->create([
            'board_id' => $board->id,
            'column_id' => $todoColumn->id,
            'created_by' => $owner->id,
        ]);

        $transition = $project->transitions()->where('to_status_id', $doneColumn->status_id)->firstOrFail();

        $this->postJson("/api/tasks/{$task->id}/transition", [
            'transition_id' => $transition->id,
        ])->assertStatus(200)
            ->assertJsonPath('data.status_id', $doneColumn->status_id)
            ->assertJsonPath('data.column_id', $doneColumn->id);
    }

    public function test_role_restricted_transition_forbidden_for_member(): void
    {
        [$owner, $board, $todoColumn, $project] = $this->makeProjectBoard();
        $member = User::factory()->create();
        $project->team->addMember($member, 'member');
        $doneColumn = BoardColumn::factory()->create(['board_id' => $board->id, 'name' => 'Done', 'position' => 2]);

        $task = Task::factory()->create([
            'board_id' => $board->id,
            'column_id' => $todoColumn->id,
            'created_by' => $owner->id,
        ]);

        // Replace open workflow with an admin-only transition to Done
        $project->transitions()->delete();
        $adminOnly = $project->transitions()->create([
            'from_status_id' => $todoColumn->status_id,
            'to_status_id' => $doneColumn->status_id,
            'allowed_roles' => ['owner', 'admin'],
        ]);

        Sanctum::actingAs($member);
        $this->getJson("/api/tasks/{$task->id}/transitions")
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
        $this->postJson("/api/tasks/{$task->id}/transition", ['transition_id' => $adminOnly->id])
            ->assertStatus(403);

        Sanctum::actingAs($owner);
        $this->getJson("/api/tasks/{$task->id}/transitions")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
        $this->postJson("/api/tasks/{$task->id}/transition", ['transition_id' => $adminOnly->id])
            ->assertStatus(200);
    }

    public function test_member_cannot_manage_workflow_but_admin_can(): void
    {
        [, , , $project] = $this->makeProjectBoard();
        $member = User::factory()->create();
        $admin = User::factory()->create();
        $project->team->addMember($member, 'member');
        $project->team->addMember($admin, 'admin');

        Sanctum::actingAs($member);
        $this->postJson("/api/projects/{$project->id}/statuses", [
            'name' => 'Blocked',
            'category' => 'in_progress',
        ])->assertStatus(403);

        Sanctum::actingAs($admin);
        $this->postJson("/api/projects/{$project->id}/statuses", [
            'name' => 'Blocked',
            'category' => 'in_progress',
        ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Blocked');
    }

    public function test_delete_status_requires_target_and_remaps(): void
    {
        [$owner, $board, $todoColumn, $project] = $this->makeProjectBoard();
        $progressColumn = BoardColumn::factory()->create(['board_id' => $board->id, 'name' => 'In Progress', 'position' => 1]);
        Sanctum::actingAs($owner);

        $task = Task::factory()->create([
            'board_id' => $board->id,
            'column_id' => $progressColumn->id,
            'created_by' => $owner->id,
        ]);
        $progressStatusId = $progressColumn->status_id;
        $todoStatusId = $todoColumn->status_id;

        // Missing target → validation error
        $this->deleteJson("/api/statuses/{$progressStatusId}")->assertStatus(422);

        $this->deleteJson("/api/statuses/{$progressStatusId}", [
            'move_to_status_id' => $todoStatusId,
        ])->assertStatus(200);

        $this->assertSame($todoStatusId, $task->fresh()->status_id);
        $this->assertSame($todoStatusId, $progressColumn->fresh()->status_id);
        $this->assertDatabaseMissing('statuses', ['id' => $progressStatusId]);
    }

    public function test_cross_project_transition_rejected(): void
    {
        [$owner, $board, $todoColumn] = $this->makeProjectBoard();
        $otherProject = Project::factory()->create();
        $foreignTransition = $otherProject->transitions()->firstOrFail();
        Sanctum::actingAs($owner);

        $task = Task::factory()->create([
            'board_id' => $board->id,
            'column_id' => $todoColumn->id,
            'created_by' => $owner->id,
        ]);

        $this->postJson("/api/tasks/{$task->id}/transition", [
            'transition_id' => $foreignTransition->id,
        ])->assertStatus(422);
    }

    public function test_viewer_cannot_transition(): void
    {
        [$owner, $board, $todoColumn, $project] = $this->makeProjectBoard();
        $viewer = User::factory()->create();
        $project->team->addMember($viewer, 'viewer');
        $doneColumn = BoardColumn::factory()->create(['board_id' => $board->id, 'name' => 'Done', 'position' => 2]);

        $task = Task::factory()->create([
            'board_id' => $board->id,
            'column_id' => $todoColumn->id,
            'created_by' => $owner->id,
        ]);
        $transition = $project->transitions()->where('to_status_id', $doneColumn->status_id)->firstOrFail();

        Sanctum::actingAs($viewer);
        $this->postJson("/api/tasks/{$task->id}/transition", [
            'transition_id' => $transition->id,
        ])->assertStatus(403);
    }

    /**
     * Owner + team + project (with seeded workflow) + board + "To Do" column.
     *
     * @return array{0: User, 1: Board, 2: BoardColumn, 3: Project}
     */
    private function makeProjectBoard(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $project = Project::factory()->forTeam($team)->create();
        $board = Board::factory()->create([
            'team_id' => $team->id,
            'created_by' => $owner->id,
            'project_id' => $project->id,
        ]);
        $column = BoardColumn::factory()->create(['board_id' => $board->id, 'name' => 'To Do', 'position' => 0]);

        return [$owner, $board, $column, $project];
    }
}
