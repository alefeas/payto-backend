<?php

namespace App\Console\Commands;

use App\Models\PendingRegistration;
use Illuminate\Console\Command;

class CleanExpiredPendingRegistrations extends Command
{
    protected $signature = 'auth:clean-expired-registrations';
    protected $description = 'Delete expired pending registrations from the database';

    public function handle()
    {
        $deleted = PendingRegistration::where('expires_at', '<', now())
            ->delete();

        $this->info("Deleted {$deleted} expired pending registrations.");
        
        return Command::SUCCESS;
    }
}
