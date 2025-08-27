<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Team;
use App\Models\Board;
use App\Models\BoardColumn;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * 
     * NOTE: This seeder should only be used in development/testing environments.
     * Production data should be created through the application interface.
     */
    public function run(): void
    {
        // Only seed in development/testing environments
        if (app()->environment(['production'])) {
            $this->command->info('Skipping seeder in production environment.');
            return;
        }

        // Create main test user (team owner)
        $admin = User::factory()->create([
            'name' => 'Test Admin',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        // Create additional users for testing roles
        $member = User::factory()->create([
            'name' => 'Test Member',
            'email' => 'member@example.com',
            'password' => bcrypt('password'),
        ]);

        $viewer = User::factory()->create([
            'name' => 'Test Viewer',
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
        ]);

        // Create a team
        $team = Team::create([
            'name' => 'Demo Team',
            'description' => 'A demo team to test role-based permissions',
            'owner_id' => $admin->id,
        ]);

        // Add team members with different roles
        $team->addMember($admin, 'admin');
        $team->addMember($member, 'member');
        $team->addMember($viewer, 'viewer');

        // Create a board for the team
        $board = Board::create([
            'name' => 'Demo Board',
            'description' => 'A demo board to test team collaboration',
            'team_id' => $team->id,
            'created_by' => $admin->id,
        ]);

        // Create default columns
        $columns = [
            ['name' => 'To Do', 'color' => '#ef4444', 'position' => 0],
            ['name' => 'In Progress', 'color' => '#f59e0b', 'position' => 1],
            ['name' => 'Review', 'color' => '#3b82f6', 'position' => 2],
            ['name' => 'Done', 'color' => '#10b981', 'position' => 3],
        ];

        foreach ($columns as $columnData) {
            BoardColumn::create(array_merge($columnData, [
                'board_id' => $board->id,
            ]));
        }

        $this->command->info('Demo team created with:');
        $this->command->info("- Admin: {$admin->email} (password: password)");
        $this->command->info("- Member: {$member->email} (password: password)");
        $this->command->info("- Viewer: {$viewer->email} (password: password)");
        $this->command->info("Team: {$team->name} (ID: {$team->id})");
        $this->command->info("Board: {$board->name} (ID: {$board->id})");
    }
}
