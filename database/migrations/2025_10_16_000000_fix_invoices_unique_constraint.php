<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Eliminar la restricción única anterior
            $table->dropUnique(['issuer_company_id', 'sales_point', 'voucher_number']);
            
            // Agregar índice único compuesto que incluya supplier_id
            // Esto permite que diferentes proveedores tengan el mismo número de factura
            $table->unique(['issuer_company_id', 'supplier_id', 'sales_point', 'voucher_number'], 'invoices_unique_number');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_unique_number');
            $table->unique(['issuer_company_id', 'sales_point', 'voucher_number']);
        });
    }
};
