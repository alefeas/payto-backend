<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Company;

// Buscar la empresa con CUIT incorrecto
$company = Company::where('national_id', '44444444')->first();

if (!$company) {
    echo "No se encontró empresa con CUIT 44444444\n";
    echo "Empresas existentes:\n";
    foreach (Company::all() as $c) {
        echo "- ID: {$c->id}, Nombre: {$c->name}, CUIT: {$c->national_id}\n";
    }
    exit(1);
}

echo "Empresa encontrada:\n";
echo "- ID: {$company->id}\n";
echo "- Nombre: {$company->name}\n";
echo "- CUIT actual: {$company->national_id}\n\n";

echo "¿Actualizar CUIT a 27214383794? (s/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim($line) !== 's') {
    echo "Operación cancelada\n";
    exit(0);
}

$company->national_id = '27214383794';
$company->save();

echo "\n✅ CUIT actualizado exitosamente a: {$company->national_id}\n";
echo "Ahora puedes subir el certificado desde la interfaz web.\n";
