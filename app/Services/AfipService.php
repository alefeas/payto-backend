<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AfipService
{
    private $wsaaUrl;
    private $padronUrl;
    private $certificatePath;
    private $keyPath;
    
    public function __construct()
    {
        $this->wsaaUrl = config('services.afip.wsaa_url');
        $this->padronUrl = config('services.afip.padron_url');
        $this->certificatePath = config('services.afip.certificate_path');
        $this->keyPath = config('services.afip.key_path');
    }
    
    /**
     * Validate CUIT/CUIL with AFIP Padrón A13
     */
    public function validateCuit(string $cuit): array
    {
        try {
            // Remove hyphens from CUIT
            $cuit = str_replace('-', '', $cuit);
            
            // Validate CUIT format
            if (!$this->isValidCuitFormat($cuit)) {
                throw new Exception('Formato de CUIT/CUIL inválido');
            }
            
            // TODO: Implement real AFIP Padrón A13 integration
            // El servicio Padrón A13 requiere certificado de PRODUCCIÓN
            // En testing, AFIP no provee acceso al padrón real
            
            return [
                'success' => false,
                'message' => 'El servicio de consulta de CUIT requiere certificado de producción. En ambiente de testing, ingresá los datos manualmente.',
                'cuit_valid' => true, // El formato es válido
            ];
            
        } catch (Exception $e) {
            Log::error('AFIP validation error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate user's AFIP certificate
     */
    public function validateCertificate($certificateFile, $keyFile, string $expectedCuit): array
    {
        try {
            // Read certificate content
            $certContent = file_get_contents($certificateFile->getRealPath());
            $keyContent = file_get_contents($keyFile->getRealPath());
            
            // Parse certificate
            $certData = openssl_x509_parse($certContent);
            
            if (!$certData) {
                throw new Exception('No se pudo leer el certificado');
            }
            
            // Validate certificate is not expired
            $now = time();
            if ($certData['validFrom_time_t'] > $now) {
                throw new Exception('El certificado aún no es válido');
            }
            
            if ($certData['validTo_time_t'] < $now) {
                throw new Exception('El certificado ha expirado');
            }
            
            // Extract CUIT from certificate subject
            $subject = $certData['subject'];
            $certCuit = null;
            
            // AFIP certificates have CUIT in serialNumber field
            if (isset($subject['serialNumber'])) {
                $extracted = preg_replace('/[^0-9]/', '', $subject['serialNumber']);
                if (strlen($extracted) === 11) {
                    $certCuit = $extracted;
                }
            }
            
            // If not found in serialNumber, try to extract from CN
            if (!$certCuit && isset($subject['CN'])) {
                $extracted = preg_replace('/[^0-9]/', '', $subject['CN']);
                if (strlen($extracted) === 11) {
                    $certCuit = $extracted;
                }
            }
            
            if (!$certCuit) {
                throw new Exception('No se pudo extraer un CUIT válido (11 dígitos) del certificado. Subject: ' . json_encode($subject));
            }
            
            // Validate CUIT matches
            $expectedCuitClean = str_replace('-', '', $expectedCuit);
            
            Log::info('CUIT Validation', [
                'cert_cuit' => $certCuit,
                'expected_cuit' => $expectedCuit,
                'expected_cuit_clean' => $expectedCuitClean,
                'match' => $certCuit === $expectedCuitClean
            ]);
            
            if ($certCuit !== $expectedCuitClean) {
                throw new Exception("El CUIT del certificado ($certCuit) no coincide con el CUIT de la empresa ($expectedCuitClean)");
            }
            
            // Validate private key matches certificate
            $publicKey = openssl_pkey_get_public($certContent);
            $privateKey = openssl_pkey_get_private($keyContent);
            
            if (!$publicKey || !$privateKey) {
                throw new Exception('Certificado o clave privada inválidos');
            }
            
            // Test if key pair matches
            $testData = 'test';
            openssl_private_encrypt($testData, $encrypted, $privateKey);
            $decrypted = '';
            openssl_public_decrypt($encrypted, $decrypted, $publicKey);
            
            if ($testData !== $decrypted) {
                throw new Exception('La clave privada no corresponde al certificado');
            }
            
            return [
                'success' => true,
                'cuit' => $certCuit,
                'validFrom' => date('Y-m-d', $certData['validFrom_time_t']),
                'validTo' => date('Y-m-d', $certData['validTo_time_t']),
                'issuer' => $certData['issuer']['CN'] ?? 'Unknown'
            ];
            
        } catch (Exception $e) {
            Log::error('Certificate validation error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate CUIT format and checksum
     */
    private function isValidCuitFormat(string $cuit): bool
    {
        // Remove hyphens
        $cuit = str_replace('-', '', $cuit);
        
        // Must be 11 digits
        if (strlen($cuit) !== 11 || !ctype_digit($cuit)) {
            return false;
        }
        
        // Validate checksum
        $multipliers = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        
        for ($i = 0; $i < 10; $i++) {
            $sum += intval($cuit[$i]) * $multipliers[$i];
        }
        
        $remainder = $sum % 11;
        $checkDigit = $remainder === 0 ? 0 : 11 - $remainder;
        
        return intval($cuit[10]) === $checkDigit;
    }
}
