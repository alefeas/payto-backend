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
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $dn = [
            'countryName' => 'AR',
            'stateOrProvinceName' => 'Buenos Aires',
            'localityName' => 'CABA',
            'organizationName' => $company->business_name ?? $company->name,
            'commonName' => $company->national_id,
        ];

        $csr = openssl_csr_new($dn, $privateKey, ['digest_alg' => 'sha256']);
        
        openssl_csr_export($csr, $csrOut);
        openssl_pkey_export($privateKey, $privateKeyOut);

        $csrPath = "afip/certificates/{$company->id}/csr.pem";
        $keyPath = "afip/certificates/{$company->id}/private.key";

        Storage::put($csrPath, $csrOut);
        Storage::put($keyPath, $privateKeyOut);

        $certificate = CompanyAfipCertificate::updateOrCreate(
            ['company_id' => $company->id],
            [
                'csr_path' => $csrPath,
                'private_key_path' => $keyPath,
                'is_active' => false,
            ]
        );

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
        $certData = openssl_x509_parse($certificateContent);
        
        if (!$certData) {
            throw new \Exception('Certificado inválido');
        }

        // Validar que no sea autofirmado en producción o si no está permitido
        if ($this->isSelfSigned($certData)) {
            if ($environment === 'production') {
                throw new \Exception('El certificado debe estar firmado por AFIP. Los certificados autofirmados no son válidos en producción.');
            }
            if (!config('afip.allow_self_signed_certs', false)) {
                throw new \Exception('El certificado debe estar firmado por AFIP. Los certificados autofirmados están deshabilitados.');
            }
        }

        $certCuit = $certData['subject']['CN'] ?? null;
        $companyCuit = preg_replace('/[^0-9]/', '', $company->national_id);
        $certCuitClean = preg_replace('/[^0-9]/', '', $certCuit ?? '');

        if ($certCuitClean !== $companyCuit) {
            throw new \Exception("El CUIT del certificado ({$certCuit}) no coincide con el CUIT de la empresa ({$company->national_id})");
        }

        $certPath = "afip/certificates/{$company->id}/certificate.crt";
        Storage::put($certPath, $certificateContent);
        
        $certificate = CompanyAfipCertificate::updateOrCreate(
            ['company_id' => $company->id],
            [
                'certificate_path' => $certPath,
                'encrypted_password' => $password ? Crypt::encryptString($password) : null,
                'valid_from' => date('Y-m-d', $certData['validFrom_time_t']),
                'valid_until' => date('Y-m-d', $certData['validTo_time_t']),
                'environment' => $environment,
                'is_active' => true,
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
        $certData = openssl_x509_parse($certificateContent);
        
        if (!$certData) {
            throw new \Exception('Certificado inválido');
        }

        // Validar que no sea autofirmado en producción o si no está permitido
        if ($this->isSelfSigned($certData)) {
            if ($environment === 'production') {
                throw new \Exception('El certificado debe estar firmado por AFIP. Los certificados autofirmados no son válidos en producción.');
            }
            if (!config('afip.allow_self_signed_certs', false)) {
                throw new \Exception('El certificado debe estar firmado por AFIP. Los certificados autofirmados están deshabilitados.');
            }
        }

        $certCuit = $certData['subject']['CN'] ?? null;
        $companyCuit = preg_replace('/[^0-9]/', '', $company->national_id);
        $certCuitClean = preg_replace('/[^0-9]/', '', $certCuit ?? '');

        if ($certCuitClean !== $companyCuit) {
            throw new \Exception("El CUIT del certificado ({$certCuit}) no coincide con el CUIT de la empresa ({$company->national_id})");
        }

        $certPath = "afip/certificates/{$company->id}/certificate.crt";
        $keyPath = "afip/certificates/{$company->id}/private.key";

        Storage::put($certPath, $certificateContent);
        Storage::put($keyPath, $privateKeyContent);

        $certificate = CompanyAfipCertificate::updateOrCreate(
            ['company_id' => $company->id],
            [
                'certificate_path' => $certPath,
                'private_key_path' => $keyPath,
                'encrypted_password' => $password ? Crypt::encryptString($password) : null,
                'valid_from' => date('Y-m-d', $certData['validFrom_time_t']),
                'valid_until' => date('Y-m-d', $certData['validTo_time_t']),
                'environment' => $environment,
                'is_active' => true,
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

        if ($certificate->certificate_path) {
            Storage::delete($certificate->certificate_path);
        }
        if ($certificate->private_key_path) {
            Storage::delete($certificate->private_key_path);
        }
        if ($certificate->csr_path) {
            Storage::delete($certificate->csr_path);
        }

        $certificate->delete();
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

        // TODO: Implementar test real con AFIP WSAA
        return [
            'success' => true,
            'message' => 'Certificado válido',
            'expires_in_days' => $certificate->valid_until->diffInDays(now()),
        ];
    }

    private function isSelfSigned(array $certData): bool
    {
        // Un certificado es autofirmado si el issuer y el subject son iguales
        $subject = $certData['subject'] ?? [];
        $issuer = $certData['issuer'] ?? [];
        
        return $subject === $issuer;
    }
}
