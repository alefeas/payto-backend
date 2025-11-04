<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule notification jobs
Schedule::job(new \App\Jobs\SendInvoiceDueRemindersJob())->dailyAt('09:00');
Schedule::job(new \App\Jobs\SendPaymentRemindersJob())->dailyAt('10:00');

// Schedule FCE acceptance checks (existing job) - this job requires an invoice ID, so we need to handle it differently
// For now, let's comment it out since it needs specific invoice IDs
// Schedule::job(new \App\Jobs\CheckFCEAcceptanceJob(1))->everyThirtyMinutes();
