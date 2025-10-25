<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('pending_approval','issued','approved','rejected','in_dispute','correcting','paid','collected','overdue','cancelled','partially_cancelled') NOT NULL DEFAULT 'pending_approval'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('pending_approval','issued','approved','rejected','in_dispute','correcting','paid','overdue','cancelled','partially_cancelled') NOT NULL DEFAULT 'pending_approval'");
    }
};
