<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE payment_retentions MODIFY COLUMN type ENUM(
            'vat_retention',
            'income_tax_retention',
            'gross_income_retention',
            'social_security_retention',
            'suss_retention',
            'gross_income_buenosaires',
            'gross_income_caba',
            'gross_income_catamarca',
            'gross_income_chaco',
            'gross_income_chubut',
            'gross_income_cordoba',
            'gross_income_corrientes',
            'gross_income_entrerios',
            'gross_income_formosa',
            'gross_income_jujuy',
            'gross_income_lapampa',
            'gross_income_larioja',
            'gross_income_mendoza',
            'gross_income_misiones',
            'gross_income_neuquen',
            'gross_income_rionegro',
            'gross_income_salta',
            'gross_income_sanjuan',
            'gross_income_sanluis',
            'gross_income_santacruz',
            'gross_income_santafe',
            'gross_income_santiagodelestero',
            'gross_income_tierradelfuego',
            'gross_income_tucuman',
            'other'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE payment_retentions MODIFY COLUMN type ENUM('vat_retention','income_tax_retention','gross_income_retention','social_security_retention','suss_retention') NOT NULL");
    }
};
