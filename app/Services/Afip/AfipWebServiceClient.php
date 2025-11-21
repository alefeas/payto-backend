<?php

namespace App\Services\Afip;

use App\Models\CompanyAfipCertificate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;

class AfipWebServiceClient
{
    private const WSAA_WSDL_TESTING = 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms?wsdl';
    private const WSAA_WSDL_PRODUCTION = 'https://wsaa.afip.gov.ar/ws/services/LoginCms?wsdl';
    
    private const WSFE_WSDL_TESTING = 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL';
    private const WSFE_WSDL_PRODUCTION = 'https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL';
    
    private const WSFEX_WSDL_TESTING = 'https://wswhomo.afip.gov.ar/wsfexv1/service.asmx?WSDL';
    private const WSFEX_WSDL_PRODUCTION = 'https://servicios1.afip.gov.ar/wsfexv1/service.asmx?WSDL';

    private CompanyAfipCertificate $certificate;
    private string $service;

    public function __construct(CompanyAfipCertificate $certificate, string $service = 'wsfe')
    {
        $this->certificate = $certificate;
        $this->service = $service;
    }

    /**
     * Get authentication token and sign for AFIP web services
     */
    public function getAuthCredentials(): array
    {
        // Refrescar certificado desde BD
        $this->certificate->refresh();
        
        Log::info('Getting AFIP auth credentials', [
            'certificate_id' => $this->certificate->id,
            'has_token' => !empty($this->certificate->current_token),
            'has_sign' => !empty($this->certificate->current_sign),
            'token_expires_at' => $this->certificate->token_expires_at,
            'has_valid_token' => $this->certificate->hasValidToken(),
        ]);
        
        // Siempre intentar usar el token existente primero
        if ($this->certificate->current_token && $this->certificate->current_sign) {
            // Si tiene token_expires_at y aún es válido, usarlo
            if ($this->certificate->hasValidToken()) {
                Log::info('Reusing existing valid token');
                return [
                    'token' => $this->certificate->current_token,
                    'sign' => $this->certificate->current_sign,
                ];
            }
            
            // Si no tiene token_expires_at pero tiene token, intentar usarlo igual
            // (AFIP dirá si es válido o no)
            if (!$this->certificate->token_expires_at) {
                Log::info('Reusing existing token without expiration date');
                return [
                    'token' => $this->certificate->current_token,
                    'sign' => $this->certificate->current_sign,
                ];
            }
            
            Log::warning('Token exists but is expired', [
                'token_expires_at' => $this->certificate->token_expires_at,
                'now' => now(),
            ]);
        } else {
            Log::info('No token found in certificate', [
                'has_token' => !empty($this->certificate->current_token),
                'has_sign' => !empty($this->certificate->current_sign),
            ]);
        }

        return $this->requestNewToken();
    }

