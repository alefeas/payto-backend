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

    public function store(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('create', [Supplier::class, $company]);
        $validated = $request->validate([
            'document_type' => 'required|in:CUIT,CUIL,DNI,Pasaporte,CDI',
            'document_number' => 'required|string|max:20',
            'business_name' => 'nullable|string|max:100',
            'first_name' => 'nullable|string|max:50',
            'last_name' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:200',
            'tax_condition' => 'required|in:registered_taxpayer,monotax,exempt,final_consumer',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_type' => 'nullable|in:CA,CC',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_cbu' => 'nullable|string|max:22',
            'bank_alias' => 'nullable|string|max:50'
        ]);

        if (empty($validated['email']) && empty($validated['phone'])) {
            return response()->json(['message' => 'Debe proporcionar al menos un dato de contacto (email o teléfono)'], 422);
        }

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
        $company = Company::findOrFail($companyId);
        $this->authorize('update', [Supplier::class, $company]);

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
            'tax_condition' => 'required|in:registered_taxpayer,monotax,exempt,final_consumer',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_type' => 'nullable|in:CA,CC',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_cbu' => 'nullable|string|max:22',
            'bank_alias' => 'nullable|string|max:50'
        ]);

        $email = $validated['email'] ?? $supplier->email;
        $phone = $validated['phone'] ?? $supplier->phone;
        if (empty($email) && empty($phone)) {
            return response()->json(['message' => 'Debe proporcionar al menos un dato de contacto (email o teléfono)'], 422);
        }

        $supplier->update($validated);
        return response()->json($supplier);
    }

    public function destroy($companyId, $id)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('delete', [Supplier::class, $company]);

        $supplier = Supplier::where('company_id', $companyId)->findOrFail($id);
        
        // Check if supplier has invoices pending approval
        if ($supplier->invoices()->where('status', 'pending_approval')->exists()) {
            return response()->json(['message' => 'No se puede eliminar un proveedor con facturas pendientes de aprobación'], 422);
        }
        
        // Check if supplier has invoices with pending payments
        $hasUnpaidInvoices = $supplier->invoices()
            ->whereIn('status', ['approved', 'issued'])
            ->where(function($query) {
                $query->whereDoesntHave('payments')
                      ->orWhereRaw('(SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE invoice_id = invoices.id) < total');
            })
            ->exists();
        
        if ($hasUnpaidInvoices) {
            return response()->json(['message' => 'No se puede eliminar un proveedor con facturas pendientes de pago'], 422);
        }
        
        $supplier->delete();
        return response()->json(null, 204);
    }
}
