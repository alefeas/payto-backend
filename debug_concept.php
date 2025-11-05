<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/bootstrap/app.php';

use App\Models\Invoice;

echo "=== DEBUG CONCEPT FIELD ===\n\n";

// Verificar algunas facturas con concepto no nulo
$invoicesWithConcept = Invoice::whereNotNull('concept')
    ->limit(5)
    ->get(['id', 'number', 'concept', 'type', 'sales_point']);

echo "Facturas con concepto NO NULO:\n";
foreach ($invoicesWithConcept as $invoice) {
    echo "ID: {$invoice->id}, Number: {$invoice->number}, Concept: {$invoice->concept}, Type: {$invoice->type}, Sales Point: {$invoice->sales_point}\n";
}

// Verificar algunas facturas con concepto nulo
$invoicesWithoutConcept = Invoice::whereNull('concept')
    ->limit(5)
    ->get(['id', 'number', 'concept', 'type', 'sales_point']);

echo "\nFacturas con concepto NULO:\n";
foreach ($invoicesWithoutConcept as $invoice) {
    echo "ID: {$invoice->id}, Number: {$invoice->number}, Concept: " . ($invoice->concept ?? 'NULL') . ", Type: {$invoice->type}, Sales Point: {$invoice->sales_point}\n";
}

// Estadísticas
echo "\n=== ESTADÍSTICAS ===\n";
$totalInvoices = Invoice::count();
$withConcept = Invoice::whereNotNull('concept')->count();
$withoutConcept = Invoice::whereNull('concept')->count();

echo "Total de facturas: {$totalInvoices}\n";
echo "Con concepto: {$withConcept}\n";
echo "Sin concepto (NULL): {$withoutConcept}\n";
echo "Porcentaje con concepto: " . round(($withConcept / $totalInvoices) * 100, 2) . "%\n";

// Verificar valores únicos de concepto
echo "\n=== VALORES ÚNICOS DE CONCEPTO ===\n";
$uniqueConcepts = Invoice::whereNotNull('concept')
    ->distinct()
    ->pluck('concept');

foreach ($uniqueConcepts as $concept) {
    $count = Invoice::where('concept', $concept)->count();
    echo "Concept '{$concept}': {$count} facturas\n";
}