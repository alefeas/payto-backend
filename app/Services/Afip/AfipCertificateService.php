<?php

namespace App\Services\Afip;

use App\Models\Company;
use App\Models\CompanyAfipCertificate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;

class AfipCertificateService
{
    public function generateCSR(Company $company): array
    {
        // Configuración OpenSSL
        $configArgs = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ];
        
        // Buscar openssl.cnf en Windows
        if (DIRECTORY_SEPARATOR === '\\') {
            $possiblePaths = [
                'C:/xampp/apache/conf/openssl.cnf',
                'C:/xampp/php/extras/openssl/openssl.cnf',
                getenv('OPENSSL_CONF'),
            ];
            
            foreach ($possiblePaths as $path) {
                if ($path && file_exists($path)) {
                    $configArgs['config'] = $path;
                    break;
                }
            }
        }

        // Generar clave privada
        $privateKey = openssl_pkey_new($configArgs);
        if (!$privateKey) {
            throw new \Exception('Error al generar clave privada. Verifica que OpenSSL esté configurado correctamente.');
        }

        // Limpiar CUIT (sin guiones)
        $cuit = preg_replace('/[^0-9]/', '', $company->national_id);
        
        // Datos del certificado
        $dn = [
            'countryName' => 'AR',
            'stateOrProvinceName' => 'Buenos Aires',
            'localityName' => 'CABA',
            'organizationName' => $company->business_name ?? $company->name,
            'commonName' => $cuit,
            'serialNumber' => 'CUIT ' . $cuit,
        ];

        // Generar CSR
        $csr = openssl_csr_new($dn, $privateKey, $configArgs);
        if (!$csr) {
            throw new \Exception('Error al generar CSR.');
        }
        
        // Exportar CSR
        if (!openssl_csr_export($csr, $csrOut)) {
            throw new \Exception('Error al exportar CSR.');
        }
        
        // Exportar clave privada
        if (!openssl_pkey_export($privateKey, $privateKeyOut, null, $configArgs)) {
            throw new \Exception('Error al exportar clave privada.');
        }

        // Encriptar clave privada antes de guardar en BD
        $encryptedKey = Crypt::encryptString($privateKeyOut);

        \Log::info('Saving CSR to database', [
            'company_id' => $company->id,
            'key_length' => strlen($encryptedKey),
        ]);

        // Actualizar o crear registro (guardar directamente en BD)
        $certificate = CompanyAfipCertificate::updateOrCreate(
            ['company_id' => $company->id],
            [
                'private_key_content' => $encryptedKey,
                'key_is_encrypted' => true,
                'is_active' => false,
            ]
        );

        \Log::info('CSR saved successfully', [
            'certificate_id' => $certificate->id,
            'has_private_key' => !empty($certificate->private_key_content),
        ]);

