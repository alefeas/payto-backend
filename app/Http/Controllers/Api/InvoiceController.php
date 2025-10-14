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
            'client_id' => 'required_without:client_data|exists:clients,id',
            'client_data' => 'required_without:client_id|array',
            'client_data.document_type' => 'required_with:client_data|in:CUIT,CUIL,DNI,Pasaporte,CDI',
            'client_data.document_number' => 'required_with:client_data|string',
            'client_data.business_name' => 'nullable|string',
            'client_data.first_name' => 'nullable|string',
            'client_data.last_name' => 'nullable|string',
            'client_data.email' => 'nullable|email',
            'client_data.tax_condition' => 'required_with:client_data|in:registered_taxpayer,monotax,exempt,final_consumer',
            'save_client' => 'boolean',
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

            // Create client if client_data is provided
            if (!isset($validated['client_id']) && isset($validated['client_data'])) {
                $client = \App\Models\Client::create([
                    'company_id' => $companyId,
                    ...$validated['client_data']
                ]);
                $validated['client_id'] = $client->id;
            }

            // Get last invoice number from database
            $lastInvoice = Invoice::where('issuer_company_id', $companyId)
                ->where('type', $validated['invoice_type'])
                ->where('sales_point', $validated['sales_point'])
                ->orderBy('voucher_number', 'desc')
                ->first();

            // Use the higher value between DB and company's last_invoice_number
            $lastFromDb = $lastInvoice ? $lastInvoice->voucher_number : 0;
            $lastFromCompany = $company->last_invoice_number ?? 0;
            $voucherNumber = max($lastFromDb, $lastFromCompany) + 1;

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
                'approvals_required' => 0, // Set based on company settings
                'approvals_received' => 0,
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

            // Auto-authorize with AFIP if certificate is configured
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
                    DB::rollBack();
                    
                    Log::error('AFIP authorization failed - Invoice not created', [
                        'company_id' => $companyId,
                        'voucher_number' => $voucherNumber,
                        'error' => $e->getMessage(),
                    ]);
                    
                    return response()->json([
                        'message' => 'AFIP rechazó la factura',
                        'error' => $e->getMessage(),
                    ], 422);
                }
            } else {
                // Simulated CAE for testing without AFIP
                $invoice->update([
                    'afip_cae' => 'SIM-' . str_pad($voucherNumber, 10, '0', STR_PAD_LEFT),
                    'afip_cae_due_date' => now()->addDays(10),
                    'afip_status' => 'approved',
                    'status' => 'issued',
                ]);
            }

            // Update company's last invoice number
            $company->update([
                'last_invoice_number' => $voucherNumber
            ]);

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

        // Only allow deletion of drafts, pending approval, or rejected invoices
        if (in_array($invoice->status, ['issued', 'approved', 'paid'])) {
            return response()->json([
                'message' => 'Cannot delete issued invoices. Use credit notes to cancel them.',
                'suggestion' => 'Create a credit note (Nota de Crédito) to cancel this invoice.',
            ], 422);
        }

        if ($invoice->afip_cae && !str_starts_with($invoice->afip_cae, 'SIM-')) {
            return response()->json([
                'message' => 'Cannot delete invoice with real AFIP CAE. Use credit notes to cancel.',
            ], 422);
        }

        $invoice->delete();

        return response()->json([
            'message' => 'Invoice deleted successfully',
        ]);
    }

    public function cancel($companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)->findOrFail($id);

        $this->authorize('delete', $invoice);

        if ($invoice->status === 'cancelled') {
            return response()->json([
                'message' => 'Invoice is already cancelled',
            ], 422);
        }

        // Mark as cancelled instead of deleting
        $invoice->update([
            'status' => 'cancelled',
        ]);

        return response()->json([
            'message' => 'Invoice cancelled successfully',
            'note' => 'To legally cancel this invoice, you should issue a credit note.',
        ]);
    }
}
