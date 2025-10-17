<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('afip_status', ['draft', 'authorized', 'rejected', 'manual'])
                ->default('draft')
                ->after('status')
                ->comment('draft=sin autorizar, authorized=autorizado por AFIP, rejected=rechazado, manual=factura manual sin AFIP');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('afip_status');
        });
    }
};
