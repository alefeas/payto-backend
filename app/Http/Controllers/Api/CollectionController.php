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
        ]);

        $validated['company_id'] = $companyId;
        $validated['registered_by'] = $request->user()->id;
        $validated['status'] = $validated['status'] ?? 'pending_confirmation';
        $validated['from_network'] = $validated['from_network'] ?? false;

        DB::beginTransaction();
        try {
            $collection = Collection::create($validated);
            DB::commit();
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

            DB::commit();

            return response()->json($collection->load(['invoice.client', 'registeredBy', 'confirmedBy']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error confirming collection'], 500);
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

        $collection->status = 'rejected';
        if (isset($validated['notes'])) {
            $collection->notes = $validated['notes'];
        }
        $collection->save();

        return response()->json($collection->load(['invoice.client', 'registeredBy']));
    }
}
