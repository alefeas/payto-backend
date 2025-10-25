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

    public function archived(string $companyId): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('viewAny', [Client::class, $company]);

        $clients = Client::where('company_id', $companyId)
            ->onlyTrashed()
            ->orderBy('deleted_at', 'desc')
            ->get();

        return $this->success($clients);
    }

    public function restore(string $companyId, string $clientId): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('update', [Client::class, $company]);

        $client = Client::where('company_id', $companyId)
            ->onlyTrashed()
            ->findOrFail($clientId);

        $client->restore();

        return $this->success($client, 'Cliente restaurado correctamente');
    }

    public function store(Request $request, string $companyId): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('create', [Client::class, $company]);

        $validated = $request->validate([
            'document_type' => 'nullable|in:CUIT,CUIL,DNI,Pasaporte,CDI',
            'document_number' => ['nullable', 'string', new \App\Rules\ValidNationalId()],
            'business_name' => 'nullable|string',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'address' => 'nullable|string', // Domicilio fiscal - opcional, AFIP no lo requiere para facturación
            'tax_condition' => 'required|in:registered_taxpayer,monotax,exempt,final_consumer',
        ]);
        
        // Require CUIT/CUIL for non-final_consumer
        if ($validated['tax_condition'] !== 'final_consumer') {
            if (empty($validated['document_number'])) {
                return $this->error('CUIT/CUIL es obligatorio para esta condición fiscal', 422);
            }
            if (!in_array($validated['document_type'], ['CUIT', 'CUIL'])) {
                return $this->error('Debe usar CUIT o CUIL para esta condición fiscal', 422);
            }
        }

        if (empty($validated['email']) && empty($validated['phone'])) {
            return $this->error('Debe proporcionar al menos un dato de contacto (email o teléfono)', 422);
        }

        // Check for duplicate (including soft deleted)
        $existing = Client::where('company_id', $companyId)
            ->where('document_number', $validated['document_number'])
            ->withTrashed()
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                return $this->error(
                    'Ya existe un cliente archivado con este CUIT. Restaura el cliente existente desde la sección "Clientes archivados" para editarlo.',
                    422
                );
            }
            return $this->error('Ya existe un cliente con este CUIT', 422);
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
            'document_type' => 'nullable|in:CUIT,CUIL,DNI,Pasaporte,CDI',
            'document_number' => ['nullable', 'string', new \App\Rules\ValidNationalId()],
            'business_name' => 'nullable|string',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'tax_condition' => 'sometimes|in:registered_taxpayer,monotax,exempt,final_consumer',
        ]);
        
        // Require CUIT/CUIL for non-final_consumer
        $taxCondition = $validated['tax_condition'] ?? $client->tax_condition;
        $docNumber = $validated['document_number'] ?? $client->document_number;
        $docType = $validated['document_type'] ?? $client->document_type;
        if ($taxCondition !== 'final_consumer') {
            if (empty($docNumber)) {
                return $this->error('CUIT/CUIL es obligatorio para esta condición fiscal', 422);
            }
            if (!in_array($docType, ['CUIT', 'CUIL'])) {
                return $this->error('Debe usar CUIT o CUIL para esta condición fiscal', 422);
            }
        }

        $email = $validated['email'] ?? $client->email;
        $phone = $validated['phone'] ?? $client->phone;
        if (empty($email) && empty($phone)) {
            return $this->error('Debe proporcionar al menos un dato de contacto (email o teléfono)', 422);
        }

        // Check if client has invoices - if so, block document_number change
        if (isset($validated['document_number']) && $validated['document_number'] !== $client->document_number) {
            $hasInvoices = \App\Models\Invoice::where('client_id', $clientId)->exists();
            
            if ($hasInvoices) {
                return $this->error(
                    'No se puede modificar el CUIT/DNI porque este cliente tiene facturas asociadas. Si necesitas cambiar el CUIT, crea un nuevo cliente.',
                    422
                );
            }
            
            // Check for duplicate (including soft deleted)
            $existing = Client::where('company_id', $companyId)
                ->where('document_number', $validated['document_number'])
                ->where('id', '!=', $clientId)
                ->withTrashed()
                ->first();

            if ($existing) {
                if ($existing->trashed()) {
                    return $this->error(
                        'Ya existe un cliente archivado con este CUIT. No puedes usar un CUIT duplicado.',
                        422
                    );
                }
                return $this->error('Ya existe un cliente con este CUIT', 422);
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

        // SoftDelete: El cliente se marca como archivado pero los datos persisten
        // El Libro IVA puede seguir accediendo con withTrashed()
        $client->delete();

        return $this->success(null, 'Cliente archivado correctamente');
    }
}
