<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

// Simulate request
$request = \Illuminate\Http\Request::create(
    '/api/v1/companies/0199ef90-c616-7348-87b2-78adddc56e25/iva-book/export/sales?month=10&year=2025',
    'GET'
);

try {
    $controller = new \App\Http\Controllers\Api\IvaBookController();
    $response = $controller->exportSalesAfip($request, '0199ef90-c616-7348-87b2-78adddc56e25');
    
    echo "Status: " . $response->getStatusCode() . "\n";
    echo "Content: " . substr($response->getContent(), 0, 500) . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