        return [
            'csr' => $csrOut,
            'csr_path' => $csrPath,
            'certificate_id' => $certificate->id,
        ];
    }

    public function uploadCertificate(
        Company $company, 
        string $certificateContent, 
        ?string $password = null,
        string $environment = 'testing'
    ): CompanyAfipCertificate {
        if (!str_contains($certificateContent, 'BEGIN CERTIFICATE')) {
            throw new \Exception('El contenido no es un certificado válido. Debe comenzar con -----BEGIN CERTIFICATE-----');
        }
        
        $certData = openssl_x509_parse($certificateContent);
        
        if (!$certData) {
            throw new \Exception('No se pudo leer el certificado. Verifica que sea un archivo .crt válido de AFIP.');
        }
        
        // Extract CUIT from certificate
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
        
        if (!$certCuit) {
            throw new \Exception('No se pudo extraer un CUIT válido del certificado');
        }
        
        $companyCuit = preg_replace('/[^0-9]/', '', $company->national_id);

        if ($certCuit !== $companyCuit) {
            throw new \Exception("El CUIT del certificado ($certCuit) no coincide con el CUIT de la empresa ($companyCuit)");
        }

        // VALIDACIÓN: Debe existir clave privada del flujo asistido
        $existingCert = CompanyAfipCertificate::where('company_id', $company->id)->first();
        
        if (!$existingCert || !$existingCert->private_key_content) {
            throw new \Exception('Primero debes generar un CSR haciendo clic en "Generar CSR", o usa el método Manual para subir certificado y clave privada juntos.');
        }
        
        // Desencriptar clave privada para validación
        $privateKeyContent = $existingCert->key_is_encrypted 
            ? Crypt::decryptString($existingCert->private_key_content)
            : $existingCert->private_key_content;
            
        $privKey = openssl_pkey_get_private($privateKeyContent);
        if (!$privKey) {
            throw new \Exception('No se pudo leer la clave privada almacenada.');
        }
        
        // Validar que el certificado y la clave privada coincidan
        $certPubKey = openssl_pkey_get_public($certificateContent);
        if (!$certPubKey) {
            throw new \Exception('No se pudo leer la clave pública del certificado.');
        }
        
        $pubDetails = openssl_pkey_get_details($certPubKey);
        $privDetails = openssl_pkey_get_details($privKey);
        
        if (!$pubDetails || !$privDetails) {
            throw new \Exception('Error al validar las claves.');
        }
        
        // Comparar las claves públicas
        if ($pubDetails['key'] !== $privDetails['key']) {
            throw new \Exception('El certificado no coincide con la clave privada almacenada. Usa el método Manual para subir certificado y clave privada juntos.');
        }
        
        // Preservar token si existe
        $existingCert = CompanyAfipCertificate::where('company_id', $company->id)->first();
        $preservedToken = [];
        if ($existingCert && $existingCert->current_token && $existingCert->current_sign) {
            $preservedToken = [
                'current_token' => $existingCert->current_token,
                'current_sign' => $existingCert->current_sign,
                'token_expires_at' => $existingCert->token_expires_at,
            ];
        }
        
        // Guardar directamente en BD (sin storage)
        $certificate = CompanyAfipCertificate::updateOrCreate(
            ['company_id' => $company->id],
            array_merge([
                'certificate_content' => $certificateContent,
                'certificate_is_encrypted' => false,
                'encrypted_password' => $password ? Crypt::encryptString($password) : null,
                'valid_from' => date('Y-m-d', $certData['validFrom_time_t']),
                'valid_until' => date('Y-m-d', $certData['validTo_time_t']),
                'environment' => $environment,
                'is_active' => true,
            ], $preservedToken)
        );
        
        // Update company verification status
        $company->update([
            'verification_status' => 'verified',
        ]);

        // Auditoría empresa: certificado AFIP subido/actualizado
        app(\App\Services\AuditService::class)->log(
            (string) $company->id,
            (string) (auth()->id() ?? ''),
            'afip.certificate.uploaded',
            'Certificado AFIP configurado',
            'CompanyAfipCertificate',
            (string) $certificate->id,
            [
                'environment' => $environment,
                'valid_from' => $certificate->valid_from,
                'valid_until' => $certificate->valid_until,
            ]
        );

        return $certificate;
    }

    public function uploadManualCertificate(
        Company $company,
        string $certificateContent,
        string $privateKeyContent,
        ?string $password = null,
        string $environment = 'testing'
    ): CompanyAfipCertificate {
        if (!str_contains($certificateContent, 'BEGIN CERTIFICATE')) {
            throw new \Exception('El certificado no es válido. Debe comenzar con -----BEGIN CERTIFICATE-----');
        }
        
        $certData = openssl_x509_parse($certificateContent);
        
        if (!$certData) {
            throw new \Exception('No se pudo leer el certificado.');
        }
        
        if (!str_contains($privateKeyContent, 'BEGIN') || !str_contains($privateKeyContent, 'PRIVATE KEY')) {
            throw new \Exception('La clave privada no es válida. Debe comenzar con -----BEGIN PRIVATE KEY----- o -----BEGIN RSA PRIVATE KEY-----');
        }
        
        // Extract CUIT from certificate
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
        
        if (!$certCuit) {
            throw new \Exception('No se pudo extraer un CUIT válido del certificado');
        }
        
        $companyCuit = preg_replace('/[^0-9]/', '', $company->national_id);

        if ($certCuit !== $companyCuit) {
            throw new \Exception("El CUIT del certificado ($certCuit) no coincide con el CUIT de la empresa ($companyCuit)");
        }

        // Verificar que el certificado y la clave privada coincidan
        $privKey = openssl_pkey_get_private($privateKeyContent, $password);
        if (!$privKey) {
            throw new \Exception('Clave privada inválida o contraseña incorrecta');
        }
        
        $pubKey = openssl_pkey_get_public($certificateContent);
        if (!$pubKey) {
            throw new \Exception('No se pudo leer la clave pública del certificado');
        }
        
        $pubDetails = openssl_pkey_get_details($pubKey);
        $privDetails = openssl_pkey_get_details($privKey);
        
        if (!$pubDetails || !$privDetails) {
            throw new \Exception('Error al validar las claves');
        }
        
        if ($pubDetails['key'] !== $privDetails['key']) {
            throw new \Exception('El certificado y la clave privada no coinciden');
        }
        
        // Encriptar clave privada antes de guardar en BD
        $encryptedKey = Crypt::encryptString($privateKeyContent);

        // Preservar token si existe
        $existingCert = CompanyAfipCertificate::where('company_id', $company->id)->first();
        $preservedToken = [];
        if ($existingCert && $existingCert->current_token && $existingCert->current_sign) {
            $preservedToken = [
                'current_token' => $existingCert->current_token,
                'current_sign' => $existingCert->current_sign,
                'token_expires_at' => $existingCert->token_expires_at,
            ];
        }

        // Guardar directamente en BD (sin storage)
        $certificate = CompanyAfipCertificate::updateOrCreate(
            ['company_id' => $company->id],
            array_merge([
                'certificate_content' => $certificateContent,
                'private_key_content' => $encryptedKey,
                'certificate_is_encrypted' => false,
                'key_is_encrypted' => true,
                'encrypted_password' => $password ? Crypt::encryptString($password) : null,
                'valid_from' => date('Y-m-d', $certData['validFrom_time_t']),
                'valid_until' => date('Y-m-d', $certData['validTo_time_t']),
                'environment' => $environment,
                'is_active' => true,
            ], $preservedToken)
        );
        
        // Update company verification status
        $company->update([
            'verification_status' => 'verified',
        ]);

        // Auditoría empresa: certificado AFIP subido manualmente
        app(\App\Services\AuditService::class)->log(
            (string) $company->id,
            (string) (auth()->id() ?? ''),
            'afip.certificate.uploaded_manual',
            'Certificado AFIP configurado (manual)',
            'CompanyAfipCertificate',
            (string) $certificate->id,
            [
                'environment' => $environment,
                'valid_from' => $certificate->valid_from,
                'valid_until' => $certificate->valid_until,
            ]
        );

        return $certificate;
    }

    public function getCertificate(Company $company): ?CompanyAfipCertificate
    {
        return $company->afipCertificate;
    }

    public function deleteCertificate(Company $company): bool
    {
        $certificate = $company->afipCertificate;
        
        if (!$certificate) {
            return false;
        }

        $id = (string) $certificate->id;
        $certificate->delete();

        // Auditoría empresa: certificado AFIP eliminado
        app(\App\Services\AuditService::class)->log(
            (string) $company->id,
            (string) (auth()->id() ?? ''),
            'afip.certificate.deleted',
            'Certificado AFIP eliminado',
            'CompanyAfipCertificate',
            $id
        );
        return true;
    }

    public function testConnection(Company $company): array
    {
        $certificate = $company->afipCertificate;
        
        if (!$certificate || !$certificate->is_active) {
            return [
                'success' => false,
                'message' => 'No hay certificado activo',
            ];
        }

        if ($certificate->isExpired()) {
            return [
                'success' => false,
                'message' => 'El certificado ha expirado',
            ];
        }

        try {
            $client = new AfipWebServiceClient($certificate, 'wsfe');
            $credentials = $client->getAuthCredentials();
            
            if (empty($credentials['token']) || empty($credentials['sign'])) {
                return [
                    'success' => false,
                    'message' => 'No se pudo obtener token de autenticación',
                ];
            }
            
            $wsfeClient = $client->getWSFEClient();
            $auth = $client->getAuthArray();
            
            $response = $wsfeClient->FEDummy();
            
            if (!isset($response->FEDummyResult)) {
                return [
                    'success' => false,
                    'message' => 'Respuesta inválida de AFIP',
                ];
            }
            
            $result = $response->FEDummyResult;
            
            return [
                'success' => true,
                'message' => 'Conexión exitosa con AFIP',
                'environment' => $certificate->environment,
                'expires_in_days' => $certificate->valid_until->diffInDays(now()),
                'afip_server' => [
                    'app_server' => $result->AppServer ?? 'N/A',
                    'db_server' => $result->DbServer ?? 'N/A',
                    'auth_server' => $result->AuthServer ?? 'N/A',
                ],
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al conectar con AFIP: ' . $e->getMessage(),
            ];
        }
    }

    private function isSelfSigned(array $certData): bool
    {
        // Un certificado es autofirmado si el issuer y el subject son iguales
        $subject = $certData['subject'] ?? [];
        $issuer = $certData['issuer'] ?? [];
        
        return $subject === $issuer;
    }
}
