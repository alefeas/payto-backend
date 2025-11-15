<?php

/**
 * Script para corregir exchange_rates incorrectos en facturas
 * Las facturas sincronizadas de AFIP tienen exchange_rate dividido por 10000 incorrectamente
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

echo "🔧 Corrigiendo exchange_rates incorrectos...\n\n";

// Buscar facturas con moneda extranjera y exchange_rate < 1
$invoices = Invoice::whereNotNull('currency')
    ->where('currency', '!=', 'ARS')
    ->where('exchange_rate', '<', 1)
    ->where('exchange_rate', '>', 0)
    ->get();

echo "📊 Encontradas " . $invoices->count() . " facturas con exchange_rate incorrecto\n\n";

$fixed = 0;
foreach ($invoices as $invoice) {
    $oldRate = $invoice->exchange_rate;
    
    // Si el exchange_rate es menor a 1, probablemente fue dividido por 10000 incorrectamente
    // Multiplicar por 10000 para corregir
    $newRate = $oldRate * 10000;
    
    echo "📝 Factura {$invoice->number} ({$invoice->currency}):\n";
    echo "   Anterior: {$oldRate}\n";
    echo "   Nuevo: {$newRate}\n\n";
    
    $invoice->exchange_rate = $newRate;
    $invoice->save();
    
    $fixed++;
}

echo "\n✅ Corregidas {$fixed} facturas\n";
