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
        ->with(['company', 'connectedCompany'])
        ->get()
        ->map(function($connection) use ($companyId) {
            $isInitiator = $connection->company_id === $companyId;
            $connectedCompany = $isInitiator ? $connection->connectedCompany : $connection->company;
            
            $invoicesSent = Invoice::where('issuer_company_id', $companyId)
                ->where('receiver_company_id', $connectedCompany->id)
                ->count();
            
            $invoicesReceived = Invoice::where('issuer_company_id', $connectedCompany->id)
                ->where('receiver_company_id', $companyId)
                ->count();
            
            $amountSent = Invoice::where('issuer_company_id', $companyId)
                ->where('receiver_company_id', $connectedCompany->id)
                ->sum('total');
            
            $amountReceived = Invoice::where('issuer_company_id', $connectedCompany->id)
                ->where('receiver_company_id', $companyId)
                ->sum('total');
            
            $lastTransaction = Invoice::where(function($q) use ($companyId, $connectedCompany) {
                $q->where(function($q2) use ($companyId, $connectedCompany) {
                    $q2->where('issuer_company_id', $companyId)
                       ->where('receiver_company_id', $connectedCompany->id);
                })->orWhere(function($q2) use ($companyId, $connectedCompany) {
                    $q2->where('issuer_company_id', $connectedCompany->id)
                       ->where('receiver_company_id', $companyId);
                });
            })
            ->orderBy('created_at', 'desc')
            ->first();
            
            return [
                'id' => $connection->id,
                'companyId' => $companyId,
                'connectedCompanyId' => $connectedCompany->id,
                'connectedCompanyName' => $connectedCompany->name,
                'connectedCompanyUniqueId' => $connectedCompany->unique_id,
                'status' => 'connected',
                'requestedAt' => $connection->created_at,
                'connectedAt' => $connection->connected_at,
                'requestedBy' => $connection->requestedByUser?->email ?? null,
                'totalInvoicesSent' => $invoicesSent,
                'totalInvoicesReceived' => $invoicesReceived,
                'totalAmountSent' => $amountSent,
                'totalAmountReceived' => $amountReceived,
                'lastTransactionDate' => $lastTransaction?->created_at,
            ];
        });

        return response()->json($connections);
    }

    public function pendingRequests($companyId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('viewAny', [CompanyConnection::class, $company]);
        
        $requests = CompanyConnection::where('connected_company_id', $companyId)
            ->where('status', 'pending')
            ->with(['company', 'requestedByUser'])
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
            ->where('status', 'pending')
            ->with(['connectedCompany', 'requestedByUser'])
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
            } else if ($existing->status === 'pending') {
                return response()->json(['message' => 'Ya existe una solicitud pendiente con esta empresa'], 422);
            }
        }

        $connection = CompanyConnection::create([
            'company_id' => $companyId,
            'connected_company_id' => $targetCompany->id,
            'status' => 'pending',
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

        $connection = CompanyConnection::findOrFail($connectionId);

        if ($connection->connected_company_id !== $companyId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($connection->status !== 'pending') {
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

        $connection = CompanyConnection::findOrFail($connectionId);

        if ($connection->connected_company_id !== $companyId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($connection->status !== 'pending') {
            return response()->json(['message' => 'Esta solicitud ya fue procesada'], 422);
        }

        $connection->delete();

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
            ->where('status', 'pending')
            ->count();

        $pendingSent = CompanyConnection::where('company_id', $companyId)
            ->where('status', 'pending')
            ->count();

        return response()->json([
            'totalConnections' => $totalConnections,
            'pendingReceived' => $pendingReceived,
            'pendingSent' => $pendingSent,
        ]);
    }
}
