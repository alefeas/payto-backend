<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Supplier;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('viewAny', [Supplier::class, $company]);
        $suppliers = Supplier::where('company_id', $companyId)->get();
        return response()->json($suppliers);
    }

    public function archived($companyId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('viewAny', [Supplier::class, $company]);
        $suppliers = Supplier::where('company_id', $companyId)
            ->onlyTrashed()
            ->orderBy('deleted_at', 'desc')
            ->get();
        return response()->json($suppliers);
    }

    public function restore($companyId, $id)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('update', [Supplier::class, $company]);
        
        $supplier = Supplier::where('company_id', $companyId)
            ->onlyTrashed()
            ->findOrFail($id);
        
        $supplier->restore();
        return response()->json(['message' => 'Proveedor restaurado correctamente', 'supplier' => $supplier]);
    }

    public function store(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('create', [Supplier::class, $company]);
        $validated = $request->validate([
            'document_type' => 'nullable|in:CUIT,CUIL,DNI,Pasaporte,CDI',
            'document_number' => ['nullable', 'string', 'max:20', new \App\Rules\ValidNationalId()],
            'business_name' => 'nullable|string|max:100',
            'first_name' => 'nullable|string|max:50',
            'last_name' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:200',
            'postal_code' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'tax_condition' => 'required|in:registered_taxpayer,monotax,exempt,final_consumer',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_type' => 'nullable|in:CA,CC',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_cbu' => 'nullable|string|max:22',
            'bank_alias' => 'nullable|string|max:50'
        ]);
        
        // Require CUIT/CUIL for non-final_consumer
        if ($validated['tax_condition'] !== 'final_consumer') {
            if (empty($validated['document_number'])) {
                return response()->json(['message' => 'CUIT/CUIL es obligatorio para esta condición fiscal'], 422);
            }
            if (!in_array($validated['document_type'], ['CUIT', 'CUIL'])) {
                return response()->json(['message' => 'Debe usar CUIT o CUIL para esta condición fiscal'], 422);
            }
        }

        if (empty($validated['email']) && empty($validated['phone'])) {
            return response()->json(['message' => 'Debe proporcionar al menos un dato de contacto (email o teléfono)'], 422);
        }

        // Check for duplicate (including soft deleted)
        $existing = Supplier::where('company_id', $companyId)
            ->where('document_number', $validated['document_number'])
            ->withTrashed()
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                return response()->json([
                    'message' => 'Ya existe un proveedor archivado con este CUIT. Restaura el proveedor existente desde la sección "Proveedores archivados" para editarlo.'
                ], 422);
            }
            return response()->json(['message' => 'Ya existe un proveedor con este CUIT'], 422);
        }

        $supplier = Supplier::create([...$validated, 'company_id' => $companyId]);
        return response()->json($supplier, 201);
    }

    public function update(Request $request, $companyId, $id)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('update', [Supplier::class, $company]);

        $supplier = Supplier::where('company_id', $companyId)->findOrFail($id);

        $validated = $request->validate([
            'document_type' => 'nullable|in:CUIT,CUIL,DNI,Pasaporte,CDI',
            'document_number' => ['nullable', 'string', 'max:20', new \App\Rules\ValidNationalId()],
            'business_name' => 'nullable|string|max:100',
            'first_name' => 'nullable|string|max:50',
            'last_name' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:200',
            'postal_code' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'tax_condition' => 'required|in:registered_taxpayer,monotax,exempt,final_consumer',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_type' => 'nullable|in:CA,CC',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_cbu' => 'nullable|string|max:22',
            'bank_alias' => 'nullable|string|max:50'
        ]);
        
        // Require CUIT/CUIL for non-final_consumer
        $taxCondition = $validated['tax_condition'] ?? $supplier->tax_condition;
        $docNumber = $validated['document_number'] ?? $supplier->document_number;
        $docType = $validated['document_type'] ?? $supplier->document_type;
        if ($taxCondition !== 'final_consumer') {
            if (empty($docNumber)) {
                return response()->json(['message' => 'CUIT/CUIL es obligatorio para esta condición fiscal'], 422);
            }
            if (!in_array($docType, ['CUIT', 'CUIL'])) {
                return response()->json(['message' => 'Debe usar CUIT o CUIL para esta condición fiscal'], 422);
            }
        }

        $email = $validated['email'] ?? $supplier->email;
        $phone = $validated['phone'] ?? $supplier->phone;
        if (empty($email) && empty($phone)) {
            return response()->json(['message' => 'Debe proporcionar al menos un dato de contacto (email o teléfono)'], 422);
        }

        // Check if supplier has invoices - if so, block document_number change
        if (isset($validated['document_number']) && $validated['document_number'] !== $supplier->document_number) {
            $hasInvoices = \App\Models\Invoice::where('supplier_id', $id)->exists();
            
            if ($hasInvoices) {
                return response()->json([
                    'message' => 'No se puede modificar el CUIT/DNI porque este proveedor tiene facturas asociadas. Si necesitas cambiar el CUIT, crea un nuevo proveedor.'
                ], 422);
            }
            
            // Check for duplicate (including soft deleted)
            $existing = Supplier::where('company_id', $companyId)
                ->where('document_number', $validated['document_number'])
                ->where('id', '!=', $id)
                ->withTrashed()
                ->first();

            if ($existing) {
                if ($existing->trashed()) {
                    return response()->json([
                        'message' => 'Ya existe un proveedor archivado con este CUIT. No puedes usar un CUIT duplicado.'
                    ], 422);
                }
                return response()->json(['message' => 'Ya existe un proveedor con este CUIT'], 422);
            }
        }

        $supplier->update($validated);
        return response()->json($supplier);
    }

    public function destroy($companyId, $id)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('delete', [Supplier::class, $company]);

        $supplier = Supplier::where('company_id', $companyId)->findOrFail($id);
        
        // SoftDelete: El proveedor se marca como archivado pero los datos persisten
        // El Libro IVA puede seguir accediendo con withTrashed()
        $supplier->delete();
        return response()->json(['message' => 'Proveedor archivado correctamente'], 200);
    }
}
