<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Repositories\InvoiceRepository;
use App\Services\Afip\AfipInvoiceService;
use App\Services\InvoiceCalculationService;
use App\Services\CuitHelperService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class InvoiceService
{
    public function __construct(
        private InvoiceRepository $invoiceRepository,
        private InvoiceCalculationService $calculationService,
        private CuitHelperService $cuitHelper,
    ) {}

    /**
     * Get invoices with filters and formatting
     */
    public function getInvoices(string $companyId, array $filters = []): LengthAwarePaginator
    {
        $with = [
            'client' => function($query) { $query->withTrashed(); },
            'supplier' => function($query) { $query->withTrashed(); },
            'items',
            'issuerCompany',
            'receiverCompany',
            'approvals.user',
            'relatedInvoice',
            'payments',
            'collections'
        ];

        $query = Invoice::where(function($q) use ($companyId) {
                $q->where('issuer_company_id', $companyId)
                  ->orWhere('receiver_company_id', $companyId);
            })
            ->with($with);

        // Apply status filters
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $this->applyStatusFilter($query, $filters['status'], $companyId);
        }

        // Apply other filters
        if (isset($filters['search'])) {
            $this->applySearchFilter($query, $filters['search']);
        }

        if (isset($filters['type']) && $filters['type'] !== 'all') {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['client']) && $filters['client'] !== 'all') {
            $this->applyClientFilter($query, $filters['client']);
        }

        if (isset($filters['date_from'])) {
            $query->whereDate('issue_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('issue_date', '<=', $filters['date_to']);
        }

        // Exclude ND/NC with related_invoice_id (for approval page)
        if (isset($filters['exclude_associated_notes']) && $filters['exclude_associated_notes']) {
            $query->whereNull('related_invoice_id');
        }

        $invoices = $query->orderBy('created_at', 'desc')->paginate(20);

        // Format invoices for response
        $company = Company::find($companyId);
        $invoices->getCollection()->transform(function ($invoice) use ($company, $companyId) {
            return $this->formatInvoiceForResponse($invoice, $company, $companyId);
        });

        return $invoices;
    }

    /**
     * Apply status filter to query
     */
    private function applyStatusFilter($query, string $status, string $companyId): void
    {
        switch ($status) {
            case 'overdue':
                $query->whereDate('due_date', '<', now())
                      ->whereNotIn('status', ['cancelled'])
                      ->whereRaw("(company_statuses IS NULL OR JSON_SEARCH(company_statuses, 'one', 'rejected') IS NULL)")
                      ->whereDoesntHave('collections', function($q) use ($companyId) {
                          $q->where('company_id', $companyId)
                            ->where('status', 'confirmed');
                      })
                      ->whereNotExists(function($q) use ($companyId) {
                          $q->select(DB::raw(1))
                            ->from('invoice_payments_tracking')
                            ->whereColumn('invoice_payments_tracking.invoice_id', 'invoices.id')
                            ->where('invoice_payments_tracking.company_id', $companyId)
                            ->whereIn('invoice_payments_tracking.status', ['confirmed', 'in_process']);
                      });
                break;

            case 'collected':
                $query->where('issuer_company_id', $companyId)
                      ->where('status', '!=', 'cancelled')
                      ->whereHas('collections', function($q) use ($companyId) {
                          $q->where('company_id', $companyId)
                            ->where('status', 'confirmed');
                      });
                break;

            case 'paid':
                $query->where('receiver_company_id', $companyId)
                      ->where('status', '!=', 'cancelled')
                      ->whereExists(function($q) use ($companyId) {
                          $q->select(DB::raw(1))
                            ->from('invoice_payments_tracking')
                            ->whereColumn('invoice_payments_tracking.invoice_id', 'invoices.id')
                            ->where('invoice_payments_tracking.company_id', $companyId)
                            ->whereIn('invoice_payments_tracking.status', ['confirmed', 'in_process']);
                      });
                break;

            case 'approved':
                $query->whereRaw("JSON_SEARCH(company_statuses, 'one', 'approved') IS NOT NULL")
                      ->whereRaw("JSON_SEARCH(company_statuses, 'one', 'paid') IS NULL")
                      ->whereDoesntHave('collections', function($q) use ($companyId) {
                          $q->where('company_id', $companyId)
                            ->where('status', 'confirmed');
                      })
                      ->whereNotExists(function($q) use ($companyId) {
                          $q->select(DB::raw(1))
                            ->from('invoice_payments_tracking')
                            ->whereColumn('invoice_payments_tracking.invoice_id', 'invoices.id')
                            ->where('invoice_payments_tracking.company_id', $companyId)
                            ->whereIn('invoice_payments_tracking.status', ['confirmed', 'in_process']);
                      });
                break;

            case 'rejected':
                $query->whereRaw("JSON_SEARCH(company_statuses, 'one', 'rejected') IS NOT NULL");
                break;

            case 'pending_approval':
                $query->where('status', 'pending_approval')
                      ->where(function($q) use ($companyId) {
                          $q->whereNull('company_statuses')
                            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(company_statuses, '$.\"" . $companyId . "\"')) = 'pending_approval'");
                      });
                break;

            case 'issued':
                $query->where('status', 'issued')
                      ->whereDoesntHave('collections', function($q) use ($companyId) {
                          $q->where('company_id', $companyId)
                            ->where('status', 'confirmed');
                      })
                      ->whereNotExists(function($q) use ($companyId) {
                          $q->select(DB::raw(1))
                            ->from('invoice_payments_tracking')
                            ->whereColumn('invoice_payments_tracking.invoice_id', 'invoices.id')
                            ->where('invoice_payments_tracking.company_id', $companyId)
                            ->whereIn('invoice_payments_tracking.status', ['confirmed', 'in_process']);
                      });
                break;

            case 'partially_cancelled':
                $query->where('status', 'partially_cancelled')
                      ->where(function($q) {
                          $q->whereNull('balance_pending')
                            ->orWhere('balance_pending', '>', 0);
                      });
                break;

            default:
                $query->where('status', $status);
        }
    }

    /**
     * Apply search filter to query
     */
    private function applySearchFilter($query, string $search): void
    {
        $query->where(function($q) use ($search) {
            $q->where('number', 'like', '%' . $search . '%')
              ->orWhere('receiver_name', 'like', '%' . $search . '%')
              ->orWhereHas('client', function($q) use ($search) {
                  $q->where('business_name', 'like', '%' . $search . '%')
                    ->orWhere('first_name', 'like', '%' . $search . '%')
                    ->orWhere('last_name', 'like', '%' . $search . '%');
              });
        });
    }

    /**
     * Apply client filter to query
     */
    private function applyClientFilter($query, string $client): void
    {
        $query->where(function($q) use ($client) {
            $q->where('receiver_name', $client)
              ->orWhereHas('client', function($q) use ($client) {
                  $q->where('business_name', $client)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) = ?", [$client]);
              });
        });
    }

    /**
     * Format invoice for API response
     */
    public function formatInvoiceForResponse(Invoice $invoice, Company $company, string $companyId): Invoice
    {
        $invoice->direction = $invoice->issuer_company_id === $companyId ? 'issued' : 'received';
        
        // Use company statuses from JSON
        $companyStatuses = $invoice->company_statuses ?: [];
        $companyIdInt = (int)$companyId;
        
        // Prioritize cancelled if invoice is cancelled
        if ($invoice->status === 'cancelled') {
            $invoice->display_status = 'cancelled';
        } elseif (isset($companyStatuses[$companyIdInt])) {
            $invoice->display_status = $companyStatuses[$companyIdInt];
        } else {
            // Fallback to global status
            if ($invoice->direction === 'issued') {
                $invoice->display_status = $invoice->status;
            } else {
                $invoice->display_status = $invoice->status === 'issued' 
                    ? ($company->required_approvals > 0 ? 'pending_approval' : 'approved')
                    : $invoice->status;
            }
        }
        
        // Calculate payment_status and pending_amount
        $this->calculatePaymentStatus($invoice, $companyId);
        
        // Calculate available_balance (for related invoices - saldo disponible)
        $invoice->available_balance = $invoice->balance_pending ?? $invoice->total ?? 0;
        
        // Add associated NC/ND details
        $invoice->credit_notes_applied = Invoice::where('related_invoice_id', $invoice->id)
            ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
            ->where('status', '!=', 'cancelled')
            ->select('id', 'type', 'number', 'sales_point', 'voucher_number', 'total', 'issue_date')
            ->get();
        
        $invoice->debit_notes_applied = Invoice::where('related_invoice_id', $invoice->id)
            ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
            ->where('status', '!=', 'cancelled')
            ->select('id', 'type', 'number', 'sales_point', 'voucher_number', 'total', 'issue_date')
            ->get();
        
        $invoice->total_nc = $invoice->credit_notes_applied->sum('total');
        $invoice->total_nd = $invoice->debit_notes_applied->sum('total');
        
        // Override approvals_required if company is receiver
        if ($invoice->receiver_company_id === $companyId) {
            $invoice->approvals_required = $company->required_approvals;
        }
        
        // Add receiver_name and receiver_document if not saved
        if (!$invoice->receiver_name || !$invoice->receiver_document) {
            if ($invoice->receiverCompany) {
                $invoice->receiver_name = $invoice->receiverCompany->name;
                $invoice->receiver_document = $invoice->receiverCompany->national_id;
            } elseif ($invoice->client) {
                $invoice->receiver_name = $invoice->client->business_name 
                    ?? trim($invoice->client->first_name . ' ' . $invoice->client->last_name)
                    ?: null;
                $invoice->receiver_document = $invoice->client->document_number;
            }
        }
        
        // Format approvals for frontend
        $this->formatApprovals($invoice);
        
        return $invoice;
    }

    /**
     * Calculate payment status and pending amount
     */
    private function calculatePaymentStatus(Invoice $invoice, string $companyId): void
    {
        $paidAmount = 0;
        if ($invoice->direction === 'issued') {
            // For issued invoices, use collections
            $paidAmount = $invoice->collections->where('company_id', $companyId)->where('status', 'confirmed')->sum('amount');
        } else {
            // For received invoices, use payments
            $paidAmount = DB::table('invoice_payments_tracking')
                ->where('invoice_id', $invoice->id)
                ->where('company_id', $companyId)
                ->whereIn('status', ['confirmed', 'in_process'])
                ->sum('amount');
        }
        
        // Recalculate balance_pending considering NC/ND
        $totalNC = Invoice::where('related_invoice_id', $invoice->id)
            ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
            ->where('status', '!=', 'cancelled')
            ->sum('total');
        
        $totalND = Invoice::where('related_invoice_id', $invoice->id)
            ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
            ->where('status', '!=', 'cancelled')
            ->sum('total');
        
        $total = $invoice->total ?? 0;
        $adjustedTotal = $total + $totalND - $totalNC;
        $invoice->paid_amount = $paidAmount;
        $invoice->pending_amount = $adjustedTotal - $paidAmount;
        $invoice->balance_pending = $invoice->pending_amount;
        
        // Cancelled invoices don't have payment_status
        if ($invoice->status === 'cancelled') {
            $invoice->payment_status = 'cancelled';
            Log::info('Setting payment_status to cancelled', [
                'invoice_number' => $invoice->number,
                'status' => $invoice->status,
                'display_status' => $invoice->display_status ?? 'not set yet',
            ]);
        } elseif ($paidAmount >= $adjustedTotal) {
            // Set payment status based on invoice direction (operation type)
            if ($invoice->direction === 'issued') {
                $invoice->payment_status = 'collected'; // Cobrada
            } else {
                $invoice->payment_status = 'paid'; // Pagada
            }
        } elseif ($paidAmount > 0) {
            $invoice->payment_status = 'partial';
        } else {
            $invoice->payment_status = 'pending';
        }
    }

    /**
     * Format approvals for frontend
     */
    private function formatApprovals(Invoice $invoice): void
    {
        if ($invoice->approvals && $invoice->approvals->count() > 0) {
            $invoice->approvals = $invoice->approvals->map(function ($approval) {
                $fullName = trim($approval->user->first_name . ' ' . $approval->user->last_name);
                return [
                    'id' => $approval->id,
                    'user' => [
                        'id' => $approval->user->id,
                        'name' => $fullName ?: $approval->user->email,
                        'first_name' => $approval->user->first_name,
                        'last_name' => $approval->user->last_name,
                        'email' => $approval->user->email,
                    ],
                    'notes' => $approval->notes,
                    'approved_at' => $approval->approved_at->toIso8601String(),
                ];
            })->values();
        } else {
            $invoice->approvals = [];
        }
    }

    /**
     * Update related invoice balance when NC/ND is created
     * Los pagos/cobros NO deben afectar el balance - solo ND/NC
     */
    public function updateRelatedInvoiceBalance(string $relatedInvoiceId): void
    {
        $relatedInvoice = Invoice::find($relatedInvoiceId);
        if (!$relatedInvoice) {
            return;
        }
        
        // RECALCULAR SALDO: Total + ND - NC (SIN incluir pagos/cobros)
        $totalNC = Invoice::where('related_invoice_id', $relatedInvoice->id)
            ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
            ->where('status', '!=', 'cancelled')
            ->sum('total');
        
        $totalND = Invoice::where('related_invoice_id', $relatedInvoice->id)
            ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
            ->where('status', '!=', 'cancelled')
            ->sum('total');
        
        // Saldo = Total + ND - NC (solo ND/NC afectan el balance)
        $relatedInvoice->balance_pending = $relatedInvoice->total + $totalND - $totalNC;
        
        // Round to avoid precision issues
        $relatedInvoice->balance_pending = round($relatedInvoice->balance_pending, 2);
        
        Log::info('Recalculated invoice balance (manual load)', [
            'invoice_id' => $relatedInvoice->id,
            'invoice_number' => $relatedInvoice->number,
            'total' => $relatedInvoice->total,
            'total_nc' => $totalNC,
            'total_nd' => $totalND,
            'balance_pending' => $relatedInvoice->balance_pending,
        ]);
        
        // Update status based on balance
        if ($relatedInvoice->balance_pending < 0.01) { // Only cancel if balance < $0.01
            $relatedInvoice->status = 'cancelled';
            $relatedInvoice->balance_pending = 0;
            
            Log::info('Invoice automatically cancelled by manual NC/ND', [
                'invoice_id' => $relatedInvoice->id,
                'invoice_number' => $relatedInvoice->number,
            ]);
        } else if ($relatedInvoice->balance_pending < $relatedInvoice->total) {
            // Partial cancellation
            $relatedInvoice->status = 'partially_cancelled';
            
            Log::info('Invoice partially cancelled by manual NC/ND', [
                'invoice_id' => $relatedInvoice->id,
                'invoice_number' => $relatedInvoice->number,
                'new_balance' => $relatedInvoice->balance_pending,
            ]);
        }
        
        $relatedInvoice->save();
    }

    /**
     * Get authorized sales points from AFIP
     */
    public function getAuthorizedSalesPoints(Company $company): array
    {
        try {
            $client = new \App\Services\Afip\AfipWebServiceClient($company->afipCertificate);
            $salesPoints = $client->getSalesPoints();
            
            // Filter only active points (not blocked and without drop date)
            $activePoints = array_filter($salesPoints, function($sp) {
                return !$sp['blocked'] && empty($sp['drop_date']);
            });
            
            $numbers = array_map(fn($sp) => $sp['point_number'], $activePoints);
            
            Log::info('Retrieved authorized sales points from AFIP', [
                'company_id' => $company->id,
                'points' => $numbers,
            ]);
            
            return !empty($numbers) ? $numbers : range(1, 10);
        } catch (\Exception $e) {
            Log::warning('Could not get sales points from AFIP, using fallback', [
                'error' => $e->getMessage()
            ]);
            return range(1, 10);
        }
    }

    /**
     * Get single invoice with formatting
     */
    public function getInvoice(string $companyId, string $invoiceId): Invoice
    {
        $invoice = Invoice::where(function($q) use ($companyId) {
                $q->where('issuer_company_id', $companyId)
                  ->orWhere('receiver_company_id', $companyId);
            })
            ->with([
                'client' => function($query) { $query->withTrashed(); },
                'supplier' => function($query) { $query->withTrashed(); },
                'items',
                'receiverCompany',
                'issuerCompany',
                'perceptions',
                'collections'
            ])
            ->findOrFail($invoiceId);

        $company = Company::findOrFail($companyId);
        
        // Use formatInvoiceForResponse to ensure all computed fields are set
        $formattedInvoice = $this->formatInvoiceForResponse($invoice, $company, $companyId);
        
        // Ensure available_balance is always set (for related invoices)
        if (!isset($formattedInvoice->available_balance)) {
            $formattedInvoice->available_balance = $formattedInvoice->balance_pending ?? $formattedInvoice->total ?? 0;
        }
        
        // Add retentions from confirmed collections
        $confirmedCollections = $formattedInvoice->collections->where('status', 'confirmed');
        if ($confirmedCollections->isNotEmpty()) {
            $formattedInvoice->withholding_iibb = $confirmedCollections->sum('withholding_iibb');
            $formattedInvoice->withholding_iibb_notes = $confirmedCollections->whereNotNull('withholding_iibb_notes')->pluck('withholding_iibb_notes')->filter()->implode(', ');
            $formattedInvoice->withholding_iva = $confirmedCollections->sum('withholding_iva');
            $formattedInvoice->withholding_iva_notes = $confirmedCollections->whereNotNull('withholding_iva_notes')->pluck('withholding_iva_notes')->filter()->implode(', ');
            $formattedInvoice->withholding_ganancias = $confirmedCollections->sum('withholding_ganancias');
            $formattedInvoice->withholding_ganancias_notes = $confirmedCollections->whereNotNull('withholding_ganancias_notes')->pluck('withholding_ganancias_notes')->filter()->implode(', ');
            $formattedInvoice->withholding_suss = $confirmedCollections->sum('withholding_suss');
            $formattedInvoice->withholding_suss_notes = $confirmedCollections->whereNotNull('withholding_suss_notes')->pluck('withholding_suss_notes')->filter()->implode(', ');
            $formattedInvoice->withholding_other = $confirmedCollections->sum('withholding_other');
            $formattedInvoice->withholding_other_notes = $confirmedCollections->whereNotNull('withholding_other_notes')->pluck('withholding_other_notes')->filter()->implode(', ');
        }

        // Add balance breakdown (NC/ND details)
        $formattedInvoice->balance_breakdown = $formattedInvoice->getBalanceBreakdown();

        return $formattedInvoice;
    }
}