    /**
     * Request new authentication token from WSAA
     */
    private function requestNewToken(): array
    {
        // Buscar token válido en CUALQUIER certificado de la misma empresa
        $otherCert = CompanyAfipCertificate::where('company_id', $this->certificate->company_id)
            ->whereNotNull('current_token')
            ->whereNotNull('current_sign')
            ->where('current_token', '!=', 'PENDING')
            ->where('token_expires_at', '>', now())
            ->orderByDesc('token_expires_at')
            ->first();
            
        if ($otherCert) {
            $this->certificate->update([
                'current_token' => $otherCert->current_token,
                'current_sign' => $otherCert->current_sign,
                'token_expires_at' => $otherCert->token_expires_at,
            ]);
            
            return [
                'token' => $otherCert->current_token,
                'sign' => $otherCert->current_sign,
            ];
        }
        
        $tra = $this->createTRA();
        $cms = $this->signTRA($tra);
        
        $wsaaUrl = $this->certificate->environment === 'production' 
            ? self::WSAA_WSDL_PRODUCTION 
            : self::WSAA_WSDL_TESTING;

        try {
            $client = new \SoapClient($wsaaUrl, [
                'soap_version' => SOAP_1_2,
                'location' => str_replace('?WSDL', '', $wsaaUrl),
                'trace' => 1,
                'exceptions' => true,
            ]);

            $response = $client->loginCms(['in0' => $cms]);
            
            $credentials = simplexml_load_string($response->loginCmsReturn);
            
            $token = preg_replace('/\s+/', '', (string) $credentials->credentials->token);
            $sign = preg_replace('/\s+/', '', (string) $credentials->credentials->sign);
            $expirationTime = (string) $credentials->header->expirationTime;

            // GUARDAR TOKEN EN TODOS LOS CERTIFICADOS DE LA EMPRESA
            $expiresAt = Carbon::parse($expirationTime);
            
            Log::info('Attempting to save AFIP token', [
                'company_id' => $this->certificate->company_id,
                'token_length' => strlen($token),
                'sign_length' => strlen($sign),
                'expires_at' => $expiresAt->toDateTimeString(),
            ]);
            
            // Guardar token con commit inmediato para que no se pierda con rollback
            $currentTransactionLevel = \DB::transactionLevel();
            
            // Si estamos en una transacción, hacer commit temporal
            if ($currentTransactionLevel > 0) {
                for ($i = 0; $i < $currentTransactionLevel; $i++) {
                    \DB::commit();
                }
            }
            
            // Guardar token fuera de cualquier transacción
            $updated = CompanyAfipCertificate::where('company_id', $this->certificate->company_id)
                ->update([
                    'current_token' => $token,
                    'current_sign' => $sign,
                    'token_expires_at' => $expiresAt,
                    'last_token_generated_at' => now(),
                ]);
            
            // Reiniciar transacciones si había alguna
            if ($currentTransactionLevel > 0) {
                for ($i = 0; $i < $currentTransactionLevel; $i++) {
                    \DB::beginTransaction();
                }
            }
            
            Log::info('AFIP token saved successfully', [
                'company_id' => $this->certificate->company_id,
                'certificates_updated' => $updated,
                'expires_at' => $expiresAt->toDateTimeString(),
            ]);
            
            // Verificar INMEDIATAMENTE en BD
            $verification = CompanyAfipCertificate::where('company_id', $this->certificate->company_id)
                ->select('current_token', 'current_sign', 'token_expires_at')
                ->first();
            
            Log::info('Token verification from DB', [
                'has_token' => !empty($verification->current_token),
                'token_length' => $verification->current_token ? strlen($verification->current_token) : 0,
                'has_sign' => !empty($verification->current_sign),
                'expires_at' => $verification->token_expires_at,
            ]);
            
            if (!$verification->current_token) {
                Log::error('CRITICAL: Token was NOT saved to database!', [
                    'company_id' => $this->certificate->company_id,
                    'certificate_id' => $this->certificate->id,
                    'updated_count' => $updated,
                ]);
            }
            
            $this->certificate->refresh();

            return ['token' => $token, 'sign' => $sign];
            
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            
            if (str_contains($errorMsg, 'ya posee un TA valido') || 
                str_contains($errorMsg, 'TA valido') ||
                str_contains($errorMsg, 'CEE ya posee')) {
                
                throw new \Exception(
                    'Token AFIP huérfano: AFIP tiene un token activo para este CUIT. '
                    . 'Esperá 12 horas o usá otro CUIT de prueba. En producción esto NO pasaría.'
                );
            }
            
            throw new \Exception('Error generando token AFIP: ' . $errorMsg);
        }
    }

    /**
     * Create Ticket de Requerimiento de Acceso (TRA)
     */
    private function createTRA(): string
    {
        $uniqueId = time();
        $generationTime = date('c', $uniqueId - 600);
        $expirationTime = date('c', $uniqueId + 600);

        $tra = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<loginTicketRequest version="1.0">
    <header>
        <uniqueId>{$uniqueId}</uniqueId>
        <generationTime>{$generationTime}</generationTime>
        <expirationTime>{$expirationTime}</expirationTime>
    </header>
    <service>{$this->service}</service>
</loginTicketRequest>
XML;

        return $tra;
    }

