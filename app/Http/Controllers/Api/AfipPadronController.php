<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Afip\AfipPadronService;
use Illuminate\Http\Request;

class AfipPadronController extends Controller
{
    /**
     * Get own company fiscal data from AFIP
     */
    public function getOwnFiscalData(string $companyId)
    {
        $company = Company::findOrFail($companyId);
        
        $certificate = $company->afipCertificate;
        
        if (!$certificate || !$certificate->is_active) {
            return response()->json([
                'error' => 'No hay certificado AFIP activo configurado',
            ], 400);
        }

        try {
            $padronService = new AfipPadronService($certificate);
            $data = $padronService->getOwnFiscalData();
            
            $isMockMode = $certificate->environment !== 'production';
            
            return response()->json([
                'success' => true,
                'data' => $data,
                'mock_mode' => $isMockMode,
                'message' => $isMockMode 
                    ? 'Datos simulados (el servicio de padrón solo funciona en producción)'
                    : 'Datos obtenidos de AFIP',
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync company tax condition from AFIP
     */
    public function syncTaxCondition(string $companyId)
    {
        $company = Company::findOrFail($companyId);
        
        $certificate = $company->afipCertificate;
        
        if (!$certificate || !$certificate->is_active) {
            return response()->json([
                'error' => 'No hay certificado AFIP activo configurado. Sube tu certificado primero.',
            ], 400);
        }

        try {
            $padronService = new AfipPadronService($certificate);
            $data = $padronService->getOwnFiscalData();
            
            // Update company tax condition
            $company->update([
                'tax_condition' => $data['tax_condition'],
            ]);
            
            $isMockMode = $certificate->environment !== 'production';
            
            return response()->json([
                'success' => true,
                'tax_condition' => $data['tax_condition'],
                'mock_mode' => $isMockMode,
                'message' => $isMockMode 
                    ? 'Condición IVA actualizada con datos simulados'
                    : 'Condición IVA sincronizada exitosamente desde AFIP',
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search taxpayer data by CUIT/CUIL
     */
    public function searchByCuit(Request $request, string $companyId)
    {
        $request->validate([
            'cuit' => 'required|string|min:11|max:13',
        ]);

        $company = Company::findOrFail($companyId);
        
        $certificate = $company->afipCertificate;
        
        if (!$certificate || !$certificate->is_active) {
            return response()->json([
                'error' => 'No hay certificado AFIP activo configurado',
            ], 400);
        }

        try {
            $padronService = new AfipPadronService($certificate);
            $data = $padronService->getTaxpayerData($request->cuit);
            
            $isMockMode = $certificate->environment !== 'production';
            
            return response()->json([
                'success' => true,
                'data' => $data,
                'mock_mode' => $isMockMode,
                'message' => $isMockMode 
                    ? 'Datos simulados (el servicio de padrón solo funciona en producción)'
                    : 'Datos obtenidos de AFIP',
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
