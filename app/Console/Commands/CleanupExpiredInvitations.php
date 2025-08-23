<?php

namespace App\Console\Commands;

use App\Models\TeamInvitation;
use Illuminate\Console\Command;

class CleanupExpiredInvitations extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'invitations:cleanup';

    /**
     * The console command description.
     */
    protected $description = 'Mark expired team invitations as expired';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $expiredCount = TeamInvitation::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        $this->info("Marked {$expiredCount} invitations as expired.");

        return Command::SUCCESS;
    }
}
