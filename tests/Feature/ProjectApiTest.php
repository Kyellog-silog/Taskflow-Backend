<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Label;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_personal_project(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/projects', [
            'name' => 'My Project',
            'key' => 'MP',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.key', 'MP')
            ->assertJsonPath('data.lead_user_id', $user->id);

        $this->assertDatabaseHas('projects', ['key' => 'MP', 'lead_user_id' => $user->id]);
    }

    public function test_project_key_must_be_uppercase_format(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/projects', ['name' => 'Bad', 'key' => 'lower'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);

        $this->postJson('/api/projects', ['name' => 'Bad', 'key' => '1AB'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    public function test_duplicate_project_key_is_rejected(): void
    {
        $user = User::factory()->create();
        Project::factory()->create(['key' => 'DUP']);
        Sanctum::actingAs($user);

        $this->postJson('/api/projects', ['name' => 'Dup', 'key' => 'DUP'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    public function test_non_member_cannot_view_project(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/projects/{$project->id}")->assertStatus(403);
    }

    public function test_team_member_can_view_team_project(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addMember($member, 'member');
        $project = Project::factory()->forTeam($team)->create();

        Sanctum::actingAs($member);

        $this->getJson("/api/projects/{$project->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $project->id);
    }

    public function test_non_member_cannot_create_project_in_team(): void
    {
        $team = Team::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/projects', ['name' => 'Nope', 'key' => 'NOPE', 'team_id' => $team->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['team_id']);
    }

    public function test_tasks_get_sequential_issue_keys(): void
    {
        [$owner, $board, $column, $project] = $this->makeTeamBoard();
        Sanctum::actingAs($owner);

        $first = $this->postJson('/api/tasks', [
            'title' => 'First task',
            'board_id' => $board->id,
            'column_id' => $column->id,
        ]);
        $second = $this->postJson('/api/tasks', [
            'title' => 'Second task',
            'board_id' => $board->id,
            'column_id' => $column->id,
        ]);

        $first->assertStatus(201)->assertJsonPath('data.issue_key', $project->key.'-1');
        $second->assertStatus(201)->assertJsonPath('data.issue_key', $project->key.'-2');
    }

    public function test_issue_key_is_immutable_via_update(): void
    {
        [$owner, $board, $column] = $this->makeTeamBoard();
        Sanctum::actingAs($owner);

        $task = $this->postJson('/api/tasks', [
            'title' => 'Task',
            'board_id' => $board->id,
            'column_id' => $column->id,
        ])->json('data');

        $this->putJson("/api/tasks/{$task['id']}", [
            'title' => 'Renamed',
            'issue_key' => 'HACK-999',
        ])->assertStatus(200);

        $this->assertDatabaseHas('tasks', ['id' => $task['id'], 'issue_key' => $task['issue_key']]);
    }

    public function test_issue_resolves_by_key(): void
    {
        [$owner, $board, $column] = $this->makeTeamBoard();
        Sanctum::actingAs($owner);

        $task = $this->postJson('/api/tasks', [
            'title' => 'Deep link me',
            'board_id' => $board->id,
            'column_id' => $column->id,
        ])->json('data');

        $this->getJson("/api/issues/{$task['issue_key']}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $task['id'])
            ->assertJsonPath('data.issue_key', $task['issue_key']);
    }

    public function test_epic_reference_must_be_epic_type(): void
    {
        [$owner, $board, $column] = $this->makeTeamBoard();
        Sanctum::actingAs($owner);

        $notAnEpic = $this->postJson('/api/tasks', [
            'title' => 'Plain task',
            'board_id' => $board->id,
            'column_id' => $column->id,
        ])->json('data');

        $this->postJson('/api/tasks', [
            'title' => 'Child',
            'board_id' => $board->id,
            'column_id' => $column->id,
            'epic_id' => $notAnEpic['id'],
        ])->assertStatus(422);
    }

    public function test_labels_must_belong_to_same_project(): void
    {
        [$owner, $board, $column] = $this->makeTeamBoard();
        $foreignLabel = Label::factory()->create(); // different project
        Sanctum::actingAs($owner);

        $this->postJson('/api/tasks', [
            'title' => 'Labelled',
            'board_id' => $board->id,
            'column_id' => $column->id,
            'labels' => [$foreignLabel->id],
        ])->assertStatus(422);
    }

    public function test_viewer_cannot_create_label(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addMember($viewer, 'viewer');
        $project = Project::factory()->forTeam($team)->create();

        Sanctum::actingAs($viewer);
        $this->postJson("/api/projects/{$project->id}/labels", ['name' => 'bug'])
            ->assertStatus(403);

        Sanctum::actingAs($owner);
        $this->postJson("/api/projects/{$project->id}/labels", ['name' => 'bug', 'color' => '#FF0000'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'bug');
    }

    public function test_board_creation_attaches_default_project(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);

        $board = Board::factory()->create(['team_id' => $team->id, 'created_by' => $owner->id]);

        $this->assertNotNull($board->project_id);
        $this->assertDatabaseHas('projects', ['id' => $board->project_id, 'team_id' => $team->id]);
    }

    public function test_viewer_cannot_delete_project_but_admin_can(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addMember($viewer, 'viewer');
        $project = Project::factory()->forTeam($team)->create();

        Sanctum::actingAs($viewer);
        $this->deleteJson("/api/projects/{$project->id}")->assertStatus(403);

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/projects/{$project->id}")->assertStatus(200);
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }

    /**
     * Team + project + board + column owned by a fresh user.
     *
     * @return array{0: User, 1: Board, 2: BoardColumn, 3: Project}
     */
    private function makeTeamBoard(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $project = Project::factory()->forTeam($team)->create();
        $board = Board::factory()->create([
            'team_id' => $team->id,
            'created_by' => $owner->id,
            'project_id' => $project->id,
        ]);
        $column = BoardColumn::factory()->create(['board_id' => $board->id]);

        return [$owner, $board, $column, $project];
    }
}
