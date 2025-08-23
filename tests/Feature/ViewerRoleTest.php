<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Team;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Task;

class ViewerRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_access_board_but_not_edit_tasks()
    {
        // Create users
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        
        // Create team and add viewer
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addMember($viewer, 'viewer');
        
        // Create board and column
        $board = Board::factory()->create(['team_id' => $team->id, 'created_by' => $owner->id]);
        $column = BoardColumn::factory()->create(['board_id' => $board->id]);
        
        // Create task
        $task = Task::factory()->create([
            'board_id' => $board->id,
            'column_id' => $column->id,
            'created_by' => $owner->id
        ]);
        
        // Test viewer can access board
        $this->assertTrue($board->canUserAccess($viewer));
        
        // Test viewer cannot edit tasks
        $this->assertFalse($board->canUserEditTasks($viewer));
        
        // Test viewer cannot create tasks
        $this->assertFalse($board->canUserCreateTasks($viewer));
        
        // Test viewer cannot manage board
        $this->assertFalse($board->canUserManage($viewer));
        
        // Test viewer is identified correctly
        $this->assertTrue($board->isUserViewer($viewer));
        $this->assertEquals('viewer', $board->getUserRole($viewer));
    }

    public function test_member_can_edit_tasks_but_viewer_cannot()
    {
        // Create users
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $viewer = User::factory()->create();
        
        // Create team with different roles
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addMember($member, 'member');
        $team->addMember($viewer, 'viewer');
        
        // Create board
        $board = Board::factory()->create(['team_id' => $team->id, 'created_by' => $owner->id]);
        
        // Test member can edit tasks
        $this->assertTrue($board->canUserEditTasks($member));
        $this->assertTrue($board->canUserCreateTasks($member));
        
        // Test viewer cannot edit tasks
        $this->assertFalse($board->canUserEditTasks($viewer));
        $this->assertFalse($board->canUserCreateTasks($viewer));
        
        // Both can access the board
        $this->assertTrue($board->canUserAccess($member));
        $this->assertTrue($board->canUserAccess($viewer));
    }

    public function test_team_role_methods()
    {
        // Create users
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $viewer = User::factory()->create();
        
        // Create team with different roles
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addMember($admin, 'admin');
        $team->addMember($member, 'member');
        $team->addMember($viewer, 'viewer');
        
        // Test role detection methods
        $this->assertTrue($team->isAdmin($admin));
        $this->assertFalse($team->isViewer($admin));
        
        $this->assertTrue($team->isMember($member));
        $this->assertFalse($team->isViewer($member));
        
        $this->assertTrue($team->isViewer($viewer));
        $this->assertTrue($team->isMember($viewer)); // Viewer is still a member
        
        // Test permission methods
        $this->assertTrue($team->canEditTasks($admin));
        $this->assertTrue($team->canEditTasks($member));
        $this->assertFalse($team->canEditTasks($viewer));
        
        $this->assertTrue($team->canManageMembers($admin));
        $this->assertFalse($team->canManageMembers($member));
        $this->assertFalse($team->canManageMembers($viewer));
        
        // Test role strings
        $this->assertEquals('owner', $team->getUserRole($owner));
        $this->assertEquals('admin', $team->getUserRole($admin));
        $this->assertEquals('member', $team->getUserRole($member));
        $this->assertEquals('viewer', $team->getUserRole($viewer));
    }
}
