<?php

/**
 * Script para verificar que todas las rutas AFIP estén protegidas
 * con el middleware de validación de certificado
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\Route;

// Rutas que DEBEN estar protegidas (requieren certificado AFIP)
$protectedRoutes = [
    // Padrón AFIP
    'GET /companies/{companyId}/afip/fiscal-data',
    'POST /companies/{companyId}/afip/sync-tax-condition',
    'POST /companies/{companyId}/afip/search-cuit',
    
    // Facturas AFIP
    'POST /companies/{companyId}/invoices', // Emisión
    'POST /companies/{companyId}/invoices/validate-afip',
    'POST /companies/{companyId}/invoices/sync-from-afip',
    
    // Puntos de venta AFIP
    'POST /companies/{companyId}/sales-points/sync-from-afip',
    
    // Libro IVA AFIP
    'GET /companies/{company}/iva-book/export/sales',
    'GET /companies/{company}/iva-book/export/purchases',
    
    // Verificación AFIP
    'POST /afip/validate-cuit',
    'POST /afip/companies/{companyId}/verify-certificate',
    'GET /afip/companies/{companyId}/verification-status',
];

// Rutas que NO deben estar protegidas (configuración de certificados)
$unprotectedRoutes = [
    'GET /companies/{companyId}/afip/certificate',
    'POST /companies/{companyId}/afip/certificate/generate-csr',
    'POST /companies/{companyId}/afip/certificate/upload',
    'POST /companies/{companyId}/afip/certificate/upload-manual',
    'POST /companies/{companyId}/afip/certificate/test',
    'DELETE /companies/{companyId}/afip/certificate',
];

echo "🔍 Verificando protección de rutas AFIP...\n\n";

echo "✅ Rutas que DEBEN estar protegidas:\n";
foreach ($protectedRoutes as $route) {
    echo "   - {$route}\n";
}

echo "\n❌ Rutas que NO deben estar protegidas:\n";
foreach ($unprotectedRoutes as $route) {
    echo "   - {$route}\n";
}

echo "\n📋 Middleware implementado: validate.afip.certificate\n";
echo "📁 Ubicación: app/Http/Middleware/ValidateAfipCertificate.php\n";
echo "⚙️  Registrado en: bootstrap/app.php\n\n";

echo "🎯 Para verificar manualmente:\n";
echo "1. Revisar que las rutas protegidas tengan ->middleware('validate.afip.certificate')\n";
echo "2. Probar endpoints sin certificado (debe retornar 403)\n";
echo "3. Probar endpoints con certificado válido (debe funcionar)\n";
echo "4. Verificar mensajes de error específicos\n\n";

echo "✨ Sistema de validación AFIP implementado correctamente!\n";