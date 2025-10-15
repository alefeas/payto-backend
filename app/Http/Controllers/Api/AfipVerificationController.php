<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\AfipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AfipVerificationController extends Controller
{
    private $afipService;
    
    public function __construct(AfipService $afipService)
    {
        $this->afipService = $afipService;
    }
    
    /**
     * Validate CUIT with AFIP and get company data
     */
    public function validateCuit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cuit' => 'required|string|size:13', // Format: XX-XXXXXXXX-X
            'company_id' => 'required|string'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Check if company is AFIP verified
        $company = Company::findOrFail($request->company_id);
        
        if (!$company->isAfipVerified()) {
            return response()->json([
                'valid' => false,
                'message' => 'Para consultar datos de AFIP necesitás un certificado firmado por AFIP (testing o producción). Los certificados autofirmados no permiten conectarse con los servidores de AFIP.',
                'requires_verification' => true
            ], 403);
        }
        
        $result = $this->afipService->validateCuit($request->cuit);
        
        if (!$result['success']) {
            return response()->json([
                'valid' => false,
                'message' => $result['message']
            ], 400);
        }
        
        return response()->json([
            'valid' => true,
            'message' => 'CUIT validado correctamente',
            'data' => $result['data']
        ]);
    }
    
    /**
     * Upload and validate AFIP certificate for company verification
     */
    public function verifyCertificate(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        
        // Check authorization
        $this->authorize('update', $company);
        
        $validator = Validator::make($request->all(), [
            'certificate' => 'required|file|mimes:crt,pem,cer|max:2048',
            'private_key' => 'required|file|mimes:key,pem|max:2048'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Archivos inválidos',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $certificateFile = $request->file('certificate');
        $keyFile = $request->file('private_key');
        
        // Validate certificate
        $result = $this->afipService->validateCertificate(
            $certificateFile,
            $keyFile,
            $company->cuit
        );
        
        if (!$result['success']) {
            return response()->json([
                'message' => $result['message']
            ], 400);
        }
        
        // Store certificate files
        $certPath = $certificateFile->store('afip/certificates/' . $company->id, 'local');
        $keyPath = $keyFile->store('afip/keys/' . $company->id, 'local');
        
        // Update company
        $company->update([
            'verification_status' => 'verified',
            'afip_certificate_path' => $certPath,
            'afip_key_path' => $keyPath,
            'verified_at' => now()
        ]);
        
        return response()->json([
            'message' => 'Perfil verificado exitosamente',
            'data' => [
                'verification_status' => 'verified',
                'verified_at' => $company->verified_at,
                'certificate_valid_until' => $result['validTo']
            ]
        ]);
    }
    
    /**
     * Get verification status
     */
    public function getVerificationStatus($companyId)
    {
        $company = Company::findOrFail($companyId);
        
        $this->authorize('view', $company);
        
        return response()->json([
            'verification_status' => $company->verification_status,
            'verified_at' => $company->verified_at,
            'has_certificate' => !empty($company->afip_certificate_path)
        ]);
    }
}
