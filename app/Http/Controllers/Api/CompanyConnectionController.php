<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyConnection;
use App\Models\Invoice;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyConnectionController extends Controller
{
    use AuthorizesRequests;

    public function index($companyId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('viewAny', [CompanyConnection::class, $company]);
        
        $connections = CompanyConnection::where(function($query) use ($companyId) {
            $query->where('company_id', $companyId)
                  ->orWhere('connected_company_id', $companyId);
        })
        ->where('status', 'connected')
        ->with(['company', 'connectedCompany', 'requestedByUser'])
        ->get();

        if ($connections->isEmpty()) {
            return response()->json([]);
        }

        // Obtener IDs de empresas conectadas
        $connectedCompanyIds = $connections->map(function($connection) use ($companyId) {
            return $connection->company_id === $companyId 
                ? $connection->connected_company_id 
                : $connection->company_id;
        })->unique()->values();

        // Consultas agregadas optimizadas (1 query por métrica en lugar de N queries)
        $invoicesSent = Invoice::where('issuer_company_id', $companyId)
            ->whereIn('receiver_company_id', $connectedCompanyIds)
            ->select('receiver_company_id', 
                DB::raw('COUNT(*) as count'), 
                DB::raw('SUM(total) as total'))
            ->groupBy('receiver_company_id')
            ->get()
            ->keyBy('receiver_company_id');

        $invoicesReceived = Invoice::whereIn('issuer_company_id', $connectedCompanyIds)
            ->where('receiver_company_id', $companyId)
            ->select('issuer_company_id', 
                DB::raw('COUNT(*) as count'), 
                DB::raw('SUM(total) as total'))
            ->groupBy('issuer_company_id')
            ->get()
            ->keyBy('issuer_company_id');

        // Obtener última transacción por empresa conectada
        $lastTransactions = collect();
        foreach ($connectedCompanyIds as $connectedId) {
            $lastTx = Invoice::where(function($q) use ($companyId, $connectedId) {
                $q->where(function($q2) use ($companyId, $connectedId) {
                    $q2->where('issuer_company_id', $companyId)
                       ->where('receiver_company_id', $connectedId);
                })->orWhere(function($q2) use ($companyId, $connectedId) {
                    $q2->where('issuer_company_id', $connectedId)
                       ->where('receiver_company_id', $companyId);
                });
            })
            ->orderBy('created_at', 'desc')
            ->first();
            
            if ($lastTx) {
                $lastTransactions->put($connectedId, $lastTx);
            }
        }
        
        $result = $connections->map(function($connection) use ($companyId, $invoicesSent, $invoicesReceived, $lastTransactions) {
            $isInitiator = $connection->company_id === $companyId;
            $connectedCompany = $isInitiator ? $connection->connectedCompany : $connection->company;
            $connectedCompanyId = $connectedCompany->id;
            
            $sent = $invoicesSent->get($connectedCompanyId);
            $received = $invoicesReceived->get($connectedCompanyId);
            $lastTx = $lastTransactions->get($connectedCompanyId);
            
            return [
                'id' => $connection->id,
                'companyId' => $companyId,
                'connectedCompanyId' => $connectedCompanyId,
                'connectedCompanyName' => $connectedCompany->name,
                'connectedCompanyUniqueId' => $connectedCompany->unique_id,
                'connectedCompanyCuit' => $connectedCompany->national_id,
                'connectedCompanyTaxCondition' => $connectedCompany->tax_condition,
                'status' => 'connected',
                'requestedAt' => $connection->created_at,
                'connectedAt' => $connection->connected_at,
                'requestedBy' => $connection->requestedByUser?->email ?? null,
                'totalInvoicesSent' => $sent?->count ?? 0,
                'totalInvoicesReceived' => $received?->count ?? 0,
                'totalAmountSent' => $sent?->total ?? 0,
                'totalAmountReceived' => $received?->total ?? 0,
                'lastTransactionDate' => $lastTx?->created_at ?? null,
            ];
        });

        return response()->json($result);
    }

    public function pendingRequests($companyId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('viewAny', [CompanyConnection::class, $company]);
        
        $requests = CompanyConnection::where('connected_company_id', $companyId)
            ->whereIn('status', ['pending_sent', 'pending_received'])
            ->with(['company', 'connectedCompany', 'requestedByUser'])
            ->get()
            ->map(function($connection) {
                return [
                    'id' => $connection->id,
                    'fromCompanyId' => $connection->company_id,
                    'fromCompanyName' => $connection->company->name,
                    'fromCompanyUniqueId' => $connection->company->unique_id,
                    'toCompanyId' => $connection->connected_company_id,
                    'toCompanyName' => $connection->connectedCompany->name,
                    'message' => $connection->message,
                    'requestedAt' => $connection->created_at,
                    'requestedBy' => $connection->requestedByUser?->email ?? null,
                ];
            });

        return response()->json($requests);
    }

    public function sentRequests($companyId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('viewAny', [CompanyConnection::class, $company]);
        
        $requests = CompanyConnection::where('company_id', $companyId)
            ->whereIn('status', ['pending_sent', 'pending_received'])
            ->with(['company', 'connectedCompany', 'requestedByUser'])
            ->get()
            ->map(function($connection) {
                return [
                    'id' => $connection->id,
                    'fromCompanyId' => $connection->company_id,
                    'fromCompanyName' => $connection->company->name,
                    'fromCompanyUniqueId' => $connection->company->unique_id,
                    'toCompanyId' => $connection->connected_company_id,
                    'toCompanyName' => $connection->connectedCompany->name,
                    'message' => $connection->message,
                    'requestedAt' => $connection->created_at,
                    'requestedBy' => $connection->requestedByUser?->email ?? null,
                ];
            });

        return response()->json($requests);
    }

    public function store(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('create', [CompanyConnection::class, $company]);

        $validated = $request->validate([
            'company_unique_id' => 'required|string|exists:companies,unique_id',
            'message' => 'nullable|string|max:500',
        ]);

        $targetCompany = Company::where('unique_id', $validated['company_unique_id'])->firstOrFail();

        if ($targetCompany->id === $companyId) {
            return response()->json(['message' => 'No puedes conectarte con tu propia empresa'], 422);
        }

        $existing = CompanyConnection::where(function($query) use ($companyId, $targetCompany) {
            $query->where('company_id', $companyId)
                  ->where('connected_company_id', $targetCompany->id);
        })->orWhere(function($query) use ($companyId, $targetCompany) {
            $query->where('company_id', $targetCompany->id)
                  ->where('connected_company_id', $companyId);
        })->first();

        if ($existing) {
            if ($existing->status === 'connected') {
                return response()->json(['message' => 'Ya tienes una conexión establecida con esta empresa'], 422);
            } else if (in_array($existing->status, ['pending_sent', 'pending_received'])) {
                return response()->json(['message' => 'Ya existe una solicitud pendiente con esta empresa'], 422);
            }
        }

        $connection = CompanyConnection::create([
            'company_id' => $companyId,
            'connected_company_id' => $targetCompany->id,
            'status' => 'pending_sent',
            'message' => $validated['message'] ?? null,
            'requested_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Solicitud enviada exitosamente',
            'connection' => $connection
        ], 201);
    }

    public function accept(Request $request, $companyId, $connectionId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('manage', [CompanyConnection::class, $company]);

        $connection = CompanyConnection::withTrashed()->findOrFail($connectionId);

        if ($connection->connected_company_id !== $companyId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if (!in_array($connection->status, ['pending_sent', 'pending_received'])) {
            return response()->json(['message' => 'Esta solicitud ya fue procesada'], 422);
        }

        $connection->update([
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        return response()->json([
            'message' => 'Conexión aceptada exitosamente',
            'connection' => $connection
        ]);
    }

    public function reject(Request $request, $companyId, $connectionId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('manage', [CompanyConnection::class, $company]);

        $connection = CompanyConnection::withTrashed()->findOrFail($connectionId);

        if ($connection->connected_company_id !== $companyId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if (!in_array($connection->status, ['pending_sent', 'pending_received'])) {
            return response()->json(['message' => 'Esta solicitud ya fue procesada'], 422);
        }

        $connection->forceDelete();

        return response()->json(['message' => 'Solicitud rechazada']);
    }

    public function stats($companyId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('viewAny', [CompanyConnection::class, $company]);
        
        $totalConnections = CompanyConnection::where(function($query) use ($companyId) {
            $query->where('company_id', $companyId)
                  ->orWhere('connected_company_id', $companyId);
        })
        ->where('status', 'connected')
        ->count();

        $pendingReceived = CompanyConnection::where('connected_company_id', $companyId)
            ->whereIn('status', ['pending_sent', 'pending_received'])
            ->count();

        $pendingSent = CompanyConnection::where('company_id', $companyId)
            ->whereIn('status', ['pending_sent', 'pending_received'])
            ->count();

        return response()->json([
            'totalConnections' => $totalConnections,
            'pendingReceived' => $pendingReceived,
            'pendingSent' => $pendingSent,
        ]);
    }

    public function destroy($companyId, $connectionId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('manage', [CompanyConnection::class, $company]);

        $connection = CompanyConnection::findOrFail($connectionId);

        if ($connection->company_id !== $companyId && $connection->connected_company_id !== $companyId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        DB::beginTransaction();
        try {
            // Determinar qué empresa es la "otra"
            $otherCompanyId = $connection->company_id === $companyId 
                ? $connection->connected_company_id 
                : $connection->company_id;
            
            $otherCompany = Company::findOrFail($otherCompanyId);
            
            // Verificar facturas emitidas (yo -> otra empresa)
            $invoicesSent = Invoice::where('issuer_company_id', $companyId)
                ->where('receiver_company_id', $otherCompanyId)
                ->exists();
            
            // Verificar facturas recibidas (otra empresa -> yo)
            $invoicesReceived = Invoice::where('issuer_company_id', $otherCompanyId)
                ->where('receiver_company_id', $companyId)
                ->exists();
            
            $created = [];
            
            // Si emití facturas, crear Cliente
            if ($invoicesSent) {
                $client = $company->clients()->create([
                    'document_type' => 'CUIT',
                    'document_number' => $otherCompany->national_id,
                    'business_name' => $otherCompany->name,
                    'tax_condition' => $otherCompany->tax_condition,
                    'email' => null,
                    'phone' => null,
                    'address' => null,
                ]);
                
                // Re-vincular facturas emitidas
                Invoice::where('issuer_company_id', $companyId)
                    ->where('receiver_company_id', $otherCompanyId)
                    ->update(['client_id' => $client->id, 'receiver_company_id' => null]);
                
                $created[] = 'cliente';
            }
            
            // Si recibí facturas, crear Proveedor
            if ($invoicesReceived) {
                $supplier = $company->suppliers()->create([
                    'document_type' => 'CUIT',
                    'document_number' => $otherCompany->national_id,
                    'business_name' => $otherCompany->name,
                    'tax_condition' => $otherCompany->tax_condition,
                    'email' => null,
                    'phone' => null,
                    'address' => null,
                ]);
                
                // Re-vincular facturas recibidas
                Invoice::where('issuer_company_id', $otherCompanyId)
                    ->where('receiver_company_id', $companyId)
                    ->update(['supplier_id' => $supplier->id, 'issuer_company_id' => null]);
                
                $created[] = 'proveedor';
            }
            
            // Eliminar conexión (hard delete)
            $connection->forceDelete();
            
            DB::commit();
            
            $message = 'Conexión eliminada correctamente';
            if (!empty($created)) {
                $message .= '. Se creó ' . implode(' y ', $created) . ' externo con los datos de la empresa para mantener el historial del Libro IVA.';
            }
            
            return response()->json([
                'message' => $message,
                'created' => $created
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error al eliminar conexión: ' . $e->getMessage());
            return response()->json(['message' => 'Error al eliminar conexión'], 500);
        }
    }


}
