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
            
            // Obtener todas las facturas recibidas APROBADAS (no pagadas) con pagos
            $allInvoices = Invoice::where('receiver_company_id', $companyId)
                ->where('status', 'approved')
                ->with(['issuerCompany', 'supplier', 'payments'])
                ->get();
        
            // Calcular paid_amount desde payments
            $allInvoices->each(function($invoice) {
                $invoice->paid_amount = $invoice->payments->sum('amount');
                $invoice->pending_amount = $invoice->total - $invoice->paid_amount;
            });
        
            // Métricas principales
            $totalPayable = $allInvoices->sum('total');
            $totalPaid = $allInvoices->sum('paid_amount');
            $totalPending = $totalPayable - $totalPaid;
            
            // Facturas vencidas
            $overdue = $allInvoices
                ->filter(function($inv) use ($today) {
                    return $inv->due_date < $today && $inv->pending_amount > 0;
                })
                ->map(function($inv) {
                    $supplierName = 'Proveedor desconocido';
                    if ($inv->supplier) {
                        $supplierName = $inv->supplier->business_name ?? $inv->supplier->name ?? 'Sin nombre';
                    } elseif ($inv->issuerCompany) {
                        $supplierName = $inv->issuerCompany->business_name ?? $inv->issuerCompany->name ?? 'Sin nombre';
                    }
                    
                    return [
                        'id' => $inv->id,
                        'supplier' => $supplierName,
                        'voucher_number' => $inv->voucher_number ?? 'S/N',
                        'due_date' => $inv->due_date,
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
                    $supplierName = 'Proveedor desconocido';
                    if ($inv->supplier) {
                        $supplierName = $inv->supplier->business_name ?? $inv->supplier->name ?? 'Sin nombre';
                    } elseif ($inv->issuerCompany) {
                        $supplierName = $inv->issuerCompany->business_name ?? $inv->issuerCompany->name ?? 'Sin nombre';
                    }
                    
                    return [
                        'id' => $inv->id,
                        'supplier' => $supplierName,
                        'voucher_number' => $inv->voucher_number ?? 'S/N',
                        'due_date' => $inv->due_date,
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
                    $supplierName = 'Proveedor eliminado';
                    
                    if ($first->supplier) {
                        $supplierName = $first->supplier->business_name ?? $first->supplier->name ?? 'Sin nombre';
                    } elseif ($first->issuerCompany) {
                        $supplierName = $first->issuerCompany->business_name ?? $first->issuerCompany->name ?? 'Sin nombre';
                    }
                    
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
                ->with(['invoice.issuerCompany', 'retentions'])
                ->orderByDesc('payment_date')
                ->limit(10)
                ->get()
                ->map(function($payment) {
                    $supplierName = 'Proveedor desconocido';
                    if ($payment->invoice && $payment->invoice->issuerCompany) {
                        $supplierName = $payment->invoice->issuerCompany->business_name ?? $payment->invoice->issuerCompany->name ?? 'Sin nombre';
                    }
                    
                    $retentionsSum = $payment->retentions ? $payment->retentions->sum('amount') : 0;
                    
                    return [
                        'id' => $payment->id,
                        'date' => $payment->payment_date,
                        'supplier' => $supplierName,
                        'amount' => $payment->amount ?? 0,
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
            $query = Invoice::where('receiver_company_id', $companyId)
                ->where('status', 'approved')
                ->with(['issuerCompany', 'supplier', 'payments']);
        
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
            
            $invoices = $query->orderByDesc('issue_date')->get();
            
            // Calcular payment_status y pending_amount dinámicamente
            $invoices->each(function($invoice) {
                $paidAmount = $invoice->payments->sum('amount');
                $total = $invoice->total ?? 0;
                
                if ($paidAmount >= $total) {
                    $invoice->payment_status = 'paid';
                } elseif ($paidAmount > 0) {
                    $invoice->payment_status = 'partial';
                } else {
                    $invoice->payment_status = 'pending';
                }
                
                $invoice->paid_amount = $paidAmount;
                $invoice->pending_amount = $total - $paidAmount;
                
                // Add bank data availability flag
                $hasBankData = false;
                if ($invoice->supplier) {
                    $hasBankData = (!empty($invoice->supplier->bank_cbu) && strlen($invoice->supplier->bank_cbu) >= 22) || 
                                   !empty($invoice->supplier->bank_account_number);
                } elseif ($invoice->issuerCompany) {
                    $hasBankData = (!empty($invoice->issuerCompany->bank_cbu) && strlen($invoice->issuerCompany->bank_cbu) >= 22) || 
                                   !empty($invoice->issuerCompany->bank_account_number);
                }
                $invoice->has_bank_data = $hasBankData;
            });
            
            // Filtrar facturas completamente pagadas
            $invoices = $invoices->filter(function($inv) {
                return $inv->pending_amount > 0;
            });
            
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
            
            // Ensure supplier bank data is included in response
            $invoicesArray = $invoices->map(function($invoice) {
                $data = $invoice->toArray();
                // Explicitly include supplier bank data if exists
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
        $invoices = Invoice::where('receiver_company_id', $companyId)
            ->where('issuer_company_id', $supplierId)
            ->where('status', 'approved')
            ->with('payments.retentions')
            ->get();
            
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
}
