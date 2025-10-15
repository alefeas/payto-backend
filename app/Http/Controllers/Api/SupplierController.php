<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request, $companyId)
    {
        $suppliers = Supplier::where('company_id', $companyId)->get();
        return response()->json($suppliers);
    }

    public function store(Request $request, $companyId)
    {
        $validated = $request->validate([
            'document_type' => 'required|in:CUIT,CUIL,DNI,Pasaporte,CDI',
            'document_number' => 'required|string|max:20',
            'business_name' => 'nullable|string|max:100',
            'first_name' => 'nullable|string|max:50',
            'last_name' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:200',
            'tax_condition' => 'required|in:registered_taxpayer,monotax,exempt,final_consumer'
        ]);

        $exists = Supplier::where('company_id', $companyId)
            ->where('document_number', $validated['document_number'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'El proveedor ya existe'], 409);
        }

        $supplier = Supplier::create([...$validated, 'company_id' => $companyId]);
        return response()->json($supplier, 201);
    }

    public function update(Request $request, $companyId, $id)
    {
        $supplier = Supplier::where('company_id', $companyId)->findOrFail($id);

        $validated = $request->validate([
            'document_type' => 'required|in:CUIT,CUIL,DNI,Pasaporte,CDI',
            'document_number' => 'required|string|max:20',
            'business_name' => 'nullable|string|max:100',
            'first_name' => 'nullable|string|max:50',
            'last_name' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:200',
            'tax_condition' => 'required|in:registered_taxpayer,monotax,exempt,final_consumer'
        ]);

        $supplier->update($validated);
        return response()->json($supplier);
    }

    public function destroy($companyId, $id)
    {
        $supplier = Supplier::where('company_id', $companyId)->findOrFail($id);
        $supplier->delete();
        return response()->json(null, 204);
    }
}
