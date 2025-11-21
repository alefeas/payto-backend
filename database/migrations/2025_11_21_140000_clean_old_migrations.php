<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Delete the old broken migration from the migrations table
        DB::table('migrations')->where('migration', 'like', '%insert_testing_user%')->delete();
    }

    public function down(): void
    {
        // Nothing to do
    }
};
