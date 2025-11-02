<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Drop old constraint
            $table->dropUnique('invoices_unique_constraint');
            
            // Add new constraint including receiver
            $table->unique(['issuer_company_id', 'receiver_company_id', 'type', 'sales_point', 'voucher_number'], 'invoices_unique_constraint');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Revert to old constraint
            $table->dropUnique('invoices_unique_constraint');
            $table->unique(['issuer_company_id', 'type', 'sales_point', 'voucher_number'], 'invoices_unique_constraint');
        });
    }
};
