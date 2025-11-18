<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Invoice;
use App\Repositories\PaymentRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    private PaymentRepository $paymentRepository;

    public function __construct(PaymentRepository $paymentRepository)
    {
        $this->paymentRepository = $paymentRepository;
    }

    public function index(Request $request, $companyId)
    {
        $filters = [];
        if ($request->has('status')) {
            $filters['status'] = $request->status;
        }

        $payments = $this->paymentRepository->getByCompanyId($companyId, $filters);
        
        // Format payments to ensure proper data structure
        $formatted = $payments->map(function($payment) {
            $data = $payment->toArray();
            
            if ($payment->invoice) {
                $data['invoice'] = [
                    'id' => $payment->invoice->id,
                    'type' => $payment->invoice->type,
                    'sales_point' => $payment->invoice->sales_point,
                    'voucher_number' => $payment->invoice->voucher_number,
                    'currency' => $payment->invoice->currency ?? 'ARS',
                ];
                
                if ($payment->invoice->supplier) {
                    $data['invoice']['supplier'] = [
                        'id' => $payment->invoice->supplier->id,
                        'business_name' => $payment->invoice->supplier->business_name,
                        'first_name' => $payment->invoice->supplier->first_name,
                        'last_name' => $payment->invoice->supplier->last_name,
                    ];
                } else {
                    $data['invoice']['supplier'] = null;
                }
                
                if ($payment->invoice->issuerCompany) {
                    $data['invoice']['issuerCompany'] = [
                        'id' => $payment->invoice->issuerCompany->id,
                        'business_name' => $payment->invoice->issuerCompany->business_name,
                        'name' => $payment->invoice->issuerCompany->name,
                    ];
                } else {
                    $data['invoice']['issuerCompany'] = null;
                }
                
                // Incluir NC/ND asociadas
                // Solo mostrar NC/ND que tengan CAE (fueron autorizadas por AFIP)
                $creditNotes = Invoice::where('related_invoice_id', $payment->invoice->id)
                    ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
                    ->where('status', '!=', 'cancelled')
                    ->whereNotNull('afip_cae')
                    ->get(['id', 'type', 'sales_point', 'voucher_number', 'total']);
                
                $debitNotes = Invoice::where('related_invoice_id', $payment->invoice->id)
                    ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
                    ->where('status', '!=', 'cancelled')
                    ->whereNotNull('afip_cae')
                    ->get(['id', 'type', 'sales_point', 'voucher_number', 'total']);
                
                $data['invoice']['credit_notes_applied'] = $creditNotes->toArray();
                $data['invoice']['debit_notes_applied'] = $debitNotes->toArray();
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

        $retentions = $validated['retentions'] ?? [];
        unset($validated['retentions']);

        $validated['company_id'] = $companyId;
        $validated['registered_by'] = $request->user()->id;
        $validated['registered_at'] = now();
        $validated['status'] = $validated['status'] ?? 'in_process';

        DB::beginTransaction();
        try {
            $payment = Payment::create($validated);

            // Guardar retenciones si existen
            if (count($retentions) > 0) {
                foreach ($retentions as $retention) {
                    $payment->retentions()->create([
                        'type' => $retention['type'],
                        'name' => $retention['name'],
                        'rate' => $retention['rate'],
                        'amount' => $retention['amount'],
                        'base_amount' => 0
                    ]);
                }
            }

            // Si el pago se crea como confirmado, actualizar estado de factura
            if ($payment->status === 'confirmed') {
                $invoice = Invoice::find($payment->invoice_id);
                if ($invoice) {
                    $this->updateInvoicePaymentStatus($invoice, $companyId);
                }
            }

            DB::commit();
            // Auditoría empresa: pago creado
            app(\App\Services\AuditService::class)->log(
                (string) $companyId,
                (string) (auth()->id() ?? ''),
                'payment.created',
                'Pago creado',
                'Payment',
                (string) $payment->id,
                [
                    'invoice_id' => (string) $payment->invoice_id,
                    'amount' => $payment->amount,
                    'method' => $payment->payment_method,
                    'status' => $payment->status,
                ]
            );

            return response()->json($payment->load(['invoice.supplier', 'registeredBy', 'retentions']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error creating payment: ' . $e->getMessage()], 500);
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

        // Auditoría empresa: pago actualizado
        app(\App\Services\AuditService::class)->log(
            (string) $companyId,
            (string) (auth()->id() ?? ''),
            'payment.updated',
            'Pago actualizado',
            'Payment',
            (string) $payment->id,
            [ 'updated_fields' => array_keys($validated) ]
        );

        return response()->json($payment->load(['invoice.supplier', 'registeredBy', 'confirmedBy']));
    }

    public function destroy($companyId, $paymentId)
    {
        $payment = Payment::where('company_id', $companyId)->findOrFail($paymentId);
        
        if ($payment->status === 'confirmed') {
            return response()->json(['error' => 'Cannot delete confirmed payment'], 400);
        }

        DB::beginTransaction();
        try {
            $invoiceId = $payment->invoice_id;
            $payment->delete();

            // Auditoría empresa: pago eliminado
            app(\App\Services\AuditService::class)->log(
                (string) $companyId,
                (string) (auth()->id() ?? ''),
                'payment.deleted',
                'Pago eliminado',
                'Payment',
                (string) $payment->id,
                [ 'invoice_id' => (string) $invoiceId, 'amount' => $payment->amount ]
            );

            // Recalcular estado de factura
            $invoice = Invoice::find($invoiceId);
            if ($invoice) {
                $totalPaid = $this->paymentRepository->getTotalPaidForInvoice($invoiceId);
                
                if ($totalPaid < $invoice->total) {
                    // Si ya no está totalmente pagada, actualizar company_statuses y restaurar status global
                    $companyStatuses = $invoice->company_statuses ?: [];
                    unset($companyStatuses[(string)$companyId]);
                    $invoice->company_statuses = $companyStatuses;
                    $invoice->status = 'issued';
                    $invoice->save();
                }
            }

            DB::commit();
            return response()->json(['message' => 'Payment deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error deleting payment'], 500);
        }
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

            // Update invoice status to paid only if fully paid
            $invoice = $payment->invoice;
            $this->updateInvoicePaymentStatus($invoice, $companyId);

            DB::commit();

            // Auditoría empresa: pago confirmado
            app(\App\Services\AuditService::class)->log(
                (string) $companyId,
                (string) (auth()->id() ?? ''),
                'payment.confirmed',
                'Pago confirmado',
                'Payment',
                (string) $payment->id,
                [ 'invoice_id' => (string) $payment->invoice_id, 'amount' => $payment->amount ]
            );

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

        $payments = $this->paymentRepository->getByInvoiceIds($validated['payment_ids'], $companyId);

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

    private function updateInvoicePaymentStatus(Invoice $invoice, string $companyId): void
    {
        $totalPaid = $this->paymentRepository->getTotalPaidForInvoice($invoice->id);
        
        // Calcular balance pendiente (Total + ND - NC)
        // Solo contar NC/ND que tengan CAE (fueron autorizadas por AFIP)
        $creditNotes = Invoice::where('related_invoice_id', $invoice->id)
            ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('afip_cae')
            ->get();
        
        $debitNotes = Invoice::where('related_invoice_id', $invoice->id)
            ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('afip_cae')
            ->get();
        
        $totalNC = $creditNotes->sum('total');
        $totalND = $debitNotes->sum('total');
        
        $balancePending = ($invoice->total ?? 0) + $totalND - $totalNC;
        
        $companyStatuses = $invoice->company_statuses ?: [];
        
        // Determinar estado según balance y pagos
        if ($totalPaid >= $balancePending && $balancePending > 0) {
            // Pagado completamente
            $companyStatuses[(string)$companyId] = 'paid';
            
            // Actualizar estado de NC/ND asociadas
            foreach ($creditNotes as $nc) {
                $ncStatuses = $nc->company_statuses ?: [];
                $ncStatuses[(string)$companyId] = 'paid';
                $nc->company_statuses = $ncStatuses;
                $nc->status = 'paid';
                $nc->save();
            }
            
            foreach ($debitNotes as $nd) {
                $ndStatuses = $nd->company_statuses ?: [];
                $ndStatuses[(string)$companyId] = 'paid';
                $nd->company_statuses = $ndStatuses;
                $nd->status = 'paid';
                $nd->save();
            }
        } elseif ($totalPaid > 0 && $balancePending < 0) {
            // Pagó de más (tiene saldo a favor del proveedor)
            $companyStatuses[(string)$companyId] = 'overpaid';
        } elseif ($balancePending > 0) {
            // Pendiente de pago
            $companyStatuses[(string)$companyId] = 'approved';
        } else {
            // Balance 0 o negativo sin pagos
            $companyStatuses[(string)$companyId] = 'approved';
        }
        
        $invoice->company_statuses = $companyStatuses;
        $invoice->status = $companyStatuses[(string)$companyId];
        $invoice->save();
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
