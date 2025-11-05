<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Asegurar que expires_at NO se actualiza automáticamente al modificar otros campos
        DB::statement('ALTER TABLE pending_registrations MODIFY expires_at DATETIME NOT NULL');
    }

    public function down(): void
    {
        // Volver a TIMESTAMP estándar (sin ON UPDATE implícito)
        DB::statement('ALTER TABLE pending_registrations MODIFY expires_at TIMESTAMP NOT NULL');
    }
};