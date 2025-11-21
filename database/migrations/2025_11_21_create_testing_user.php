<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Insert testing user for portfolio demonstration
        DB::table('users')->updateOrInsert(
            ['email' => 'test@payto.com'],
            [
                'id' => Str::uuid(),
                'email' => 'test@payto.com',
                'password' => '$2y$12$G8tNTs7UYewxSzBCifGmjeoNpdsjfJxKLyu1bHuVeJwogyOT.aU6W',
                'first_name' => 'Test',
                'last_name' => 'Account',
                'email_verified' => true,
                'country' => 'Argentina',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')->where('email', 'test@payto.com')->delete();
    }
};
