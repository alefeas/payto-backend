<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Update auto_retentions JSON field in companies table
        $companies = DB::table('companies')->whereNotNull('auto_retentions')->get();
        
        foreach ($companies as $company) {
            $autoRetentions = json_decode($company->auto_retentions, true);
            if (!is_array($autoRetentions)) continue;
            
            $updated = false;
            foreach ($autoRetentions as &$retention) {
                if (isset($retention['type'])) {
                    $oldType = $retention['type'];
                    $retention['type'] = $this->mapToEnglish($oldType);
                    if ($oldType !== $retention['type']) {
                        $updated = true;
                    }
                }
            }
            
            if ($updated) {
                DB::table('companies')
                    ->where('id', $company->id)
                    ->update(['auto_retentions' => json_encode($autoRetentions)]);
            }
        }
        
        // Update auto_perceptions JSON field in companies table
        $companies = DB::table('companies')->whereNotNull('auto_perceptions')->get();
        
        foreach ($companies as $company) {
            $autoPerceptions = json_decode($company->auto_perceptions, true);
            if (!is_array($autoPerceptions)) continue;
            
            $updated = false;
            foreach ($autoPerceptions as &$perception) {
                if (isset($perception['type'])) {
                    $oldType = $perception['type'];
                    $perception['type'] = $this->mapToEnglish($oldType);
                    if ($oldType !== $perception['type']) {
                        $updated = true;
                    }
                }
            }
            
            if ($updated) {
                DB::table('companies')
                    ->where('id', $company->id)
                    ->update(['auto_perceptions' => json_encode($autoPerceptions)]);
            }
        }
    }

    public function down(): void
    {
        // No rollback needed
    }
    
    private function mapToEnglish(string $type): string
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
        ];
        
        return $mapping[$type] ?? $type;
    }
};
