<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->insertOrIgnore([
            'id' => Str::uuid(),
            'email' => 'test@payto.com',
            'password' => bcrypt('Test123456'),
            'first_name' => 'Test',
            'last_name' => 'User',
            'phone' => '+5491123456789',
            'email_verified' => true,
            'country' => 'Argentina',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('users')->where('email', 'test@payto.com')->delete();
    }
};
