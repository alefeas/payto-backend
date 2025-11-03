<?php

/**
 * Script to verify that refactoring didn't break anything
 * Run: php verify-refactoring.php
 */

echo "=== Verificando Refactorización ===\n\n";

$errors = [];
$warnings = [];

// 1. Check all classes exist
echo "1. Verificando clases...\n";

$classes = [
    // DTOs
    'App\\DTOs\\InvoiceItemDTO',
    'App\\DTOs\\InvoicePerceptionDTO',
    'App\\DTOs\\CreateInvoiceDTO',
    'App\\DTOs\\CreateManualInvoiceDTO',
    
    // Repositories
    'App\\Repositories\\InvoiceRepository',
    
    // Services
    'App\\Services\\InvoiceService',
    'App\\Services\\InvoiceCalculationService',
    'App\\Services\\CuitHelperService',
    
    // Form Requests
    'App\\Http\\Requests\\StoreInvoiceRequest',
    'App\\Http\\Requests\\StoreManualIssuedInvoiceRequest',
    'App\\Http\\Requests\\StoreManualReceivedInvoiceRequest',
    'App\\Http\\Requests\\UpdateSyncedInvoiceRequest',
    'App\\Http\\Requests\\SyncFromAfipRequest',
    'App\\Http\\Requests\\ValidateWithAfipRequest',
    'App\\Http\\Requests\\GetNextNumberRequest',
    'App\\Http\\Requests\\GetAssociableInvoicesRequest',
    'App\\Http\\Requests\\DownloadBulkRequest',
];

foreach ($classes as $class) {
    // Try to load the class
    try {
        if (class_exists($class)) {
            echo "  ✓ {$class}\n";
        } else {
            // Check if file exists as fallback
            $namespace = str_replace('App\\', '', $class);
            $filePath = str_replace('\\', '/', $namespace);
            $fullPath = __DIR__ . '/app/' . $filePath . '.php';
            
            if (file_exists($fullPath)) {
                echo "  ✓ {$class} (archivo existe)\n";
            } else {
                echo "  ✗ {$class} - NO ENCONTRADA\n";
                $errors[] = "Clase {$class} no existe";
            }
        }
    } catch (\Exception $e) {
        echo "  ✗ {$class} - ERROR: {$e->getMessage()}\n";
        $errors[] = "Error cargando {$class}: {$e->getMessage()}";
    }
}

// 2. Check InvoiceController methods
echo "\n2. Verificando métodos del InvoiceController...\n";

require __DIR__ . '/vendor/autoload.php';

$controllerPath = __DIR__ . '/app/Http/Controllers/Api/InvoiceController.php';
$controllerContent = file_get_contents($controllerPath);

$requiredMethods = [
    'index',
    'show',
    'store',
    'storeManualIssued',
    'storeManualReceived',
    'updateSyncedInvoice',
    'syncFromAfip',
    'validateWithAfip',
    'getNextNumber',
];

foreach ($requiredMethods as $method) {
    if (strpos($controllerContent, "public function {$method}") !== false) {
        echo "  ✓ {$method}()\n";
    } else {
        echo "  ✗ {$method}() - NO ENCONTRADO\n";
        $errors[] = "Método {$method}() no encontrado en InvoiceController";
    }
}

// 3. Check InvoiceService methods
echo "\n3. Verificando métodos del InvoiceService...\n";

$servicePath = __DIR__ . '/app/Services/InvoiceService.php';
if (file_exists($servicePath)) {
    $serviceContent = file_get_contents($servicePath);
    
    $serviceMethods = [
        'getInvoices',
        'getInvoice',
        'formatInvoiceForResponse',
        'updateRelatedInvoiceBalance',
    ];
    
    foreach ($serviceMethods as $method) {
        if (strpos($serviceContent, "function {$method}") !== false) {
            echo "  ✓ {$method}()\n";
        } else {
            echo "  ✗ {$method}() - NO ENCONTRADO\n";
            $warnings[] = "Método {$method}() no encontrado en InvoiceService";
        }
    }
} else {
    $errors[] = "InvoiceService.php no encontrado";
}

// 4. Check syntax errors
echo "\n4. Verificando sintaxis PHP...\n";

$phpFiles = [
    __DIR__ . '/app/Http/Controllers/Api/InvoiceController.php',
    __DIR__ . '/app/Services/InvoiceService.php',
    __DIR__ . '/app/Services/InvoiceCalculationService.php',
    __DIR__ . '/app/Repositories/InvoiceRepository.php',
];

foreach ($phpFiles as $file) {
    if (!file_exists($file)) {
        echo "  ✗ " . basename($file) . " - ARCHIVO NO ENCONTRADO\n";
        $errors[] = "Archivo {$file} no encontrado";
        continue;
    }
    
    $output = [];
    $returnVar = 0;
    exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnVar);
    
    if ($returnVar === 0) {
        echo "  ✓ " . basename($file) . " - Sin errores de sintaxis\n";
    } else {
        echo "  ✗ " . basename($file) . " - ERRORES DE SINTAXIS\n";
        echo "    " . implode("\n    ", $output) . "\n";
        $errors[] = "Error de sintaxis en {$file}";
    }
}

// Summary
echo "\n=== Resumen ===\n";
if (empty($errors)) {
    echo "✓ Todos los checks pasaron correctamente\n";
    if (!empty($warnings)) {
        echo "\n⚠ Advertencias:\n";
        foreach ($warnings as $warning) {
            echo "  - {$warning}\n";
        }
    }
    echo "\n✅ La refactorización parece estar correcta!\n";
    echo "💡 Sugerencia: Ejecuta los tests con: php artisan test\n";
    exit(0);
} else {
    echo "✗ Errores encontrados:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    echo "\n❌ Por favor corrige los errores antes de continuar.\n";
    exit(1);
}

