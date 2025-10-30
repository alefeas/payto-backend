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
        Schema::table('invoice_collections', function (Blueprint $table) {
            $table->decimal('withholding_iibb', 15, 2)->default(0)->after('amount');
            $table->decimal('withholding_iva', 15, 2)->default(0)->after('withholding_iibb');
            $table->decimal('withholding_ganancias', 15, 2)->default(0)->after('withholding_iva');
            $table->decimal('withholding_suss', 15, 2)->default(0)->after('withholding_ganancias');
            $table->decimal('withholding_other', 15, 2)->default(0)->after('withholding_suss');
            $table->text('withholding_notes')->nullable()->after('withholding_other');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_collections', function (Blueprint $table) {
            $table->dropColumn(['withholding_iibb', 'withholding_iva', 'withholding_ganancias', 'withholding_suss', 'withholding_other', 'withholding_notes']);
        });
    }
};
