<?php

/**
 * Script de verificación FCE MiPyME
 * Ejecutar: php verify_fce_setup.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "===========================================\n";
echo "   VERIFICACIÓN FCE MiPyME - PayTo\n";
echo "===========================================\n\n";

// 1. Verificar empresa MiPyME
echo "1️⃣  Verificando empresa MiPyME...\n";
$company = App\Models\Company::first();

if (!$company) {
    echo "   ❌ No hay empresas en la base de datos\n";
    exit(1);
}

echo "   ✅ Empresa encontrada: {$company->name}\n";
echo "   📋 ID: {$company->id}\n";
echo "   🏢 is_mipyme: " . ($company->is_mipyme ? '✅ true' : '❌ false') . "\n";
echo "   💳 CBU: " . ($company->cbu ? "✅ {$company->cbu}" : '❌ No configurado') . "\n\n";

if (!$company->is_mipyme) {
    echo "   ⚠️  ADVERTENCIA: Empresa no está marcada como MiPyME\n";
    echo "   💡 Ejecuta: UPDATE companies SET is_mipyme = 1 WHERE id = '{$company->id}';\n\n";
}

if (!$company->cbu || strlen($company->cbu) !== 22) {
    echo "   ⚠️  ADVERTENCIA: CBU no válido (debe tener 22 dígitos)\n";
    echo "   💡 Ejecuta: UPDATE companies SET cbu = '0170099220000001234567' WHERE id = '{$company->id}';\n\n";
}

// 2. Verificar tipos disponibles
echo "2️⃣  Verificando tipos de comprobantes disponibles...\n";
$types = App\Services\VoucherTypeService::getAvailableTypes($company->is_mipyme);
$fceTypes = array_filter($types, fn($t) => $t['category'] === 'fce_mipyme');

echo "   📊 Total tipos disponibles: " . count($types) . "\n";
echo "   🎫 Tipos FCE MiPyME: " . count($fceTypes) . "\n";

if (count($fceTypes) > 0) {
    echo "   ✅ Tipos FCE disponibles:\n";
    foreach ($fceTypes as $key => $type) {
        echo "      • {$key} - {$type['name']} (código {$type['code']})\n";
    }
} else {
    echo "   ❌ No hay tipos FCE disponibles\n";
    echo "   💡 Verifica que is_mipyme = 1\n";
}
echo "\n";

// 3. Verificar migración de campos
echo "3️⃣  Verificando campos en base de datos...\n";

try {
    $hasCompanyFields = Schema::hasColumns('companies', ['is_mipyme', 'cbu']);
    echo "   " . ($hasCompanyFields ? '✅' : '❌') . " Campos en tabla companies\n";
    
    $hasInvoiceFields = Schema::hasColumns('invoices', ['payment_due_date', 'issuer_cbu', 'acceptance_status']);
    echo "   " . ($hasInvoiceFields ? '✅' : '❌') . " Campos en tabla invoices\n";
} catch (Exception $e) {
    echo "   ⚠️  No se pudo verificar estructura: {$e->getMessage()}\n";
}
echo "\n";

// 4. Verificar servicios
echo "4️⃣  Verificando servicios...\n";

try {
    $voucherService = new App\Services\VoucherTypeService();
    echo "   ✅ VoucherTypeService\n";
    
    $validationService = new App\Services\VoucherValidationService();
    echo "   ✅ VoucherValidationService\n";
    
    // Verificar que FCEA existe
    $fceaCode = App\Services\VoucherTypeService::getAfipCode('FCEA');
    echo "   ✅ FCEA código AFIP: {$fceaCode}\n";
    
    // Verificar conversión inversa
    $fceaKey = App\Services\VoucherTypeService::getTypeByCode('201');
    echo "   ✅ Código 201 -> {$fceaKey}\n";
    
} catch (Exception $e) {
    echo "   ❌ Error en servicios: {$e->getMessage()}\n";
}
echo "\n";

// 5. Verificar AFIP Web Service Client
echo "5️⃣  Verificando AFIP Web Service Client...\n";

try {
    if ($company->afipCertificate && $company->afipCertificate->is_active) {
        echo "   ✅ Certificado AFIP activo\n";
        
        $client = new App\Services\Afip\AfipWebServiceClient(
            $company->afipCertificate->certificate,
            $company->afipCertificate->private_key,
            $company->cuit,
            $company->afipCertificate->environment === 'production'
        );
        
        echo "   ✅ AfipWebServiceClient inicializado\n";
        
        // Verificar que tiene método getWSFEXClient
        if (method_exists($client, 'getWSFEXClient')) {
            echo "   ✅ Método getWSFEXClient disponible\n";
        } else {
            echo "   ❌ Método getWSFEXClient NO disponible\n";
        }
        
    } else {
        echo "   ⚠️  No hay certificado AFIP activo\n";
        echo "   💡 Sube un certificado en Configuración → AFIP/ARCA\n";
    }
} catch (Exception $e) {
    echo "   ⚠️  No se pudo verificar AFIP client: {$e->getMessage()}\n";
}
echo "\n";

// 6. Verificar Job de aceptación
echo "6️⃣  Verificando Job de aceptación...\n";

if (class_exists('App\Jobs\CheckFCEAcceptanceJob')) {
    echo "   ✅ CheckFCEAcceptanceJob existe\n";
} else {
    echo "   ❌ CheckFCEAcceptanceJob NO existe\n";
}
echo "\n";

// 7. Resumen final
echo "===========================================\n";
echo "   RESUMEN\n";
echo "===========================================\n\n";

$allGood = $company->is_mipyme && 
           $company->cbu && 
           strlen($company->cbu) === 22 && 
           count($fceTypes) > 0;

if ($allGood) {
    echo "✅ TODO LISTO PARA TESTING FCE\n\n";
    echo "Próximos pasos:\n";
    echo "1. Recarga el frontend (Ctrl+R)\n";
    echo "2. Ve a 'Emitir Comprobante'\n";
    echo "3. Selecciona un tipo FCE (FCEA, FCEB, FCEC)\n";
    echo "4. Verifica que aparezca el campo 'Fecha Venc. Pago (FCE)'\n";
    echo "5. Completa y emite la factura\n\n";
    echo "📖 Lee TESTING_FCE_MIPYME.md para más detalles\n";
} else {
    echo "⚠️  CONFIGURACIÓN INCOMPLETA\n\n";
    echo "Problemas detectados:\n";
    
    if (!$company->is_mipyme) {
        echo "❌ Empresa no es MiPyME\n";
        echo "   Ejecuta: UPDATE companies SET is_mipyme = 1 WHERE id = '{$company->id}';\n\n";
    }
    
    if (!$company->cbu || strlen($company->cbu) !== 22) {
        echo "❌ CBU no configurado o inválido\n";
        echo "   Ejecuta: UPDATE companies SET cbu = '0170099220000001234567' WHERE id = '{$company->id}';\n\n";
    }
    
    if (count($fceTypes) === 0) {
        echo "❌ No hay tipos FCE disponibles\n";
        echo "   Verifica VoucherTypeService.php\n\n";
    }
}

echo "\n";
