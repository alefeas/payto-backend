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
        $company = Auth::user()->companies()->findOrFail($companyId);
        
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
            'CN' => $companyName,
            'serialNumber' => "CUIT {$cuit}",
        ];

        $csr = openssl_csr_new($dn, $privKey, $config);
        if (!$csr) {
            return response()->json(['error' => 'Error al generar CSR'], 500);
        }

        openssl_csr_export($csr, $csrOut);
        openssl_pkey_export($privKey, $privKeyOut);

        return response()->json([
            'csr' => $csrOut,
            'private_key' => $privKeyOut,
        ]);
    }

    public function show($companyId)
    {
        $company = Auth::user()->companies()->findOrFail($companyId);
        $certificate = $company->afipCertificates()->where('is_active', true)->first();

        if (!$certificate) {
            return response()->json(['data' => null], 404);
        }

        return response()->json(['data' => new AfipCertificateResource($certificate)]);
    }

    public function uploadCertificate(Request $request, $companyId)
    {
        return $this->store($request);
    }

    public function uploadManual(Request $request, $companyId)
    {
        return $this->store($request);
    }

    public function testConnection($companyId)
    {
        $company = Auth::user()->companies()->findOrFail($companyId);
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

    public function store(Request $request)
    {
        $request->validate([
            'certificate' => 'required|file|mimes:crt,pem',
            'private_key' => 'required|file|mimes:key,pem',
            'password' => 'nullable|string',
            'environment' => 'required|in:testing,production',
        ]);

        $company = Auth::user()->company;

        try {
            $certificateService = new AfipCertificateService();
            
            $certificate = $certificateService->storeCertificate(
                $company,
                $request->file('certificate'),
                $request->file('private_key'),
                $request->password,
                $request->environment
            );

            return response()->json([
                'message' => 'Certificate uploaded successfully',
                'data' => new AfipCertificateResource($certificate),
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function update(Request $request, CompanyAfipCertificate $certificate)
    {
        if ($certificate->company_id !== Auth::user()->company_id) {
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
        $company = Auth::user()->companies()->findOrFail($companyId);
        
        $certificateService = new AfipCertificateService();
        $certificateService->deleteCertificate($company);

        return response()->json(['message' => 'Certificate deleted successfully']);
    }

    public function activate(CompanyAfipCertificate $certificate)
    {
        if ($certificate->company_id !== Auth::user()->company_id) {
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
        if ($certificate->company_id !== Auth::user()->company_id) {
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
}
