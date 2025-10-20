<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class SupplierPaymentController extends Controller
{
    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function store(Request $request, string $companyId)
    {
        $validated = $request->validate([
            'invoice_id' => 'required|uuid|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_method' => 'required|in:transfer,check,cash,debit_card,credit_card,other',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'retentions' => 'nullable|array',
            'retentions.*.type' => 'required|string',
            'retentions.*.name' => 'required|string|max:100',
            'retentions.*.rate' => 'required|numeric|min:0|max:100',
            'retentions.*.base_amount' => 'required|numeric|min:0',
            'retentions.*.amount' => 'required|numeric|min:0',
            'retentions.*.certificate_number' => 'nullable|string|max:50',
        ]);

        $company = Company::findOrFail($companyId);
        
        try {
            $payment = $this->paymentService->registerPayment($validated, $company);
            
            return response()->json([
                'success' => true,
                'message' => 'Payment registered successfully',
                'data' => $payment,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    public function calculateRetentions(Request $request, string $companyId, string $invoiceId)
    {
        $company = Company::findOrFail($companyId);
        $invoice = Invoice::findOrFail($invoiceId);
        
        $retentions = $this->paymentService->calculateRetentions($invoice, $company);
        
        return response()->json([
            'success' => true,
            'data' => [
                'retentions' => $retentions,
                'total_retentions' => array_sum(array_column($retentions, 'amount')),
                'is_retention_agent' => $company->is_retention_agent,
            ],
        ]);
    }

    public function confirm(string $companyId, string $paymentId)
    {
        $payment = Payment::where('company_id', $companyId)->findOrFail($paymentId);
        
        $payment = $this->paymentService->confirmPayment($payment, auth()->id());
        
        return response()->json([
            'success' => true,
            'message' => 'Payment confirmed successfully',
            'data' => $payment,
        ]);
    }

    public function generateTxt(Request $request, string $companyId)
    {
        $validated = $request->validate([
            'payment_ids' => 'required|array|min:1',
            'payment_ids.*' => 'required|uuid|exists:invoice_payments_tracking,id',
        ]);

        $company = Company::findOrFail($companyId);
        
        try {
            $txtContent = $this->paymentService->generatePaymentTxt($validated['payment_ids'], $company);
            
            $filename = 'pagos_' . date('Ymd_His') . '.txt';
            
            return response($txtContent)
                ->header('Content-Type', 'text/plain')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
                
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    public function index(Request $request, string $companyId)
    {
        $query = Payment::where('company_id', $companyId)
            ->with(['invoice.issuerCompany', 'retentions', 'registeredBy']);
        
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('from_date')) {
            $query->where('payment_date', '>=', $request->from_date);
        }
        
        if ($request->has('to_date')) {
            $query->where('payment_date', '<=', $request->to_date);
        }
        
        $payments = $query->orderByDesc('payment_date')->paginate(20);
        
        return response()->json([
            'success' => true,
            'data' => $payments->map(function($payment) {
                return [
                    'id' => $payment->id,
                    'invoice_id' => $payment->invoice_id,
                    'voucher_number' => $payment->invoice->voucher_number,
                    'supplier' => [
                        'id' => $payment->invoice->issuerCompany->id,
                        'name' => $payment->invoice->issuerCompany->business_name ?? $payment->invoice->issuerCompany->name,
                    ],
                    'amount' => $payment->amount,
                    'retentions' => $payment->retentions->map(fn($r) => [
                        'type' => $r->type,
                        'name' => $r->name,
                        'amount' => $r->amount,
                    ]),
                    'total_retentions' => $payment->retentions->sum('amount'),
                    'net_paid' => $payment->amount - $payment->retentions->sum('amount'),
                    'payment_date' => $payment->payment_date,
                    'payment_method' => $payment->payment_method,
                    'reference_number' => $payment->reference_number,
                    'status' => $payment->status,
                    'registered_by' => $payment->registeredBy->name ?? null,
                ];
            }),
            'pagination' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }
}
