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
            $table->text('withholding_iibb_notes')->nullable()->after('withholding_iibb');
            $table->text('withholding_iva_notes')->nullable()->after('withholding_iva');
            $table->text('withholding_ganancias_notes')->nullable()->after('withholding_ganancias');
            $table->text('withholding_suss_notes')->nullable()->after('withholding_suss');
            $table->text('withholding_other_notes')->nullable()->after('withholding_other');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_collections', function (Blueprint $table) {
            $table->dropColumn([
                'withholding_iibb_notes',
                'withholding_iva_notes',
                'withholding_ganancias_notes',
                'withholding_suss_notes',
                'withholding_other_notes',
            ]);
        });
    }
};
