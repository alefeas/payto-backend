<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AccountsPayableController extends Controller
{
    public function getDashboard(string $companyId)
    {
        try {
            $company = Company::findOrFail($companyId);
            $today = Carbon::today();
            
            // Obtener todas las facturas recibidas aprobadas y pendientes de pago
            $company = Company::findOrFail($companyId);
            $requiredApprovals = $company->required_approvals ?? 0;
            
            $query = Invoice::where('receiver_company_id', $companyId)
                ->whereNotIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE', 'NDA', 'NDB', 'NDC', 'NDM', 'NDE']) // Excluir NC/ND
                ->with(['issuerCompany', 'supplier.company', 'payments']);
            
            if ($requiredApprovals > 0) {
                $query->where('approvals_received', '>=', $requiredApprovals);
            }
            
            $allInvoices = $query->get();
        
            // Calcular paid_amount desde payments y considerar NC/ND relacionadas
            $allInvoices->each(function($invoice) {
                $invoice->paid_amount = $invoice->payments->sum('amount');
                
                // Calcular total ajustado considerando NC/ND relacionadas
                // Solo contar NC/ND que tengan CAE (fueron autorizadas por AFIP)
                $totalNC = Invoice::where('related_invoice_id', $invoice->id)
                    ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
                    ->where('status', '!=', 'cancelled')
                    ->whereNotNull('afip_cae')
                    ->sum('total');
                
                $totalND = Invoice::where('related_invoice_id', $invoice->id)
                    ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
                    ->where('status', '!=', 'cancelled')
                    ->whereNotNull('afip_cae')
                    ->sum('total');
                
                $adjustedTotal = ($invoice->total ?? 0) + $totalND - $totalNC;
                $invoice->pending_amount = max(0, $adjustedTotal - $invoice->paid_amount);
            });
        
            // Métricas principales - usar pending_amount que ya considera NC/ND
            $totalPayable = $allInvoices->sum(function($inv) {
                // Solo contar NC/ND que tengan CAE (fueron autorizadas por AFIP)
                $totalNC = Invoice::where('related_invoice_id', $inv->id)
                    ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
                    ->where('status', '!=', 'cancelled')
                    ->whereNotNull('afip_cae')
                    ->sum('total');
                $totalND = Invoice::where('related_invoice_id', $inv->id)
                    ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
                    ->where('status', '!=', 'cancelled')
                    ->whereNotNull('afip_cae')
                    ->sum('total');
                return ($inv->total ?? 0) + $totalND - $totalNC;
            });
            $totalPaid = $allInvoices->sum('paid_amount');
            $totalPending = $allInvoices->sum('pending_amount');
            
            // Facturas vencidas
            $overdue = $allInvoices
                ->filter(function($inv) use ($today) {
                    return $inv->due_date < $today && $inv->pending_amount > 0;
                })
                ->map(function($inv) {
                    $supplierName = null;
                    if ($inv->supplier) {
                        $supplierName = $inv->supplier->business_name 
                            ?? ($inv->supplier->first_name && $inv->supplier->last_name 
                                ? trim($inv->supplier->first_name . ' ' . $inv->supplier->last_name) 
                                : null);
                    }
                    if (!$supplierName && $inv->issuerCompany) {
                        $supplierName = $inv->issuerCompany->business_name ?? $inv->issuerCompany->name;
                    }
                    $supplierName = $supplierName ?? 'Sin nombre';
                    
                    return [
                        'id' => $inv->id,
                        'supplier' => $supplierName,
                        'voucher_number' => ($inv->type ?? 'FC') . ' ' . str_pad($inv->sales_point ?? 0, 4, '0', STR_PAD_LEFT) . '-' . str_pad($inv->voucher_number ?? 0, 8, '0', STR_PAD_LEFT),
                        'due_date' => $inv->due_date,
                        'issue_date' => $inv->issue_date,
                        'days_overdue' => Carbon::parse($inv->due_date)->diffInDays(Carbon::today()),
                        'pending_amount' => $inv->pending_amount,
                    ];
                })
                ->values();
            
            // Próximos vencimientos (30 días)
            $upcoming = $allInvoices
                ->filter(function($inv) use ($today) {
                    $dueDate = Carbon::parse($inv->due_date);
                    return $dueDate >= $today && $dueDate <= $today->copy()->addDays(30) && $inv->pending_amount > 0;
                })
                ->sortBy('due_date')
                ->map(function($inv) {
                    $supplierName = null;
                    if ($inv->supplier) {
                        $supplierName = $inv->supplier->business_name 
                            ?? ($inv->supplier->first_name && $inv->supplier->last_name 
                                ? trim($inv->supplier->first_name . ' ' . $inv->supplier->last_name) 
                                : null);
                    }
                    if (!$supplierName && $inv->issuerCompany) {
                        $supplierName = $inv->issuerCompany->business_name ?? $inv->issuerCompany->name;
                    }
                    $supplierName = $supplierName ?? 'Sin nombre';
                    
                    return [
                        'id' => $inv->id,
                        'supplier' => $supplierName,
                        'voucher_number' => ($inv->type ?? 'FC') . ' ' . str_pad($inv->sales_point ?? 0, 4, '0', STR_PAD_LEFT) . '-' . str_pad($inv->voucher_number ?? 0, 8, '0', STR_PAD_LEFT),
                        'due_date' => $inv->due_date,
                        'issue_date' => $inv->issue_date,
                        'days_until_due' => Carbon::today()->diffInDays(Carbon::parse($inv->due_date)),
                        'pending_amount' => $inv->pending_amount,
                    ];
                })
                ->values();
            
            // Por proveedor (usar supplier_id o issuer_company_id, excluir si emisor = receptor)
            $bySupplier = $allInvoices
                ->filter(function($inv) use ($companyId) {
                    if ($inv->pending_amount <= 0) return false;
                    // Excluir si el emisor es la misma empresa receptora y no hay supplier
                    if ($inv->issuer_company_id === $companyId && !$inv->supplier_id) return false;
                    return true;
                })
                ->groupBy(function($inv) {
                    // Agrupar por supplier_id si existe, sino por issuer_company_id, sino por 'unknown'
                    return $inv->supplier_id ?? $inv->issuer_company_id ?? 'unknown';
                })
                ->map(function($invoices, $supplierId) {
                    $first = $invoices->first();
                    $supplierName = null;
                    
                    if ($first->supplier) {
                        $supplierName = $first->supplier->business_name 
                            ?? ($first->supplier->first_name && $first->supplier->last_name 
                                ? trim($first->supplier->first_name . ' ' . $first->supplier->last_name) 
                                : null);
                    }
                    if (!$supplierName && $first->issuerCompany) {
                        $supplierName = $first->issuerCompany->business_name ?? $first->issuerCompany->name;
                    }
                    $supplierName = $supplierName ?? 'Sin nombre';
                    
                    return [
                        'supplier_id' => $supplierId,
                        'supplier_name' => $supplierName,
                        'invoice_count' => $invoices->count(),
                        'total_pending' => $invoices->sum('pending_amount'),
                    ];
                })
                ->sortByDesc('total_pending')
                ->values();
        
            // Pagos recientes
            $recentPayments = Payment::where('company_id', $companyId)
                ->with(['invoice.issuerCompany', 'invoice.supplier', 'retentions'])
                ->orderByDesc('payment_date')
                ->limit(10)
                ->get()
                ->map(function($payment) {
                    $supplierName = 'Proveedor desconocido';
                    $currency = 'ARS';
                    $exchangeRate = 1;
                    
                    if ($payment->invoice) {
                        $currency = $payment->invoice->currency ?? 'ARS';
                        $exchangeRate = $payment->invoice->exchange_rate ?? 1;
                        
                        if ($payment->invoice->supplier) {
                            $supplierName = $payment->invoice->supplier->business_name 
                                ?? ($payment->invoice->supplier->first_name && $payment->invoice->supplier->last_name 
                                    ? trim($payment->invoice->supplier->first_name . ' ' . $payment->invoice->supplier->last_name) 
                                    : 'Sin nombre');
                        } elseif ($payment->invoice->issuerCompany) {
                            $supplierName = $payment->invoice->issuerCompany->business_name ?? $payment->invoice->issuerCompany->name ?? 'Sin nombre';
                        }
                    }
                    
                    $retentionsSum = $payment->retentions ? $payment->retentions->sum('amount') : 0;
                    
                    return [
                        'id' => $payment->id,
                        'date' => $payment->payment_date,
                        'supplier' => $supplierName,
                        'amount' => $payment->amount ?? 0,
                        'currency' => $currency,
                        'exchange_rate' => $exchangeRate,
                        'retentions' => $retentionsSum,
                        'net_paid' => ($payment->amount ?? 0) - $retentionsSum,
                        'method' => $payment->payment_method ?? 'unknown',
                        'status' => $payment->status ?? 'unknown',
                    ];
                });
        
            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => [
                        'total_payable' => $totalPayable ?? 0,
                        'total_paid' => Payment::where('company_id', $companyId)->sum('amount') ?? 0,
                        'total_pending' => $totalPending ?? 0,
                        'overdue_count' => $overdue->count(),
                        'overdue_amount' => $overdue->sum('pending_amount') ?? 0,
                        'upcoming_count' => $upcoming->count(),
                        'upcoming_amount' => $upcoming->sum('pending_amount') ?? 0,
                    ],
                    'overdue_invoices' => $overdue->toArray(),
                    'upcoming_invoices' => $upcoming->take(10)->toArray(),
                    'by_supplier' => $bySupplier->take(10)->toArray(),
                    'recent_payments' => $recentPayments->toArray(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getDashboard: ' . $e->getMessage(), [
                'company_id' => $companyId,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Error al cargar el dashboard: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getInvoices(Request $request, string $companyId)
    {
        try {
            $company = Company::findOrFail($companyId);
            $requiredApprovals = $company->required_approvals ?? 0;
            
            $query = Invoice::where('receiver_company_id', $companyId)
                ->whereNotIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE', 'NDA', 'NDB', 'NDC', 'NDM', 'NDE']) // Excluir NC/ND
                ->with([
                    'issuerCompany.primaryBankAccount',
                    'issuerCompany.bankAccounts',
                    'supplier.company',
                    'payments',
                    'creditNotes',
                    'debitNotes'
                ]);
        
            // Filtros
            if ($request->has('supplier_id')) {
                $query->where('issuer_company_id', $request->supplier_id);
            }
            
            if ($request->has('from_date')) {
                $query->where('issue_date', '>=', $request->from_date);
            }
            
            if ($request->has('to_date')) {
                $query->where('issue_date', '<=', $request->to_date);
            }
            
            if ($request->has('overdue') && $request->overdue) {
                $query->where('due_date', '<', Carbon::today());
            }
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('voucher_number', 'like', "%{$search}%")
                      ->orWhereHas('issuerCompany', function($q) use ($search) {
                          $q->where('business_name', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                      });
                });
            }
            
            if ($requiredApprovals > 0) {
                $query->where('approvals_received', '>=', $requiredApprovals);
            }
            
            $invoices = $query->orderByDesc('issue_date')->get();
            
            // Calcular payment_status y pending_amount dinámicamente
            // Considerando NC/ND relacionadas que afectan el monto a pagar
            $invoices->each(function($invoice) use ($companyId) {
                $paidAmount = $invoice->payments->sum('amount');
                
                // Obtener NC/ND relacionadas con detalles
                $creditNotes = Invoice::where('related_invoice_id', $invoice->id)
                    ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
                    ->where('status', '!=', 'cancelled')
                    ->select('id', 'type', 'number', 'sales_point', 'voucher_number', 'total', 'issue_date')
                    ->get();
                
                $debitNotes = Invoice::where('related_invoice_id', $invoice->id)
                    ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
                    ->where('status', '!=', 'cancelled')
                    ->select('id', 'type', 'number', 'sales_point', 'voucher_number', 'total', 'issue_date')
                    ->get();
                
                $totalNC = $creditNotes->sum('total');
                $totalND = $debitNotes->sum('total');
                
                $baseTotal = $invoice->total ?? 0;
                $adjustedTotal = $baseTotal + $totalND - $totalNC; // ND suma, NC resta
                
                if ($paidAmount >= $adjustedTotal) {
                    $invoice->payment_status = 'paid';
                } elseif ($paidAmount > 0) {
                    $invoice->payment_status = 'partial';
                } else {
                    $invoice->payment_status = 'pending';
                }
                
                $invoice->paid_amount = $paidAmount;
                $invoice->pending_amount = max(0, $adjustedTotal - $paidAmount); // No negativo
                $invoice->credit_notes_applied = $creditNotes;
                $invoice->debit_notes_applied = $debitNotes;
                $invoice->total_nc = $totalNC;
                $invoice->total_nd = $totalND;
                
                // Add bank data availability flag
                $hasBankData = false;
                if ($invoice->supplier) {
                    $hasBankData = (!empty($invoice->supplier->bank_cbu) && strlen($invoice->supplier->bank_cbu) >= 22) || 
                                   !empty($invoice->supplier->bank_account_number);
                } elseif ($invoice->issuerCompany) {
                    // Check primary bank account or any bank account
                    $primaryBankAccount = $invoice->issuerCompany->primaryBankAccount ?? $invoice->issuerCompany->bankAccounts->first();
                    if ($primaryBankAccount) {
                        $hasBankData = (!empty($primaryBankAccount->cbu) && strlen($primaryBankAccount->cbu) >= 22);
                    } elseif (!empty($invoice->issuerCompany->cbu) && strlen($invoice->issuerCompany->cbu) >= 22) {
                        $hasBankData = true;
                    }
                }
                $invoice->has_bank_data = $hasBankData;
            });
            
            // Filtrar solo facturas con saldo pendiente (excluir pagadas/canceladas completamente)
            $invoices = $invoices->filter(function($inv) {
                if ($inv->status === 'cancelled') return false;
                if ($inv->payment_status === 'paid' || $inv->payment_status === 'collected') return false;
                return $inv->pending_amount > 0;
            })->values();
            
            // Aplicar filtro de payment_status si existe
            if ($request->has('payment_status')) {
                $invoices = $invoices->filter(function($inv) use ($request) {
                    return $inv->payment_status === $request->payment_status;
                });
            }
            
            // Paginación manual
            $page = $request->get('page', 1);
            $perPage = 20;
            $total = $invoices->count();
            $invoices = $invoices->slice(($page - 1) * $perPage, $perPage)->values();
            
            // Ensure supplier and issuerCompany data is included in response
            $invoicesArray = $invoices->map(function($invoice) {
                $data = $invoice->toArray();
                // Explicitly include supplier data if exists
                if ($invoice->supplier) {
                    $data['supplier'] = [
                        'id' => $invoice->supplier->id,
                        'company_id' => $invoice->supplier->company_id,
                        'document_number' => $invoice->supplier->document_number,
                        'business_name' => $invoice->supplier->business_name,
                        'first_name' => $invoice->supplier->first_name,
                        'last_name' => $invoice->supplier->last_name,
                        'bank_name' => $invoice->supplier->bank_name,
                        'bank_cbu' => $invoice->supplier->bank_cbu,
                        'bank_account_number' => $invoice->supplier->bank_account_number,
                        'bank_account_type' => $invoice->supplier->bank_account_type,
                        'bank_alias' => $invoice->supplier->bank_alias,
                    ];
                }
                // Explicitly include issuerCompany data if exists (with bank data)
                if ($invoice->issuerCompany) {
                    $primaryBankAccount = $invoice->issuerCompany->primaryBankAccount ?? $invoice->issuerCompany->bankAccounts->first();
                    
                    $data['issuerCompany'] = [
                        'id' => $invoice->issuerCompany->id,
                        'business_name' => $invoice->issuerCompany->business_name,
                        'name' => $invoice->issuerCompany->name,
                        'national_id' => $invoice->issuerCompany->national_id,
                        'cbu' => $invoice->issuerCompany->cbu, // Legacy field
                        // Bank account data (use primary or first)
                        'bank_name' => $primaryBankAccount->bank_name ?? null,
                        'bank_cbu' => $primaryBankAccount->cbu ?? $invoice->issuerCompany->cbu ?? null,
                        'bank_account_type' => $primaryBankAccount->account_type ?? null,
                        'bank_alias' => $primaryBankAccount->alias ?? null,
                        'bank_account_number' => null, // Not stored in BankAccount model
                    ];
                }
                return $data;
            });
            
            return response()->json([
                'success' => true,
                'data' => $invoicesArray->toArray(),
                'pagination' => [
                    'current_page' => $page,
                    'last_page' => ceil($total / $perPage),
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getInvoices: ' . $e->getMessage(), [
                'company_id' => $companyId,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Error al cargar facturas: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getSupplierSummary(string $companyId, string $supplierId)
    {
        $company = Company::findOrFail($companyId);
        $requiredApprovals = $company->required_approvals ?? 0;
        
        $query = Invoice::where('receiver_company_id', $companyId)
            ->where('issuer_company_id', $supplierId)
            ->with('payments.retentions');
        
        if ($requiredApprovals > 0) {
            $query->where('approvals_received', '>=', $requiredApprovals);
        }
        
        $invoices = $query->get();
            
        // Calcular paid_amount desde payments
        $invoices->each(function($invoice) {
            $invoice->paid_amount = $invoice->payments->sum('amount');
            $invoice->pending_amount = $invoice->total - $invoice->paid_amount;
        });
        
        $supplier = Company::findOrFail($supplierId);
        
        return response()->json([
            'success' => true,
            'data' => [
                'supplier' => [
                    'id' => $supplier->id,
                    'name' => $supplier->business_name ?? $supplier->name,
                    'cuit' => $supplier->national_id,
                    'tax_condition' => $supplier->tax_condition,
                ],
                'summary' => [
                    'total_invoices' => $invoices->count(),
                    'total_amount' => $invoices->sum('total'),
                    'paid_amount' => $invoices->sum('paid_amount'),
                    'pending_amount' => $invoices->sum('pending_amount'),
                    'overdue_count' => $invoices->where('due_date', '<', Carbon::today())->filter(fn($inv) => $inv->pending_amount > 0)->count(),
                ],
                'invoices' => $invoices->map(function($invoice) {
                    return [
                        'id' => $invoice->id,
                        'voucher_number' => $invoice->voucher_number,
                        'issue_date' => $invoice->issue_date,
                        'due_date' => $invoice->due_date,
                        'total_amount' => $invoice->total,
                        'paid_amount' => $invoice->paid_amount,
                        'pending_amount' => $invoice->pending_amount,
                        'payment_status' => $invoice->paid_amount >= $invoice->total ? 'paid' : ($invoice->paid_amount > 0 ? 'partial' : 'pending'),
                    ];
                }),
            ],
        ]);
    }

    public function getDefaultRetentions(string $companyId)
    {
        $company = Company::findOrFail($companyId);
        
        return response()->json([
            'success' => true,
            'data' => [
                'is_retention_agent' => $company->is_retention_agent ?? false,
                'auto_retentions' => $company->auto_retentions ?? [],
            ],
        ]);
    }

    public function generatePaymentTxt(Request $request, string $companyId)
    {
        $validated = $request->validate([
            'invoice_ids' => 'required|array|min:1',
            'invoice_ids.*' => 'required|uuid|exists:invoices,id',
        ]);

        $company = Company::findOrFail($companyId);
        
        $invoices = Invoice::whereIn('id', $validated['invoice_ids'])
            ->with(['supplier', 'issuerCompany.primaryBankAccount', 'issuerCompany.bankAccounts', 'payments'])
            ->get();
        
        $lines = [];
        $totalAmount = 0;
        $recordCount = 0;
        
        foreach ($invoices as $invoice) {
            $supplier = $invoice->supplier ?? $invoice->issuerCompany;
            
            if (!$supplier) continue;
            
            // Get bank data (for connected companies, check primaryBankAccount)
            $cbu = '';
            $accountNumber = '';
            
            if ($invoice->supplier) {
                $cbu = $invoice->supplier->bank_cbu ?? '';
                $accountNumber = $invoice->supplier->bank_account_number ?? '';
            } elseif ($invoice->issuerCompany) {
                $primaryBankAccount = $invoice->issuerCompany->primaryBankAccount ?? $invoice->issuerCompany->bankAccounts->first();
                if ($primaryBankAccount) {
                    $cbu = $primaryBankAccount->cbu ?? '';
                }
                if (empty($cbu)) {
                    $cbu = $invoice->issuerCompany->cbu ?? '';
                }
            }
            
            $cuit = $supplier->document_number ?? $supplier->national_id ?? '';
            $name = $supplier->business_name ?? ($supplier->first_name && $supplier->last_name ? $supplier->first_name . ' ' . $supplier->last_name : $supplier->name ?? '');
            
            // Skip if no bank data
            if (empty($cbu) && empty($accountNumber)) continue;
            
            // Calculate pending amount
            $paidAmount = $invoice->payments->sum('amount');
            $amount = $invoice->total - $paidAmount;
            
            // Normalize text (remove accents and special characters)
            $name = $this->normalizeText($name);
            
            // Standard format for Argentine banks
            $line = sprintf(
                "%s;%s;%s;%s;%s;%s",
                str_replace('-', '', $cuit),
                $name,
                $cbu,
                number_format($amount, 2, '.', ''),
                $invoice->voucher_number ?? '',
                'Pago Factura ' . ($invoice->type ?? 'FC') . ' ' . str_pad($invoice->sales_point ?? 0, 4, '0', STR_PAD_LEFT) . '-' . str_pad($invoice->voucher_number ?? 0, 8, '0', STR_PAD_LEFT)
            );
            
            $lines[] = $line;
            $totalAmount += $amount;
            $recordCount++;
        }
        
        if (empty($lines)) {
            return response()->json([
                'success' => false,
                'error' => 'No invoices with valid bank data found',
            ], 400);
        }
        
        // Header
        array_unshift($lines, "CUIT;RAZON_SOCIAL;CBU;IMPORTE;REFERENCIA;CONCEPTO");
        
        // Footer
        $lines[] = sprintf(
            "TOTAL;%d registros;%s",
            $recordCount,
            number_format($totalAmount, 2, '.', '')
        );
        
        $txtContent = implode("\r\n", $lines);
        $filename = 'pagos_' . date('Ymd_His') . '.txt';
        
        return response($txtContent)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    private function normalizeText(string $text): string
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = preg_replace('/[^A-Za-z0-9 \-\.\,]/', '', $text);
        return strtoupper(trim($text));
    }

    /**
     * Get balances including unassociated NC/ND
     */
    public function getBalances(string $companyId)
    {
        try {
            $company = Company::findOrFail($companyId);
            
            // NC/ND sin factura asociada (solo recibidas de proveedores)
            // Estas son saldos pendientes que deben manejarse independientemente
            $unassociatedCreditNotes = Invoice::where('receiver_company_id', $companyId)
                ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
                ->whereNull('related_invoice_id')
                ->where('status', '!=', 'cancelled')
                ->with(['issuerCompany', 'supplier.company'])
                ->get()
                ->map(function($nc) {
                    $supplierName = null;
                    if ($nc->supplier) {
                        $supplierName = $nc->supplier->business_name 
                            ?? ($nc->supplier->first_name && $nc->supplier->last_name 
                                ? trim($nc->supplier->first_name . ' ' . $nc->supplier->last_name) 
                                : null);
                    }
                    if (!$supplierName && $nc->issuerCompany) {
                        $supplierName = $nc->issuerCompany->business_name ?? $nc->issuerCompany->name;
                    }
                    
                    return [
                        'id' => $nc->id,
                        'type' => $nc->type,
                        'number' => $nc->number,
                        'voucher_number' => ($nc->type ?? 'NC') . ' ' . str_pad($nc->sales_point ?? 0, 4, '0', STR_PAD_LEFT) . '-' . str_pad($nc->voucher_number ?? 0, 8, '0', STR_PAD_LEFT),
                        'issue_date' => $nc->issue_date,
                        'due_date' => $nc->due_date,
                        'supplier_name' => $supplierName ?? 'Sin nombre',
                        'total' => $nc->total,
                        'currency' => $nc->currency ?? 'ARS',
                        'exchange_rate' => $nc->exchange_rate ?? 1,
                        'balance_type' => 'credit', // A favor nuestro (reduce lo que debemos pagar)
                        'description' => 'Nota de Crédito sin factura asociada',
                    ];
                });
            
            $unassociatedDebitNotes = Invoice::where('receiver_company_id', $companyId)
                ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
                ->whereNull('related_invoice_id')
                ->where('status', '!=', 'cancelled')
                ->with(['issuerCompany', 'supplier.company'])
                ->get()
                ->map(function($nd) {
                    $supplierName = null;
                    if ($nd->supplier) {
                        $supplierName = $nd->supplier->business_name 
                            ?? ($nd->supplier->first_name && $nd->supplier->last_name 
                                ? trim($nd->supplier->first_name . ' ' . $nd->supplier->last_name) 
                                : null);
                    }
                    if (!$supplierName && $nd->issuerCompany) {
                        $supplierName = $nd->issuerCompany->business_name ?? $nd->issuerCompany->name;
                    }
                    
                    return [
                        'id' => $nd->id,
                        'type' => $nd->type,
                        'number' => $nd->number,
                        'voucher_number' => ($nd->type ?? 'ND') . ' ' . str_pad($nd->sales_point ?? 0, 4, '0', STR_PAD_LEFT) . '-' . str_pad($nd->voucher_number ?? 0, 8, '0', STR_PAD_LEFT),
                        'issue_date' => $nd->issue_date,
                        'due_date' => $nd->due_date,
                        'supplier_name' => $supplierName ?? 'Sin nombre',
                        'total' => $nd->total,
                        'currency' => $nd->currency ?? 'ARS',
                        'exchange_rate' => $nd->exchange_rate ?? 1,
                        'balance_type' => 'debit', // En contra nuestro (aumenta lo que debemos pagar)
                        'description' => 'Nota de Débito sin factura asociada',
                    ];
                });
            
            // Calcular totales
            $totalCredits = $unassociatedCreditNotes->sum('total');
            $totalDebits = $unassociatedDebitNotes->sum('total');
            $netBalance = $totalDebits - $totalCredits; // Positivo = debemos pagar, Negativo = tenemos crédito
            
            return response()->json([
                'success' => true,
                'data' => [
                    'credit_notes' => $unassociatedCreditNotes->values()->toArray(),
                    'debit_notes' => $unassociatedDebitNotes->values()->toArray(),
                    'summary' => [
                        'total_credits' => $totalCredits,
                        'total_debits' => $totalDebits,
                        'net_balance' => $netBalance,
                        'net_balance_type' => $netBalance >= 0 ? 'debit' : 'credit',
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getBalances: ' . $e->getMessage(), [
                'company_id' => $companyId,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Error al cargar saldos: ' . $e->getMessage()
            ], 500);
        }
    }
}
