<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Update existing roles to new system
        DB::table('company_members')->where('role', 'financial_director')->update(['role' => 'operator']);
        DB::table('company_members')->where('role', 'accountant')->update(['role' => 'operator']);
        DB::table('company_members')->where('role', 'approver')->update(['role' => 'operator']);
        
        // Modify enum to include all new roles
        DB::statement("ALTER TABLE company_members MODIFY COLUMN role ENUM('administrator', 'financial_director', 'accountant', 'approver', 'operator') NOT NULL");
    }

    public function down(): void
    {
        // Revert to old enum
        DB::statement("ALTER TABLE company_members MODIFY COLUMN role ENUM('administrator', 'financial_director', 'accountant', 'approver', 'operator') NOT NULL");
    }
};
