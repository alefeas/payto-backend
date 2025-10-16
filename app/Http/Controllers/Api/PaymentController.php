<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function index(Request $request, $companyId)
    {
        $query = Payment::with(['invoice.supplier', 'registeredBy', 'confirmedBy'])
            ->where('company_id', $companyId);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $payments = $query->orderBy('payment_date', 'desc')->get();

        return response()->json($payments);
    }

    public function store(Request $request, $companyId)
    {
        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0',
            'payment_date' => 'required|date',
            'payment_method' => 'required|in:transfer,check,cash,card',
            'reference_number' => 'nullable|string|max:100',
            'attachment_url' => 'nullable|string|max:500',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:pending,in_process,confirmed,cancelled',
            'retentions' => 'nullable|array',
            'retentions.*.type' => 'required|in:vat_retention,income_tax_retention,gross_income_retention,suss_retention',
            'retentions.*.name' => 'required|string',
            'retentions.*.rate' => 'required|numeric|min:0|max:100',
            'retentions.*.amount' => 'required|numeric|min:0',
        ]);

        $validated['company_id'] = $companyId;
        $validated['registered_by'] = $request->user()->id;
        $validated['status'] = $validated['status'] ?? 'in_process';

        DB::beginTransaction();
        try {
            $payment = Payment::create($validated);

            // Guardar retenciones si existen
            if (isset($validated['retentions']) && count($validated['retentions']) > 0) {
                foreach ($validated['retentions'] as $retention) {
                    $payment->retentions()->create($retention);
                }
            }

            // If status is confirmed, update invoice status
            if ($payment->status === 'confirmed') {
                $invoice = Invoice::find($validated['invoice_id']);
                $invoice->status = 'paid';
                $invoice->save();
            }

            DB::commit();

            return response()->json($payment->load(['invoice.supplier', 'registeredBy', 'retentions']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error creating payment'], 500);
        }
    }

    public function update(Request $request, $companyId, $paymentId)
    {
        $payment = Payment::where('company_id', $companyId)->findOrFail($paymentId);

        $validated = $request->validate([
            'amount' => 'sometimes|numeric|min:0',
            'payment_date' => 'sometimes|date',
            'payment_method' => 'sometimes|in:transfer,check,cash,card',
            'reference_number' => 'nullable|string|max:100',
            'attachment_url' => 'nullable|string|max:500',
            'notes' => 'nullable|string',
        ]);

        $payment->update($validated);

        return response()->json($payment->load(['invoice.supplier', 'registeredBy', 'confirmedBy']));
    }

    public function destroy($companyId, $paymentId)
    {
        $payment = Payment::where('company_id', $companyId)->findOrFail($paymentId);
        
        if ($payment->status === 'confirmed') {
            return response()->json(['error' => 'Cannot delete confirmed payment'], 400);
        }

        $payment->delete();

        return response()->json(['message' => 'Payment deleted successfully']);
    }

    public function confirm(Request $request, $companyId, $paymentId)
    {
        $payment = Payment::where('company_id', $companyId)->findOrFail($paymentId);

        if ($payment->status === 'confirmed') {
            return response()->json(['error' => 'Payment already confirmed'], 400);
        }

        DB::beginTransaction();
        try {
            $payment->status = 'confirmed';
            $payment->confirmed_by = $request->user()->id;
            $payment->confirmed_at = now();
            $payment->save();

            // Update invoice status to paid
            $invoice = $payment->invoice;
            $invoice->status = 'paid';
            $invoice->save();

            DB::commit();

            return response()->json($payment->load(['invoice.supplier', 'registeredBy', 'confirmedBy']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error confirming payment'], 500);
        }
    }

    public function generateTxt(Request $request, $companyId)
    {
        $validated = $request->validate([
            'payment_ids' => 'required|array',
            'payment_ids.*' => 'exists:invoice_payments_tracking,id',
        ]);

        $payments = Payment::with(['invoice.supplier'])
            ->where('company_id', $companyId)
            ->whereIn('id', $validated['payment_ids'])
            ->get();

        // Validate all suppliers have CBU
        foreach ($payments as $payment) {
            if (!$payment->invoice->supplier->bank_cbu) {
                return response()->json([
                    'error' => 'Supplier ' . $payment->invoice->supplier->business_name . ' does not have CBU'
                ], 400);
            }
        }

        // Generate TXT content (format depends on bank)
        $txtContent = $this->generateHomebankingTxt($payments);

        // Mark payments as in_process
        Payment::whereIn('id', $validated['payment_ids'])->update(['status' => 'in_process']);

        return response()->json([
            'content' => $txtContent,
            'filename' => 'pagos_' . date('Ymd') . '.txt'
        ]);
    }

    private function generateHomebankingTxt($payments)
    {
        $lines = [];
        
        foreach ($payments as $payment) {
            $supplier = $payment->invoice->supplier;
            
            // Format: CBU|Amount|Reference|Supplier Name
            // This is a simplified format - adjust according to your bank's requirements
            $line = sprintf(
                "%s|%.2f|%s|%s",
                $supplier->bank_cbu,
                $payment->amount,
                $payment->reference_number ?? 'Pago Factura ' . $payment->invoice->invoice_number,
                $supplier->business_name
            );
            
            $lines[] = $line;
        }
        
        return implode("\n", $lines);
    }
}
