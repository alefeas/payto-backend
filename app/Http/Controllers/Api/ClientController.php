<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Company;
use App\Traits\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function index(string $companyId): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('viewAny', [Client::class, $company]);

        $clients = Client::where('company_id', $companyId)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($clients);
    }

    public function store(Request $request, string $companyId): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('create', [Client::class, $company]);

        $validated = $request->validate([
            'document_type' => 'required|in:CUIT,CUIL,DNI,Pasaporte,CDI',
            'document_number' => 'required|string',
            'business_name' => 'nullable|string',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'tax_condition' => 'required|in:registered_taxpayer,monotax,exempt,final_consumer',
        ]);

        if (empty($validated['email']) && empty($validated['phone'])) {
            return $this->error('Debe proporcionar al menos un dato de contacto (email o teléfono)', 422);
        }

        // Check for duplicate
        $existing = Client::where('company_id', $companyId)
            ->where('document_number', $validated['document_number'])
            ->first();

        if ($existing) {
            return $this->error('Ya existe un cliente con este número de documento', 422);
        }

        $client = Client::create([
            'company_id' => $companyId,
            ...$validated,
        ]);

        return $this->success($client, 'Client created successfully', 201);
    }

    public function update(Request $request, string $companyId, string $clientId): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('update', [Client::class, $company]);

        $client = Client::where('company_id', $companyId)->findOrFail($clientId);

        $validated = $request->validate([
            'document_type' => 'sometimes|in:CUIT,CUIL,DNI,Pasaporte,CDI',
            'document_number' => 'sometimes|string',
            'business_name' => 'nullable|string',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'tax_condition' => 'sometimes|in:registered_taxpayer,monotax,exempt,final_consumer',
        ]);

        $email = $validated['email'] ?? $client->email;
        $phone = $validated['phone'] ?? $client->phone;
        if (empty($email) && empty($phone)) {
            return $this->error('Debe proporcionar al menos un dato de contacto (email o teléfono)', 422);
        }

        // Check for duplicate if document_number is being changed
        if (isset($validated['document_number']) && $validated['document_number'] !== $client->document_number) {
            $existing = Client::where('company_id', $companyId)
                ->where('document_number', $validated['document_number'])
                ->where('id', '!=', $clientId)
                ->first();

            if ($existing) {
                return $this->error('Ya existe un cliente con este número de documento', 422);
            }
        }

        $client->update($validated);

        return $this->success($client, 'Client updated successfully');
    }

    public function destroy(string $companyId, string $clientId): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('delete', [Client::class, $company]);

        $client = Client::where('company_id', $companyId)->findOrFail($clientId);

        // Check if client has invoices pending approval
        if ($client->invoices()->where('status', 'pending_approval')->exists()) {
            return $this->error('No se puede eliminar un cliente con facturas pendientes de aprobación', 422);
        }

        // Check if client has invoices with pending collections
        $hasUncollectedInvoices = $client->invoices()
            ->whereIn('status', ['approved', 'issued'])
            ->where(function($query) {
                $query->whereDoesntHave('payments')
                      ->orWhereRaw('(SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE invoice_id = invoices.id) < total');
            })
            ->exists();

        if ($hasUncollectedInvoices) {
            return $this->error('No se puede eliminar un cliente con facturas pendientes de cobro', 422);
        }

        $client->delete();

        return $this->success(null, 'Client deleted successfully');
    }
}
