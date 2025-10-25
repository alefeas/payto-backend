<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Verificando duplicados en la tabla companies...\n";

$duplicates = DB::table('companies')
    ->select('national_id', DB::raw('COUNT(*) as count'))
    ->groupBy('national_id')
    ->having('count', '>', 1)
    ->get();

if ($duplicates->isEmpty()) {
    echo "No se encontraron duplicados.\n";
} else {
    echo "Duplicados encontrados:\n";
    foreach ($duplicates as $duplicate) {
        echo "CUIT: {$duplicate->national_id} - Count: {$duplicate->count}\n";
        
        // Mostrar detalles de las empresas duplicadas
        $companies = DB::table('companies')
            ->where('national_id', $duplicate->national_id)
            ->get(['id', 'name', 'business_name', 'created_at']);
            
        foreach ($companies as $company) {
            echo "  ID: {$company->id}, Name: {$company->name}, Business: {$company->business_name}, Created: {$company->created_at}\n";
        }
        echo "\n";
    }
}