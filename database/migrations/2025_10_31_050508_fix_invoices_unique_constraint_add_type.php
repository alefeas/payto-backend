<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Eliminar constraint único viejo
            $table->dropUnique('invoices_issuer_company_id_sales_point_voucher_number_unique');
            
            // Agregar nuevo constraint único que incluye type
            $table->unique(['issuer_company_id', 'type', 'sales_point', 'voucher_number'], 'invoices_unique_constraint');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Revertir al constraint viejo
            $table->dropUnique('invoices_unique_constraint');
            $table->unique(['issuer_company_id', 'sales_point', 'voucher_number'], 'invoices_issuer_company_id_sales_point_voucher_number_unique');
        });
    }
};
