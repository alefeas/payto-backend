<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanySalesPoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SalesPointController extends Controller
{
    public function index($companyId)
    {
        $user = Auth::user();
        $company = $user->companies()->findOrFail($companyId);
        
        $salesPoints = $company->salesPoints()->orderBy('point_number')->get();
        
        return response()->json(['data' => $salesPoints]);
    }

    public function store(Request $request, $companyId)
    {
        $user = Auth::user();
        $company = $user->companies()->with('afipCertificate')->findOrFail($companyId);
        
        $request->validate([
            'point_number' => 'required|integer|min:1|max:9999',
            'name' => 'nullable|string|max:100',
        ]);
        
        // Verificar que no exista localmente
        $exists = CompanySalesPoint::where('company_id', $company->id)
            ->where('point_number', $request->point_number)
            ->exists();
            
        if ($exists) {
            return response()->json(['error' => 'El punto de venta ya existe'], 422);
        }
        
        // Validar con AFIP que el punto de venta esté autorizado
        if (!$company->afipCertificate || !$company->afipCertificate->is_active) {
            return response()->json(['error' => 'Certificado AFIP requerido para validar puntos de venta'], 403);
        }
        
        try {
            $webServiceClient = new \App\Services\Afip\AfipWebServiceClient(
                $company->afipCertificate,
                'wsfe'
            );
            
            $afipSalesPoints = $webServiceClient->getSalesPoints();
            
            $existsInAfip = false;
            $afipDescription = null;
            
            foreach ($afipSalesPoints as $afipSp) {
                if ($afipSp['point_number'] == $request->point_number) {
                    if ($afipSp['blocked'] || $afipSp['drop_date']) {
                        return response()->json([
                            'error' => 'Este punto de venta está bloqueado o dado de baja en AFIP'
                        ], 422);
                    }
                    $existsInAfip = true;
                    $afipDescription = $afipSp['description'];
                    break;
                }
            }
            
            if (!$existsInAfip) {
                return response()->json([
                    'error' => 'Este punto de venta no está autorizado en AFIP. Primero debes darlo de alta en el portal de AFIP.'
                ], 422);
            }
            
            // Si existe en AFIP, crear con la descripción de AFIP si no se proporcionó una
            $salesPoint = CompanySalesPoint::create([
                'company_id' => $company->id,
                'point_number' => $request->point_number,
                'name' => $request->name ?: $afipDescription,
                'is_active' => true,
            ]);
            
            return response()->json(['data' => $salesPoint], 201);
            
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al validar con AFIP: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $companyId, $salesPointId)
    {
        $user = Auth::user();
        $company = $user->companies()->findOrFail($companyId);
        
        $salesPoint = CompanySalesPoint::where('company_id', $company->id)
            ->findOrFail($salesPointId);
        
        $request->validate([
            'name' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);
        
        $salesPoint->update($request->only(['name', 'is_active']));
        
        return response()->json(['data' => $salesPoint]);
    }

    public function destroy($companyId, $salesPointId)
    {
        $user = Auth::user();
        $company = $user->companies()->findOrFail($companyId);
        
        $salesPoint = CompanySalesPoint::where('company_id', $company->id)
            ->findOrFail($salesPointId);
        
        // Verificar que no tenga facturas
        $hasInvoices = $company->issuedInvoices()
            ->where('sales_point', $salesPoint->point_number)
            ->exists();
            
        if ($hasInvoices) {
            return response()->json(['error' => 'No se puede eliminar un punto de venta con facturas'], 422);
        }
        
        $salesPoint->delete();
        
        return response()->json(['message' => 'Punto de venta eliminado']);
    }

    public function syncFromAfip($companyId)
    {
        $user = Auth::user();
        $company = $user->companies()->with('afipCertificate')->findOrFail($companyId);
        
        if (!$company->afipCertificate || !$company->afipCertificate->is_active) {
            return response()->json(['error' => 'Certificado AFIP requerido'], 403);
        }
        
        try {
            $webServiceClient = new \App\Services\Afip\AfipWebServiceClient(
                $company->afipCertificate,
                'wsfe'
            );
            
            $afipSalesPoints = $webServiceClient->getSalesPoints();
            
            $synced = 0;
            $created = 0;
            
            foreach ($afipSalesPoints as $afipSp) {
                // Saltar puntos de venta bloqueados o dados de baja
                if ($afipSp['blocked'] || $afipSp['drop_date']) {
                    continue;
                }
                
                $existing = CompanySalesPoint::where('company_id', $company->id)
                    ->where('point_number', $afipSp['point_number'])
                    ->first();
                
                if ($existing) {
                    $existing->update([
                        'name' => $afipSp['description'] ?? $existing->name,
                        'is_active' => true,
                    ]);
                    $synced++;
                } else {
                    CompanySalesPoint::create([
                        'company_id' => $company->id,
                        'point_number' => $afipSp['point_number'],
                        'name' => $afipSp['description'],
                        'is_active' => true,
                    ]);
                    $created++;
                }
            }
            
            return response()->json([
                'message' => 'Sincronización completada',
                'synced' => $synced,
                'created' => $created,
                'total' => count($afipSalesPoints),
            ]);
            
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
