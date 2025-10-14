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

        // Check for duplicate
        $existing = Client::where('company_id', $companyId)
            ->where('document_number', $validated['document_number'])
            ->first();

        if ($existing) {
            return $this->error('Client with this document number already exists', 422);
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

        // Check for duplicate if document_number is being changed
        if (isset($validated['document_number']) && $validated['document_number'] !== $client->document_number) {
            $existing = Client::where('company_id', $companyId)
                ->where('document_number', $validated['document_number'])
                ->where('id', '!=', $clientId)
                ->first();

            if ($existing) {
                return $this->error('Client with this document number already exists', 422);
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

        // Check if client has invoices
        if ($client->invoices()->count() > 0) {
            return $this->error('Cannot delete client with existing invoices', 422);
        }

        $client->delete();

        return $this->success(null, 'Client deleted successfully');
    }
}
