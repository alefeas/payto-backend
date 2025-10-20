<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $mapping = [
            'iva' => 'vat_retention',
            'ganancias' => 'income_tax_retention',
            'suss' => 'suss_retention',
            'iibb_bsas' => 'gross_income_buenosaires',
            'iibb_caba' => 'gross_income_caba',
            'iibb_catamarca' => 'gross_income_catamarca',
            'iibb_chaco' => 'gross_income_chaco',
            'iibb_chubut' => 'gross_income_chubut',
            'iibb_cordoba' => 'gross_income_cordoba',
            'iibb_corrientes' => 'gross_income_corrientes',
            'iibb_entrerios' => 'gross_income_entrerios',
            'iibb_formosa' => 'gross_income_formosa',
            'iibb_jujuy' => 'gross_income_jujuy',
            'iibb_lapampa' => 'gross_income_lapampa',
            'iibb_larioja' => 'gross_income_larioja',
            'iibb_mendoza' => 'gross_income_mendoza',
            'iibb_misiones' => 'gross_income_misiones',
            'iibb_neuquen' => 'gross_income_neuquen',
            'iibb_rionegro' => 'gross_income_rionegro',
            'iibb_salta' => 'gross_income_salta',
            'iibb_sanjuan' => 'gross_income_sanjuan',
            'iibb_sanluis' => 'gross_income_sanluis',
            'iibb_santacruz' => 'gross_income_santacruz',
            'iibb_santafe' => 'gross_income_santafe',
            'iibb_sgo_estero' => 'gross_income_santiagodelestero',
            'iibb_tdf' => 'gross_income_tierradelfuego',
            'iibb_tucuman' => 'gross_income_tucuman',
            'custom' => 'other',
            'vat' => 'vat_retention',
            'income_tax' => 'income_tax_retention',
            'gross_income_retention' => 'gross_income_buenosaires',
        ];

        foreach ($mapping as $oldValue => $newValue) {
            DB::table('payment_retentions')
                ->where('type', $oldValue)
                ->update(['type' => $newValue]);
        }
    }

    public function down(): void
    {
        // No rollback
    }
};
