<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateTestingUserSeeder extends Seeder
{
    public function run(): void
    {
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
}
