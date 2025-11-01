<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE pending_registrations MODIFY expires_at TIMESTAMP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pending_registrations MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }
};
