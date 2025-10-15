<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoice_items MODIFY id CHAR(36) NOT NULL');
    }

    public function down(): void
    {
        // No rollback needed
    }
};
