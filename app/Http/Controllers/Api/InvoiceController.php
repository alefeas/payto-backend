<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Afip\AfipInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    public function index(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        
        $this->authorize('viewAny', [Invoice::class, $company]);

        $invoices = Invoice::where('issuer_company_id', $companyId)
            ->with(['client', 'items', 'receiverCompany'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($invoices);
    }

    public function store(Request $request, $companyId)
    {
        $company = Company::with('afipCertificate')->findOrFail($companyId);
        
        $this->authorize('create', [Invoice::class, $company]);

        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'invoice_type' => 'required|in:A,B,C,E',
            'sales_point' => 'required|integer|min:1|max:9999',
            'issue_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:issue_date',
            'currency' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'required|numeric|min:0|max:100',
        ]);

        try {
            DB::beginTransaction();

            $lastInvoice = Invoice::where('issuer_company_id', $companyId)
                ->where('type', $validated['invoice_type'])
                ->where('sales_point', $validated['sales_point'])
                ->orderBy('voucher_number', 'desc')
                ->first();

            $voucherNumber = $lastInvoice ? $lastInvoice->voucher_number + 1 : 1;

            $subtotal = 0;
            $totalTaxes = 0;

            foreach ($validated['items'] as $item) {
                $itemSubtotal = $item['quantity'] * $item['unit_price'];
                $itemTax = $itemSubtotal * ($item['tax_rate'] / 100);
                
                $subtotal += $itemSubtotal;
                $totalTaxes += $itemTax;
            }

            $total = $subtotal + $totalTaxes;

            $invoice = Invoice::create([
                'number' => sprintf('%04d-%08d', $validated['sales_point'], $voucherNumber),
                'type' => $validated['invoice_type'],
                'sales_point' => $validated['sales_point'],
                'voucher_number' => $voucherNumber,
                'concept' => 'products',
                'issuer_company_id' => $companyId,
                'client_id' => $validated['client_id'],
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'] ?? now()->addDays(30),
                'subtotal' => $subtotal,
                'total_taxes' => $totalTaxes,
                'total_perceptions' => 0,
                'total' => $total,
                'currency' => $validated['currency'] ?? 'ARS',
                'exchange_rate' => $validated['exchange_rate'] ?? 1,
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending_approval',
                'afip_status' => 'pending',
                'created_by' => auth()->id(),
            ]);

            foreach ($validated['items'] as $item) {
                $itemSubtotal = $item['quantity'] * $item['unit_price'];
                $itemTax = $itemSubtotal * ($item['tax_rate'] / 100);
                $itemTotal = $itemSubtotal + $itemTax;

                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'],
                    'tax_amount' => $itemTax,
                    'subtotal' => $itemSubtotal,
                    'total' => $itemTotal,
                ]);
            }

            if ($company->afipCertificate && $company->afipCertificate->is_active) {
                try {
                    $afipService = new AfipInvoiceService($company);
                    $afipResult = $afipService->authorizeInvoice($invoice);
                    
                    $invoice->update([
                        'afip_cae' => $afipResult['cae'],
                        'afip_cae_due_date' => $afipResult['cae_expiration'],
                        'afip_status' => 'approved',
                        'status' => 'issued',
                        'afip_sent_at' => now(),
                    ]);
                } catch (\Exception $e) {
                    Log::error('AFIP authorization failed', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                    
                    $invoice->update([
                        'afip_error_message' => $e->getMessage(),
                        'afip_status' => 'error',
                        'status' => 'rejected',
                    ]);
                }
            } else {
                $invoice->update([
                    'afip_cae' => 'SIM-' . str_pad($voucherNumber, 10, '0', STR_PAD_LEFT),
                    'afip_cae_due_date' => now()->addDays(10),
                    'afip_status' => 'approved',
                    'status' => 'issued',
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Invoice created successfully',
                'invoice' => $invoice->load(['client', 'items']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Invoice creation failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)
            ->with(['client', 'items', 'receiverCompany'])
            ->findOrFail($id);

        $this->authorize('view', $invoice);

        return response()->json($invoice);
    }

    public function destroy($companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)->findOrFail($id);

        $this->authorize('delete', $invoice);

        if ($invoice->status === 'issued' && $invoice->hasValidCae()) {
            return response()->json([
                'message' => 'Cannot delete issued invoice with valid CAE',
            ], 422);
        }

        $invoice->delete();

        return response()->json([
            'message' => 'Invoice deleted successfully',
        ]);
    }
}
