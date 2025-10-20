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
        
        // Si está en PRODUCCIÓN y tiene certificado, validar con AFIP
        if ($company->afipCertificate && 
            $company->afipCertificate->is_active && 
            $company->afipCertificate->environment === 'production') {
            
            try {
                $webServiceClient = new \App\Services\Afip\AfipWebServiceClient(
                    $company->afipCertificate,
                    'wsfe'
                );
                
                $afipSalesPoints = $webServiceClient->getSalesPoints();
                $pointExists = false;
                
                foreach ($afipSalesPoints as $afipSp) {
                    if ($afipSp['point_number'] == $request->point_number) {
                        $pointExists = true;
                        // Si AFIP tiene descripción, usarla
                        if (!$request->name && $afipSp['description']) {
                            $request->merge(['name' => $afipSp['description']]);
                        }
                        break;
                    }
                }
                
                if (!$pointExists) {
                    return response()->json([
                        'error' => 'El punto de venta no existe en AFIP. Debes darlo de alta en AFIP primero o usar "Sincronizar con AFIP".'
                    ], 422);
                }
            } catch (\Exception $e) {
                \Log::warning('No se pudo validar punto de venta con AFIP', [
                    'error' => $e->getMessage(),
                    'company_id' => $company->id,
                ]);
                // En producción, si falla la validación, no permitir crear
                return response()->json([
                    'error' => 'No se pudo validar con AFIP: ' . $e->getMessage()
                ], 500);
            }
        }
        
        // Crear punto de venta
        $salesPoint = CompanySalesPoint::create([
            'company_id' => $company->id,
            'point_number' => $request->point_number,
            'name' => $request->name,
            'is_active' => true,
        ]);
        
        return response()->json(['data' => $salesPoint], 201);
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
    
    public function resetVouchers($companyId, $salesPoint)
    {
        $user = Auth::user();
        $company = $user->companies()->findOrFail($companyId);
        
        // Solo owner puede resetear
        $member = $company->members()->where('user_id', $user->id)->first();
        if (!$member || $member->role !== 'owner') {
            return response()->json(['error' => 'Solo el propietario puede reiniciar números'], 403);
        }
        
        \DB::beginTransaction();
        try {
            $deleted = \App\Models\Invoice::where('issuer_company_id', $company->id)
                ->where('sales_point', $salesPoint)
                ->delete();
            
            \DB::commit();
            
            return response()->json([
                'message' => 'Números de comprobante reiniciados',
                'deleted_invoices' => $deleted,
                'next_number' => 1,
            ]);
        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