    /**
     * Sign TRA with certificate and private key
     */
    private function signTRA(string $tra): string
    {
        // Read certificate and key directly from database
        $certContent = $this->certificate->certificate_content;
        $keyContent = $this->certificate->private_key_content;

        if (!$certContent || !$keyContent) {
            throw new \Exception('Certificate or private key not found in database');
        }

        // Decrypt if needed
        if ($this->certificate->certificate_is_encrypted) {
            try {
                $certContent = Crypt::decryptString($certContent);
            } catch (\Exception $e) {
                throw new \Exception('Failed to decrypt certificate: ' . $e->getMessage());
            }
        }

        // Verificar que el certificado sea válido
        $certData = openssl_x509_parse($certContent);
        if (!$certData) {
            throw new \Exception('Invalid certificate file');
        }

        // Decrypt private key if needed
        if ($this->certificate->key_is_encrypted) {
            try {
                $keyContent = Crypt::decryptString($keyContent);
            } catch (\Exception $e) {
                throw new \Exception('Failed to decrypt private key: ' . $e->getMessage());
            }
        }
        $password = $this->certificate->encrypted_password 
            ? Crypt::decryptString($this->certificate->encrypted_password) 
            : null;
        
        $privKey = openssl_pkey_get_private($keyContent, $password);
        if (!$privKey) {
            throw new \Exception('Invalid private key or password');
        }

        // Verificar que coincidan
        $pubKey = openssl_pkey_get_public($certContent);
        $pubDetails = openssl_pkey_get_details($pubKey);
        $privDetails = openssl_pkey_get_details($privKey);
        
        if ($pubDetails['key'] !== $privDetails['key']) {
            Log::error('Certificate and private key do not match', [
                'cert_cn' => $certData['subject']['CN'] ?? 'N/A',
                'company_id' => $this->certificate->company_id,
            ]);
            throw new \Exception('Certificate and private key do not match');
        }

        $traFile = tempnam(sys_get_temp_dir(), 'tra_');
        $cmsFile = tempnam(sys_get_temp_dir(), 'cms_');
        $certFile = tempnam(sys_get_temp_dir(), 'cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'key_');
        
        file_put_contents($traFile, $tra);
        file_put_contents($certFile, $certContent);
        file_put_contents($keyFile, $keyContent);

        // Configurar OpenSSL para Windows
        if (DIRECTORY_SEPARATOR === '\\') {
            $opensslConf = getenv('OPENSSL_CONF') ?: 'C:/xampp/apache/conf/openssl.cnf';
            if (file_exists($opensslConf)) {
                putenv("OPENSSL_CONF={$opensslConf}");
            }
        }

        $status = openssl_pkcs7_sign(
            $traFile,
            $cmsFile,
            "file://{$certFile}",
            ["file://{$keyFile}", $password],
            [],
            !PKCS7_DETACHED
        );

        if (!$status) {
            $error = openssl_error_string();
            Log::error('Failed to sign TRA', ['openssl_error' => $error]);
            throw new \Exception('Failed to sign TRA: ' . $error);
        }

        $cms = file_get_contents($cmsFile);
        
        unlink($traFile);
        unlink($cmsFile);
        unlink($certFile);
        unlink($keyFile);

        // Extraer solo el contenido BASE64 del CMS
        $cms = str_replace("\r", '', $cms);
        $cms = preg_replace('/^MIME.+?\n\n/s', '', $cms);
        $cms = preg_replace('/\n\n.+$/s', '', $cms);
        $cms = str_replace("\n", '', $cms);

        return $cms;
    }

    /**
     * Get SOAP client for WSFE service
     */
    public function getWSFEClient(): \SoapClient
    {
        $wsdl = $this->certificate->environment === 'production'
            ? self::WSFE_WSDL_PRODUCTION
            : self::WSFE_WSDL_TESTING;

        return new \SoapClient($wsdl, [
            'soap_version' => SOAP_1_2,
            'location' => str_replace('?WSDL', '', $wsdl),
            'trace' => 1,
            'exceptions' => true,
        ]);
    }

    /**
     * Get SOAP client for WSFEX service (FCE MiPyME)
     */
    public function getWSFEXClient(): \SoapClient
    {
        $wsdl = $this->certificate->environment === 'production'
            ? self::WSFEX_WSDL_PRODUCTION
            : self::WSFEX_WSDL_TESTING;

        return new \SoapClient($wsdl, [
            'soap_version' => SOAP_1_2,
            'location' => str_replace('?WSDL', '', $wsdl),
            'trace' => 1,
            'exceptions' => true,
        ]);
    }

    /**
     * Get authentication token
     */
    public function getToken(): string
    {
        $credentials = $this->getAuthCredentials();
        return $credentials['token'];
    }

    /**
     * Get authentication sign
     */
    public function getSign(): string
    {
        $credentials = $this->getAuthCredentials();
        return $credentials['sign'];
    }

    /**
     * Get authentication array for WSFE requests
     */
    public function getAuthArray(): array
    {
        $credentials = $this->getAuthCredentials();
        
        // Limpiar CUIT (solo números)
        $cuit = preg_replace('/[^0-9]/', '', $this->certificate->company->national_id);
        
        return [
            'Token' => $credentials['token'],
            'Sign' => $credentials['sign'],
            'Cuit' => $cuit,
        ];
    }

    /**
     * Get sales points from AFIP
     */
    public function getSalesPoints(): array
    {
        $client = $this->getWSFEClient();
        $auth = $this->getAuthArray();
        
        try {
            $response = $client->FEParamGetPtosVenta(['Auth' => $auth]);
            
            if (!isset($response->FEParamGetPtosVentaResult->ResultGet->PtoVenta)) {
                return [];
            }
            
            $salesPoints = $response->FEParamGetPtosVentaResult->ResultGet->PtoVenta;
            
            // Si es un solo punto de venta, convertir a array
            if (!is_array($salesPoints)) {
                $salesPoints = [$salesPoints];
            }
            
            return array_map(function($sp) {
                return [
                    'point_number' => (int) $sp->Nro,
                    'description' => isset($sp->Desc) ? (string) $sp->Desc : null,
                    'blocked' => isset($sp->Bloqueado) ? $sp->Bloqueado === 'S' : false,
                    'drop_date' => isset($sp->FchBaja) ? (string) $sp->FchBaja : null,
                ];
            }, $salesPoints);
            
        } catch (\Exception $e) {
            Log::error('Error getting sales points from AFIP', [
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Error consultando puntos de venta en AFIP: ' . $e->getMessage());
        }
    }
}
