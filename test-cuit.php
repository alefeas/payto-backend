<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\CuitValidatorService;

// Test CUITs
$testCuits = [
    '00000000000',
    '20267565393',
    '27123456784',
    '20123456789',
];

foreach ($testCuits as $cuit) {
    $isValid = CuitValidatorService::isValid($cuit);
    echo "CUIT: $cuit - " . ($isValid ? 'VÁLIDO' : 'INVÁLIDO') . "\n";
}

// Check if there are any invoices with clients
$invoices = \App\Models\Invoice::whereNotNull('client_id')
    ->with('client')
    ->take(5)
    ->get();

echo "\n--- Facturas con clientes ---\n";
foreach ($invoices as $invoice) {
    $cuit = $invoice->client ? $invoice->client->document_number : 'N/A';
    $isValid = $cuit !== 'N/A' ? CuitValidatorService::isValid($cuit) : false;
    echo "Invoice {$invoice->id}: CUIT {$cuit} - " . ($isValid ? 'VÁLIDO' : 'INVÁLIDO') . "\n";
}
