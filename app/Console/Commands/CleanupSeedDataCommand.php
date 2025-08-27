<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Team;
use App\Models\Board;
use App\Models\TeamMember;
use App\Models\Task;
use App\Models\BoardColumn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupSeedDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:cleanup-seed-data {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove seeded demo data from production database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!app()->environment(['production', 'staging'])) {
            $this->error('This command is only intended for production/staging environments.');
            return self::FAILURE;
        }

        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('DRY RUN MODE - No data will be deleted');
        } else {
            $this->warn('WARNING: This will permanently delete seeded demo data!');
            if (!$this->confirm('Are you sure you want to continue?')) {
                return self::FAILURE;
            }
        }

        DB::beginTransaction();

        try {
            // Find demo users by email patterns
            $demoUsers = User::whereIn('email', [
                'test@example.com',
                'member@example.com', 
                'viewer@example.com'
            ])->get();

            $this->info("Found {$demoUsers->count()} demo users to remove");

            // Find demo teams
            $demoTeams = Team::where('name', 'Demo Team')
                ->orWhere('description', 'like', '%demo%')
                ->get();

            $this->info("Found {$demoTeams->count()} demo teams to remove");

            // Find demo boards
            $demoBoards = Board::where('name', 'Demo Board')
                ->orWhere('description', 'like', '%demo%')
                ->get();

            $this->info("Found {$demoBoards->count()} demo boards to remove");

            if (!$isDryRun) {
                // Delete in proper order to respect foreign key constraints
                
                // Delete tasks in demo boards
                foreach ($demoBoards as $board) {
                    $taskCount = $board->tasks()->count();
                    if ($taskCount > 0) {
                        $this->info("Deleting {$taskCount} tasks from board: {$board->name}");
                        $board->tasks()->delete();
                    }
                }

                // Delete demo boards
                foreach ($demoBoards as $board) {
                    $this->info("Deleting board: {$board->name}");
                    $board->columns()->delete(); // Delete columns first
                    $board->delete();
                }

                // Delete team memberships
                foreach ($demoTeams as $team) {
                    $memberCount = $team->members()->count();
                    if ($memberCount > 0) {
                        $this->info("Removing {$memberCount} members from team: {$team->name}");
                        $team->members()->detach();
                    }
                }

                // Delete demo teams
                foreach ($demoTeams as $team) {
                    $this->info("Deleting team: {$team->name}");
                    $team->delete();
                }

                // Delete demo users
                foreach ($demoUsers as $user) {
                    $this->info("Deleting user: {$user->email}");
                    $user->delete();
                }

                Log::info('Cleanup seed data command completed successfully', [
                    'users_deleted' => $demoUsers->count(),
                    'teams_deleted' => $demoTeams->count(),
                    'boards_deleted' => $demoBoards->count(),
                ]);

                $this->info('Demo data cleanup completed successfully!');
            }

            DB::commit();
            return self::SUCCESS;

        } catch (\Exception $e) {
            DB::rollback();
            
            $this->error('Error during cleanup: ' . $e->getMessage());
            Log::error('Cleanup seed data command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return self::FAILURE;
        }
    }
}
