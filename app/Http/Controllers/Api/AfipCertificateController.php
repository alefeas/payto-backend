<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AfipCertificateResource;
use App\Models\Company;
use App\Models\CompanyAfipCertificate;
use App\Services\Afip\AfipCertificateService;
use App\Services\Afip\AfipVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AfipCertificateController extends Controller
{
    public function generateCSR(Request $request, $companyId)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }
        
        $company = $user->companies()->findOrFail($companyId);
        
        $cuit = preg_replace('/[^0-9]/', '', $company->national_id ?: '');
        $companyName = $company->business_name ?: $company->name;

        
        if (strlen($cuit) !== 11) {
            return response()->json([
                'error' => 'La empresa debe tener un CUIT válido de 11 dígitos. Actualiza los datos de la empresa.'
            ], 422);
        }

        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        
        if (DIRECTORY_SEPARATOR === '\\') {
            $paths = [
                'C:/xampp/apache/conf/openssl.cnf',
                'C:/xampp/php/extras/openssl/openssl.cnf',
                getenv('OPENSSL_CONF'),
            ];
            foreach ($paths as $path) {
                if ($path && file_exists($path)) {
                    $config['config'] = $path;
                    break;
                }
            }
        }

        $privKey = openssl_pkey_new($config);
        if (!$privKey) {
            return response()->json(['error' => 'Error al generar clave privada'], 500);
        }

        $dn = [
            'countryName' => 'AR',
            'stateOrProvinceName' => 'Buenos Aires',
            'localityName' => 'CABA',
            'organizationName' => $companyName,
            'commonName' => $cuit,
            'serialNumber' => "CUIT {$cuit}",
        ];

        $csr = openssl_csr_new($dn, $privKey, $config);
        if (!$csr) {
            return response()->json(['error' => 'Error al generar CSR'], 500);
        }

        $csrOut = '';
        $privKeyOut = '';
        
        if (!openssl_csr_export($csr, $csrOut)) {
            return response()->json(['error' => 'Error al exportar CSR'], 500);
        }
        
        if (!openssl_pkey_export($privKey, $privKeyOut, null, $config)) {
            return response()->json(['error' => 'Error al exportar clave privada'], 500);
        }
        
        // GUARDAR CSR y clave privada en el sistema
        $csrPath = "afip/certificates/{$company->id}/csr.pem";
        $keyPath = "afip/certificates/{$company->id}/private.key";
        
        \Storage::put($csrPath, $csrOut);
        \Storage::put($keyPath, $privKeyOut);
        
        // Crear o actualizar registro en la base de datos
        $existingCert = CompanyAfipCertificate::where('company_id', $company->id)->first();
        $preservedToken = [];
        if ($existingCert && $existingCert->current_token && $existingCert->current_sign) {
            $preservedToken = [
                'current_token' => $existingCert->current_token,
                'current_sign' => $existingCert->current_sign,
                'token_expires_at' => $existingCert->token_expires_at,
            ];
        }
        
        CompanyAfipCertificate::updateOrCreate(
            ['company_id' => $company->id],
            array_merge([
                'csr_path' => $csrPath,
                'private_key_path' => $keyPath,
                'is_active' => false,
            ], $preservedToken)
        );

        return response()->json([
            'csr' => $csrOut,
            'private_key' => $privKeyOut,
        ]);
    }

    public function show($companyId)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }
        
        $company = $user->companies()->findOrFail($companyId);
        $certificate = $company->afipCertificates()->where('is_active', true)->first();

        if (!$certificate) {
            return response()->json(['data' => null], 404);
        }

        return response()->json(['data' => new AfipCertificateResource($certificate)]);
    }

    public function uploadCertificate(Request $request, $companyId)
    {
        return $this->store($request, $companyId);
    }

    public function uploadManual(Request $request, $companyId)
    {
        return $this->store($request, $companyId);
    }
    
    public function uploadWithCSR(Request $request, $companyId)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }
        
        $company = $user->companies()->findOrFail($companyId);
        
        try {
            $csrContent = $request->input('csr');
            $certContent = $request->input('certificate');
            $environment = $request->input('environment', 'testing');
            
            if (!$csrContent || !$certContent) {
                throw new \Exception('Debes proporcionar el CSR y el certificado');
            }
            
            // Extraer clave privada del CSR (si está incluida)
            $csrPubKey = openssl_csr_get_public_key($csrContent);
            if (!$csrPubKey) {
                throw new \Exception('CSR inválido');
            }
            
            // Validar que el certificado coincida con el CSR
            $certPubKey = openssl_pkey_get_public($certContent);
            if (!$certPubKey) {
                throw new \Exception('Certificado inválido');
            }
            
            $csrDetails = openssl_pkey_get_details($csrPubKey);
            $certDetails = openssl_pkey_get_details($certPubKey);
            
            if ($csrDetails['rsa']['n'] !== $certDetails['rsa']['n']) {
                throw new \Exception('El certificado NO coincide con el CSR proporcionado');
            }
            
            // Buscar si ya existe un certificado con clave privada
            $existingCert = CompanyAfipCertificate::where('company_id', $company->id)->first();
            if (!$existingCert || !$existingCert->private_key_path || !Storage::exists($existingCert->private_key_path)) {
                throw new \Exception('No se encontró la clave privada. Usa el método manual para subir certificado y clave privada juntos.');
            }
            
            // Guardar CSR
            $csrPath = "afip/certificates/{$company->id}/csr.pem";
            \Storage::put($csrPath, $csrContent);
            
            $existingCert->update(['csr_path' => $csrPath]);
            
            // Ahora usar el método normal
            $certificateService = new AfipCertificateService();
            $certificate = $certificateService->uploadCertificate(
                $company,
                $certContent,
                null,
                $environment
            );
            
            return response()->json([
                'message' => 'Certificado configurado exitosamente',
                'data' => new AfipCertificateResource($certificate),
            ], 201);
            
        } catch (\Exception $e) {
            \Log::error('Certificate with CSR upload failed', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function testConnection($companyId)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }
        
        $company = $user->companies()->findOrFail($companyId);
        $certificate = $company->afipCertificates()->where('is_active', true)->first();

        if (!$certificate) {
            return response()->json(['success' => false, 'message' => 'No certificate found'], 404);
        }

        try {
            $verificationService = new AfipVerificationService($certificate);
            $result = $verificationService->verifyConnection();
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request, $companyId = null)
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }
        
        // Si viene companyId en la URL, usar esa empresa
        if ($companyId) {
            $company = $user->companies()->findOrFail($companyId);
        } else {
            // Fallback al método anterior
            $company = $user->company;
            if (!$company) {
                $company = $user->companies()->first();
            }
            if (!$company) {
                return response()->json(['error' => 'No se encontró una empresa asociada al usuario'], 400);
            }
        }

        try {
            // Log para debugging
            \Log::info('Certificate upload attempt', [
                'has_file' => $request->hasFile('certificate'),
                'has_input' => $request->has('certificate'),
                'all_keys' => array_keys($request->all()),
                'files_keys' => array_keys($request->allFiles()),
            ]);
            
            // Validar que al menos venga el certificado
            if (!$request->hasFile('certificate') && !$request->has('certificate')) {
                throw new \Exception('Debe proporcionar un certificado');
            }

            // Obtener contenido del certificado
            $certContent = null;
            if ($request->hasFile('certificate')) {
                $certFile = $request->file('certificate');
                \Log::info('Certificate file received', [
                    'is_valid' => $certFile->isValid(),
                    'size' => $certFile->getSize(),
                    'mime' => $certFile->getMimeType(),
                ]);
                if ($certFile->isValid()) {
                    $certContent = file_get_contents($certFile->getRealPath());
                }
            } elseif ($request->has('certificate')) {
                $certContent = $request->input('certificate');
                \Log::info('Certificate text received', [
                    'length' => strlen($certContent),
                    'starts_with' => substr($certContent, 0, 50),
                ]);
            }

            if (!$certContent) {
                \Log::error('Certificate content is empty');
                throw new \Exception('No se pudo leer el certificado');
            }
            
            \Log::info('Certificate content loaded', [
                'length' => strlen($certContent),
                'has_begin' => str_contains($certContent, 'BEGIN CERTIFICATE'),
            ]);

            // Obtener contenido de la clave privada si existe
            $keyContent = null;
            if ($request->hasFile('private_key')) {
                $keyFile = $request->file('private_key');
                if ($keyFile->isValid()) {
                    $keyContent = file_get_contents($keyFile->getRealPath());
                }
            } elseif ($request->has('private_key')) {
                $keyContent = $request->input('private_key');
            }

            $certificateService = new AfipCertificateService();
            
            if ($keyContent) {
                // Manual upload with both certificate and key
                $certificate = $certificateService->uploadManualCertificate(
                    $company,
                    $certContent,
                    $keyContent,
                    $request->password,
                    $request->environment ?? 'testing'
                );
            } else {
                // Assisted upload with only certificate
                $certificate = $certificateService->uploadCertificate(
                    $company,
                    $certContent,
                    $request->password,
                    $request->environment ?? 'testing'
                );
            }

            return response()->json([
                'message' => 'Certificado configurado exitosamente',
                'data' => new AfipCertificateResource($certificate),
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Certificate upload failed', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function update(Request $request, CompanyAfipCertificate $certificate)
    {
        $user = Auth::user();
        if (!$user || $certificate->company_id !== $user->company_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'environment' => 'sometimes|in:testing,production',
        ]);

        $certificate->update($request->only(['environment']));

        return response()->json([
            'message' => 'Certificate updated successfully',
            'data' => new AfipCertificateResource($certificate->fresh()),
        ]);
    }

    public function destroy($companyId)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }
        
        $company = $user->companies()->findOrFail($companyId);
        
        $certificateService = new AfipCertificateService();
        $certificateService->deleteCertificate($company);

        return response()->json(['message' => 'Certificate deleted successfully']);
    }

    public function activate(CompanyAfipCertificate $certificate)
    {
        $user = Auth::user();
        if (!$user || $certificate->company_id !== $user->company_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $certificateService = new AfipCertificateService();
        $certificateService->activateCertificate($certificate);

        return response()->json([
            'message' => 'Certificate activated successfully',
            'data' => new AfipCertificateResource($certificate->fresh()),
        ]);
    }

    public function verify(CompanyAfipCertificate $certificate)
    {
        $user = Auth::user();
        if (!$user || $certificate->company_id !== $user->company_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $verificationService = new AfipVerificationService($certificate);
            $result = $verificationService->verifyConnection();

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateTaxCondition($companyId)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }
        
        $company = $user->companies()->findOrFail($companyId);
        $certificate = $company->afipCertificates()->where('is_active', true)->first();

        if (!$certificate) {
            return response()->json(['error' => 'No hay certificado AFIP activo'], 404);
        }

        try {
            $verificationService = new AfipVerificationService($certificate);
            $taxCondition = $verificationService->getTaxCondition();
            
            $company->update(['tax_condition' => $taxCondition]);

            return response()->json([
                'success' => true,
                'data' => ['taxCondition' => $taxCondition]
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
