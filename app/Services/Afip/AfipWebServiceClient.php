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
        // Siempre intentar usar el token existente primero
        if ($this->certificate->current_token && $this->certificate->current_sign) {
            // Si tiene token_expires_at y aún es válido, usarlo
            if ($this->certificate->hasValidToken()) {
                return [
                    'token' => $this->certificate->current_token,
                    'sign' => $this->certificate->current_sign,
                ];
            }
            
            // Si no tiene token_expires_at pero tiene token, intentar usarlo igual
            // (AFIP dirá si es válido o no)
            if (!$this->certificate->token_expires_at) {
                return [
                    'token' => $this->certificate->current_token,
                    'sign' => $this->certificate->current_sign,
                ];
            }
        }

        return $this->requestNewToken();
    }

    /**
     * Request new authentication token from WSAA
     */
    private function requestNewToken(): array
    {
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
            
            // Limpiar token y sign de espacios y saltos de línea
            $token = preg_replace('/\s+/', '', (string) $credentials->credentials->token);
            $sign = preg_replace('/\s+/', '', (string) $credentials->credentials->sign);
            $expirationTime = (string) $credentials->header->expirationTime;

            $this->certificate->update([
                'current_token' => $token,
                'current_sign' => $sign,
                'token_expires_at' => Carbon::parse($expirationTime),
                'last_token_generated_at' => now(),
            ]);

            return ['token' => $token, 'sign' => $sign];
            
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            
            // Si ya tiene un TA válido en AFIP, extender la expiración del token actual
            if (str_contains($errorMsg, 'ya posee un TA valido') || str_contains($errorMsg, 'TA valido')) {
                // Refrescar el certificado desde la BD por si se actualizó en otra petición
                $this->certificate->refresh();
                
                if ($this->certificate->current_token && $this->certificate->current_sign) {
                    // Extender la expiración por 12 horas
                    $this->certificate->update([
                        'token_expires_at' => now()->addHours(12),
                    ]);
                    
                    Log::info('Reusing existing AFIP token', [
                        'company_id' => $this->certificate->company_id,
                        'expires_at' => $this->certificate->token_expires_at,
                    ]);
                    
                    return [
                        'token' => $this->certificate->current_token,
                        'sign' => $this->certificate->current_sign,
                    ];
                }
                
                Log::warning('AFIP has valid token but not stored locally', [
                    'company_id' => $this->certificate->company_id,
                ]);
                
                throw new \Exception(
                    'AFIP indica que ya existe un token válido. Ejecuta: php artisan afip:clear-tokens --company-id=' . $this->certificate->company_id
                );
            }
            
            Log::error('AFIP WSAA authentication failed', [
                'company_id' => $this->certificate->company_id,
                'error' => $errorMsg,
            ]);
            throw new \Exception('Failed to authenticate with AFIP: ' . $errorMsg);
        }
    }

    /**
     * Create Ticket de Requerimiento de Acceso (TRA)
     */
    private function createTRA(): string
    {
        $uniqueId = time();
        $generationTime = date('c', $uniqueId - 60);
        $expirationTime = date('c', $uniqueId + 60);

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
        $certPath = Storage::path($this->certificate->certificate_path);
        $keyPath = Storage::path($this->certificate->private_key_path);

        if (!file_exists($certPath) || !file_exists($keyPath)) {
            throw new \Exception('Certificate or private key file not found');
        }

        // Verificar que el certificado sea válido
        $certContent = file_get_contents($certPath);
        $certData = openssl_x509_parse($certContent);
        if (!$certData) {
            throw new \Exception('Invalid certificate file');
        }

        // Verificar que la clave privada sea válida
        $keyContent = file_get_contents($keyPath);
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
        
        file_put_contents($traFile, $tra);

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
            "file://{$certPath}",
            ["file://{$keyPath}", $password],
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
}
