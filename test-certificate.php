<?php

// Script de diagnóstico para certificados AFIP

$certFile = $argv[1] ?? null;
$keyFile = $argv[2] ?? null;

if (!$certFile) {
    die("Uso: php test-certificate.php <certificado.crt> [clave.key]\n");
}

if (!file_exists($certFile)) {
    die("Error: El archivo $certFile no existe\n");
}

echo "=== DIAGNÓSTICO DE CERTIFICADO AFIP ===\n\n";

// Leer certificado
$certContent = file_get_contents($certFile);
echo "1. Contenido del certificado:\n";
echo "   - Tamaño: " . strlen($certContent) . " bytes\n";
echo "   - Comienza con BEGIN CERTIFICATE: " . (str_contains($certContent, 'BEGIN CERTIFICATE') ? 'SÍ' : 'NO') . "\n\n";

// Parsear certificado
$certData = openssl_x509_parse($certContent);

if (!$certData) {
    die("ERROR: No se pudo parsear el certificado\n");
}

echo "2. Información del certificado:\n";
echo "   - Subject:\n";
foreach ($certData['subject'] as $key => $value) {
    echo "     * $key: $value\n";
}
echo "\n   - Issuer:\n";
foreach ($certData['issuer'] as $key => $value) {
    echo "     * $key: $value\n";
}

echo "\n   - Validez:\n";
echo "     * Desde: " . date('Y-m-d H:i:s', $certData['validFrom_time_t']) . "\n";
echo "     * Hasta: " . date('Y-m-d H:i:s', $certData['validTo_time_t']) . "\n";

// Extraer CUIT
$subject = $certData['subject'];
$certCuit = null;

if (isset($subject['serialNumber'])) {
    $extracted = preg_replace('/[^0-9]/', '', $subject['serialNumber']);
    if (strlen($extracted) === 11) {
        $certCuit = $extracted;
    }
}

if (!$certCuit && isset($subject['CN'])) {
    $extracted = preg_replace('/[^0-9]/', '', $subject['CN']);
    if (strlen($extracted) === 11) {
        $certCuit = $extracted;
    }
}

echo "\n3. CUIT extraído: " . ($certCuit ?: 'NO SE PUDO EXTRAER') . "\n";

// Verificar si es autofirmado
$isSelfsigned = $certData['subject'] === $certData['issuer'];
echo "\n4. ¿Es autofirmado?: " . ($isSelfsigned ? 'SÍ' : 'NO') . "\n";

// Si hay clave privada, verificar coincidencia
if ($keyFile && file_exists($keyFile)) {
    echo "\n5. Verificando clave privada...\n";
    $keyContent = file_get_contents($keyFile);
    
    echo "   - Tamaño: " . strlen($keyContent) . " bytes\n";
    echo "   - Comienza con BEGIN: " . (str_contains($keyContent, 'BEGIN') ? 'SÍ' : 'NO') . "\n";
    
    $privKey = openssl_pkey_get_private($keyContent);
    if (!$privKey) {
        echo "   - ERROR: No se pudo leer la clave privada\n";
    } else {
        echo "   - Clave privada válida: SÍ\n";
        
        $pubKey = openssl_pkey_get_public($certContent);
        if ($pubKey) {
            $pubDetails = openssl_pkey_get_details($pubKey);
            $privDetails = openssl_pkey_get_details($privKey);
            
            $match = $pubDetails['key'] === $privDetails['key'];
            echo "   - ¿Coincide con el certificado?: " . ($match ? 'SÍ' : 'NO') . "\n";
        }
    }
}

echo "\n=== FIN DEL DIAGNÓSTICO ===\n";
