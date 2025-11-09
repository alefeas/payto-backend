<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CollectionController extends Controller
{
    public function index(Request $request, $companyId)
    {
        $query = Collection::with(['invoice.client', 'invoice.receiverCompany', 'registeredBy', 'confirmedBy'])
            ->where('company_id', $companyId);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('from_network')) {
            $query->where('from_network', $request->boolean('from_network'));
        }

        $collections = $query->orderBy('collection_date', 'desc')->get();
        
        // Format collections to ensure proper data structure
        $formatted = $collections->map(function($collection) {
            $data = $collection->toArray();
            
            if ($collection->invoice) {
                $data['invoice'] = [
                    'id' => $collection->invoice->id,
                    'type' => $collection->invoice->type,
                    'sales_point' => $collection->invoice->sales_point,
                    'voucher_number' => $collection->invoice->voucher_number,
                    'currency' => $collection->invoice->currency ?? 'ARS',
                ];
                
                if ($collection->invoice->client) {
                    $data['invoice']['client'] = [
                        'id' => $collection->invoice->client->id,
                        'business_name' => $collection->invoice->client->business_name,
                        'first_name' => $collection->invoice->client->first_name,
                        'last_name' => $collection->invoice->client->last_name,
                    ];
                } else {
                    $data['invoice']['client'] = null;
                }
                
                if ($collection->invoice->receiverCompany) {
                    $data['invoice']['receiverCompany'] = [
                        'id' => $collection->invoice->receiverCompany->id,
                        'business_name' => $collection->invoice->receiverCompany->business_name,
                        'name' => $collection->invoice->receiverCompany->name,
                    ];
                } else {
                    $data['invoice']['receiverCompany'] = null;
                }
            }
            
            return $data;
        });

        return response()->json($formatted);
    }

    public function store(Request $request, $companyId)
    {
        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0',
            'collection_date' => 'required|date',
            'collection_method' => 'required|in:transfer,check,cash,card,debit_card,credit_card,other',
            'reference_number' => 'nullable|string|max:100',
            'attachment_url' => 'nullable|string|max:500',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:pending_confirmation,confirmed,rejected',
            'from_network' => 'nullable|boolean',
            'withholding_iibb' => 'nullable|numeric|min:0',
            'withholding_iibb_notes' => 'nullable|string',
            'withholding_iva' => 'nullable|numeric|min:0',
            'withholding_iva_notes' => 'nullable|string',
            'withholding_ganancias' => 'nullable|numeric|min:0',
            'withholding_ganancias_notes' => 'nullable|string',
            'withholding_suss' => 'nullable|numeric|min:0',
            'withholding_suss_notes' => 'nullable|string',
            'withholding_other' => 'nullable|numeric|min:0',
            'withholding_other_notes' => 'nullable|string',
        ]);

        $validated['company_id'] = $companyId;
        $validated['registered_by'] = $request->user()->id;
        $validated['status'] = $validated['status'] ?? 'pending_confirmation';
        $validated['from_network'] = $validated['from_network'] ?? false;

        DB::beginTransaction();
        try {
            $collection = Collection::create($validated);
            
            // Si la colección se crea como confirmada, actualizar company_statuses JSON
            if ($collection->status === 'confirmed') {
                $invoice = Invoice::find($collection->invoice_id);
                if ($invoice) {
                    $this->updateInvoiceCollectionStatus($invoice, $companyId);
                }
            }
            
            DB::commit();

            // Auditoría empresa: cobro creado
            app(\App\Services\AuditService::class)->log(
                (string) $companyId,
                (string) (auth()->id() ?? ''),
                'collection.created',
                'Cobro creado',
                'Collection',
                (string) $collection->id,
                [
                    'invoice_id' => (string) $collection->invoice_id,
                    'amount' => $collection->amount,
                    'method' => $collection->collection_method,
                    'status' => $collection->status,
                    'from_network' => $collection->from_network,
                ]
            );

            return response()->json($collection->load(['invoice.client', 'registeredBy']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error creating collection'], 500);
        }
    }

    public function update(Request $request, $companyId, $collectionId)
    {
        $collection = Collection::where('company_id', $companyId)->findOrFail($collectionId);

        $validated = $request->validate([
            'amount' => 'sometimes|numeric|min:0',
            'collection_date' => 'sometimes|date',
            'collection_method' => 'sometimes|in:transfer,check,cash,card,debit_card,credit_card,other',
            'reference_number' => 'nullable|string|max:100',
            'attachment_url' => 'nullable|string|max:500',
            'notes' => 'nullable|string',
        ]);

        $collection->update($validated);

        // Auditoría empresa: cobro actualizado
        app(\App\Services\AuditService::class)->log(
            (string) $companyId,
            (string) (auth()->id() ?? ''),
            'collection.updated',
            'Cobro actualizado',
            'Collection',
            (string) $collection->id,
            [ 'updated_fields' => array_keys($validated) ]
        );

        return response()->json($collection->load(['invoice.client', 'registeredBy', 'confirmedBy']));
    }

    public function confirm(Request $request, $companyId, $collectionId)
    {
        $collection = Collection::where('company_id', $companyId)->findOrFail($collectionId);

        if ($collection->status === 'confirmed') {
            return response()->json(['error' => 'Collection already confirmed'], 400);
        }

        DB::beginTransaction();
        try {
            $collection->status = 'confirmed';
            $collection->confirmed_by = $request->user()->id;
            $collection->confirmed_at = now();
            $collection->save();

            // Actualizar company_statuses JSON para esta empresa
            $invoice = $collection->invoice;
            $this->updateInvoiceCollectionStatus($invoice, $companyId);

            DB::commit();

            // Auditoría empresa: cobro confirmado
            app(\App\Services\AuditService::class)->log(
                (string) $companyId,
                (string) (auth()->id() ?? ''),
                'collection.confirmed',
                'Cobro confirmado',
                'Collection',
                (string) $collection->id,
                [ 'invoice_id' => (string) $collection->invoice_id, 'amount' => $collection->amount ]
            );

            return response()->json($collection->load(['invoice.client', 'registeredBy', 'confirmedBy']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error confirming collection'], 500);
        }
    }

    public function destroy($companyId, $collectionId)
    {
        $collection = Collection::where('company_id', $companyId)->findOrFail($collectionId);
        
        if ($collection->status === 'confirmed') {
            return response()->json(['error' => 'Cannot delete confirmed collection'], 400);
        }

        DB::beginTransaction();
        try {
            $invoiceId = $collection->invoice_id;
            $collection->delete();

            // Auditoría empresa: cobro eliminado
            app(\App\Services\AuditService::class)->log(
                (string) $companyId,
                (string) (auth()->id() ?? ''),
                'collection.deleted',
                'Cobro eliminado',
                'Collection',
                (string) $collection->id,
                [ 'invoice_id' => (string) $invoiceId, 'amount' => $collection->amount ]
            );

            // Recalcular company_statuses JSON
            $invoice = Invoice::find($invoiceId);
            if ($invoice) {
                $this->updateInvoiceCollectionStatus($invoice, $companyId);
            }

            DB::commit();
            return response()->json(['message' => 'Collection deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error deleting collection'], 500);
        }
    }

    public function reject(Request $request, $companyId, $collectionId)
    {
        $collection = Collection::where('company_id', $companyId)->findOrFail($collectionId);

        if ($collection->status === 'rejected') {
            return response()->json(['error' => 'Collection already rejected'], 400);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $invoiceId = $collection->invoice_id;
            $collection->status = 'rejected';
            if (isset($validated['notes'])) {
                $collection->notes = $validated['notes'];
            }
            $collection->save();

            // Recalcular company_statuses JSON
            $invoice = Invoice::find($invoiceId);
            if ($invoice) {
                $this->updateInvoiceCollectionStatus($invoice, $companyId);
            }

            DB::commit();

            // Auditoría empresa: cobro rechazado
            app(\App\Services\AuditService::class)->log(
                (string) $companyId,
                (string) (auth()->id() ?? ''),
                'collection.rejected',
                'Cobro rechazado',
                'Collection',
                (string) $collection->id,
                [ 'invoice_id' => (string) $invoiceId, 'notes' => $collection->notes ]
            );

            return response()->json($collection->load(['invoice.client', 'registeredBy']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error rejecting collection'], 500);
        }
    }

    private function updateInvoiceCollectionStatus(Invoice $invoice, string $companyId): void
    {
        $totalCollected = Collection::where('invoice_id', $invoice->id)
            ->where('status', 'confirmed')
            ->sum('amount');
        
        // Calcular balance pendiente (Total + ND - NC)
        $totalNC = Invoice::where('related_invoice_id', $invoice->id)
            ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
            ->where('status', '!=', 'cancelled')
            ->where('afip_status', 'approved')
            ->sum('total');
        
        $totalND = Invoice::where('related_invoice_id', $invoice->id)
            ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
            ->where('status', '!=', 'cancelled')
            ->where('afip_status', 'approved')
            ->sum('total');
        
        $balancePending = ($invoice->total ?? 0) + $totalND - $totalNC;
        
        $companyStatuses = $invoice->company_statuses ?: [];
        
        // Determinar estado según balance y cobros
        if ($totalCollected >= $balancePending && $balancePending > 0) {
            // Cobrado completamente
            $companyStatuses[(string)$companyId] = 'collected';
        } elseif ($totalCollected > 0 && $balancePending < 0) {
            // Cobró de más (tiene saldo a favor del cliente)
            $companyStatuses[(string)$companyId] = 'overpaid';
        } elseif ($balancePending > 0) {
            // Pendiente de cobro
            $companyStatuses[(string)$companyId] = 'issued';
        } else {
            // Balance 0 o negativo sin cobros
            $companyStatuses[(string)$companyId] = 'issued';
        }
        
        $invoice->company_statuses = $companyStatuses;
        $invoice->status = $companyStatuses[(string)$companyId];
        $invoice->save();
    }
}
