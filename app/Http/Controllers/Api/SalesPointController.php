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
        $company = $user->companies()->findOrFail($companyId);
        
        $request->validate([
            'point_number' => 'required|integer|min:1|max:9999',
            'name' => 'nullable|string|max:100',
        ]);
        
        // Verificar que no exista
        $exists = CompanySalesPoint::where('company_id', $company->id)
            ->where('point_number', $request->point_number)
            ->exists();
            
        if ($exists) {
            return response()->json(['error' => 'El punto de venta ya existe'], 422);
        }
        
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
}
