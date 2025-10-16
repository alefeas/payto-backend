<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM(
            'pending_approval',
            'issued',
            'approved',
            'rejected',
            'in_dispute',
            'correcting',
            'paid',
            'overdue',
            'cancelled',
            'partially_cancelled'
        ) NOT NULL DEFAULT 'pending_approval'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM(
            'pending_approval',
            'issued',
            'approved',
            'rejected',
            'in_dispute',
            'correcting',
            'paid',
            'overdue',
            'cancelled'
        ) NOT NULL DEFAULT 'pending_approval'");
    }
};
