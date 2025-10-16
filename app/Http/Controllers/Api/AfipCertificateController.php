<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Afip\AfipCertificateService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AfipCertificateController extends Controller
{
    use ApiResponse;

    private AfipCertificateService $certificateService;

    public function __construct(AfipCertificateService $certificateService)
    {
        $this->certificateService = $certificateService;
    }

    public function show(string $companyId): JsonResponse
    {
        $company = Company::whereHas('members', function ($query) {
            $query->where('user_id', auth()->id())
                  ->whereIn('role', ['owner', 'administrator'])
                  ->where('is_active', true);
        })->findOrFail($companyId);

        $certificate = $this->certificateService->getCertificate($company);

        if (!$certificate || !$certificate->certificate_path) {
            return $this->error('No hay certificado configurado', 404);
        }

        try {
            $certContent = \Illuminate\Support\Facades\Storage::get($certificate->certificate_path);
            $certData = openssl_x509_parse($certContent);
            $isSelfSigned = ($certData['subject'] ?? []) === ($certData['issuer'] ?? []);
        } catch (\Exception $e) {
            $isSelfSigned = false;
        }

        return $this->success([
            'id' => $certificate->id,
            'isActive' => $certificate->is_active,
            'validFrom' => $certificate->valid_from?->toIso8601String(),
            'validUntil' => $certificate->valid_until?->toIso8601String(),
            'isExpired' => $certificate->isExpired(),
            'isExpiringSoon' => $certificate->isExpiringSoon(),
            'environment' => $certificate->environment,
            'hasValidToken' => $certificate->hasValidToken(),
            'isSelfSigned' => $isSelfSigned,
        ]);
    }

    public function generateCSR(string $companyId): JsonResponse
    {
        $company = Company::whereHas('members', function ($query) {
            $query->where('user_id', auth()->id())
                  ->whereIn('role', ['owner', 'administrator'])
                  ->where('is_active', true);
        })->findOrFail($companyId);

        $result = $this->certificateService->generateCSR($company);

        return $this->success([
            'csr' => $result['csr'],
            'certificateId' => $result['certificate_id'],
        ], 'CSR generado exitosamente');
    }

    public function uploadCertificate(Request $request, string $companyId): JsonResponse
    {
        $company = Company::whereHas('members', function ($query) {
            $query->where('user_id', auth()->id())
                  ->whereIn('role', ['owner', 'administrator'])
                  ->where('is_active', true);
        })->findOrFail($companyId);

        $request->validate([
            'certificate' => 'required|string',
            'password' => 'nullable|string',
            'environment' => 'nullable|in:testing,production',
        ]);

        $certificate = $this->certificateService->uploadCertificate(
            $company,
            $request->input('certificate'),
            $request->input('password'),
            $request->input('environment', 'testing')
        );

        // Actualizar condición fiscal desde AFIP
        try {
            $afipData = app(\App\Services\Afip\AfipVerificationService::class)
                ->getContribuyenteData($company->national_id, $company);
            
            if ($afipData && isset($afipData['tax_condition'])) {
                $company->update(['tax_condition' => $afipData['tax_condition']]);
            }
        } catch (\Exception $e) {
            \Log::warning('No se pudo actualizar condición fiscal desde AFIP: ' . $e->getMessage());
        }

        return $this->success([
            'id' => $certificate->id,
            'isActive' => $certificate->is_active,
            'validUntil' => $certificate->valid_until?->toIso8601String(),
            'taxCondition' => $company->tax_condition,
        ], 'Certificado subido exitosamente');
    }

    public function uploadManual(Request $request, string $companyId): JsonResponse
    {
        $company = Company::whereHas('members', function ($query) {
            $query->where('user_id', auth()->id())
                  ->whereIn('role', ['owner', 'administrator'])
                  ->where('is_active', true);
        })->findOrFail($companyId);

        $request->validate([
            'certificate' => 'required|string',
            'private_key' => 'required|string',
            'password' => 'nullable|string',
            'environment' => 'nullable|in:testing,production',
        ]);

        $certificate = $this->certificateService->uploadManualCertificate(
            $company,
            $request->input('certificate'),
            $request->input('private_key'),
            $request->input('password'),
            $request->input('environment', 'testing')
        );

        // Actualizar condición fiscal desde AFIP
        try {
            $afipData = app(\App\Services\Afip\AfipVerificationService::class)
                ->getContribuyenteData($company->national_id, $company);
            
            if ($afipData && isset($afipData['tax_condition'])) {
                $company->update(['tax_condition' => $afipData['tax_condition']]);
            }
        } catch (\Exception $e) {
            \Log::warning('No se pudo actualizar condición fiscal desde AFIP: ' . $e->getMessage());
        }

        return $this->success([
            'id' => $certificate->id,
            'isActive' => $certificate->is_active,
            'validUntil' => $certificate->valid_until?->toIso8601String(),
            'taxCondition' => $company->tax_condition,
        ], 'Certificado subido exitosamente');
    }

    public function testConnection(string $companyId): JsonResponse
    {
        $company = Company::whereHas('members', function ($query) {
            $query->where('user_id', auth()->id())
                  ->whereIn('role', ['owner', 'administrator'])
                  ->where('is_active', true);
        })->findOrFail($companyId);

        $result = $this->certificateService->testConnection($company);

        if ($result['success']) {
            return $this->success($result, $result['message']);
        }

        return $this->error($result['message'], 400);
    }

    public function updateTaxCondition(string $companyId): JsonResponse
    {
        $company = Company::whereHas('members', function ($query) {
            $query->where('user_id', auth()->id())
                  ->whereIn('role', ['owner', 'administrator'])
                  ->where('is_active', true);
        })->findOrFail($companyId);

        try {
            $afipData = app(\App\Services\Afip\AfipVerificationService::class)
                ->getContribuyenteData($company->national_id, $company);
            
            if (!$afipData || !isset($afipData['tax_condition'])) {
                return $this->error('No se pudo obtener la condición fiscal desde AFIP', 400);
            }

            $company->update(['tax_condition' => $afipData['tax_condition']]);

            return $this->success([
                'taxCondition' => $company->tax_condition,
                'businessName' => $afipData['business_name'] ?? null,
            ], 'Condición fiscal actualizada desde AFIP');
        } catch (\Exception $e) {
            return $this->error('Error al consultar AFIP: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(string $companyId): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        
        $member = $company->members()
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->first();

        if (!$member || $member->role !== 'owner') {
            return $this->error('Solo el propietario puede eliminar el certificado AFIP', 403);
        }

        $this->certificateService->deleteCertificate($company);

        return $this->success(null, 'Certificado eliminado exitosamente');
    }
}
