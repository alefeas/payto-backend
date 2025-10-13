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
        DB::statement("ALTER TABLE company_members MODIFY COLUMN role ENUM('owner', 'administrator', 'financial_director', 'accountant', 'approver', 'operator') NOT NULL");
        
        // Convertir el primer administrador de cada empresa en owner
        $companies = DB::table('companies')->pluck('id');
        
        foreach ($companies as $companyId) {
            $firstAdmin = DB::table('company_members')
                ->where('company_id', $companyId)
                ->where('role', 'administrator')
                ->orderBy('created_at', 'asc')
                ->first();
            
            if ($firstAdmin) {
                DB::table('company_members')
                    ->where('id', $firstAdmin->id)
                    ->update(['role' => 'owner']);
            }
        }
    }

    public function down(): void
    {
        DB::statement("UPDATE company_members SET role = 'administrator' WHERE role = 'owner'");
        DB::statement("ALTER TABLE company_members MODIFY COLUMN role ENUM('administrator', 'financial_director', 'accountant', 'approver', 'operator') NOT NULL");
    }
};
