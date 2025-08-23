<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Team;

class TeamTestSeeder extends Seeder
{
    public function run(): void
    {
        // Find or create viewer user
        $viewer = User::firstOrCreate(
            ['email' => 'viewer@example.com'],
            [
                'name' => 'Test Viewer',
                'password' => bcrypt('password'),
                'role' => 'member'
            ]
        );

        // Find or create member user
        $member = User::firstOrCreate(
            ['email' => 'member@example.com'],
            [
                'name' => 'Test Member',
                'password' => bcrypt('password'),
                'role' => 'member'
            ]
        );

        // Get the existing team
        $team = Team::first();
        
        if ($team) {
            // Add viewer with viewer role
            $team->addMember($viewer, 'viewer');
            echo "Added {$viewer->name} as viewer to team {$team->name}\n";
            
            // Add regular member
            $team->addMember($member, 'member');
            echo "Added {$member->name} as member to team {$team->name}\n";
        }
    }
}
