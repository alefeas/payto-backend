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
        if ($this->certificate->hasValidToken()) {
            return [
                'token' => $this->certificate->token,
                'sign' => $this->certificate->sign,
            ];
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
                'token' => $token,
                'sign' => $sign,
                'token_expires_at' => Carbon::parse($expirationTime),
            ]);

            return ['token' => $token, 'sign' => $sign];
            
        } catch (\Exception $e) {
            Log::error('AFIP WSAA authentication failed', [
                'company_id' => $this->certificate->company_id,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to authenticate with AFIP: ' . $e->getMessage());
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

        // Extraer contenido BASE64 entre BEGIN y END
        if (preg_match('/-----BEGIN PKCS7-----(.+)-----END PKCS7-----/s', $cms, $matches)) {
            $cms = preg_replace('/\s+/', '', $matches[1]);
        } else {
            // Fallback: remover headers y limpiar
            $cms = preg_replace('/^.+\n\n/', '', $cms);
            $cms = preg_replace('/\n-----END.+$/', '', $cms);
            $cms = preg_replace('/\s+/', '', $cms);
        }

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
        
        return [
            'Token' => $credentials['token'],
            'Sign' => $credentials['sign'],
            'Cuit' => $this->certificate->company->national_id,
        ];
    }
}
