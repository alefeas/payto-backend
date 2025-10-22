<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE `invoice_collections` MODIFY `collection_method` ENUM('transfer', 'check', 'cash', 'card', 'debit_card', 'credit_card', 'other') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE `invoice_collections` MODIFY `collection_method` ENUM('transfer', 'check', 'cash', 'card') NOT NULL");
    }
};
