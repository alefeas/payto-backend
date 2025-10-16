<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Eliminar todas las restricciones únicas existentes
            try {
                $table->dropUnique('invoices_unique_number');
            } catch (\Exception $e) {
                // Ignorar si no existe
            }
            
            try {
                $table->dropUnique(['issuer_company_id', 'sales_point', 'voucher_number']);
            } catch (\Exception $e) {
                // Ignorar si no existe
            }
            
            try {
                $table->dropUnique(['issuer_company_id', 'supplier_id', 'sales_point', 'voucher_number']);
            } catch (\Exception $e) {
                // Ignorar si no existe
            }
        });
        
        // Agregar índice compuesto pero NO único
        // Esto mejora el rendimiento sin impedir duplicados
        DB::statement('CREATE INDEX invoices_number_lookup ON invoices (issuer_company_id, supplier_id, sales_point, voucher_number)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX invoices_number_lookup ON invoices');
    }
};
