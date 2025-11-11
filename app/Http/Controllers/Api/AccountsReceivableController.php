<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AccountsReceivableController extends Controller
{
    /**
     * Get accounts receivable (facturas pendientes de cobro)
     */
    public function getBalances(string $companyId)
    {
        try {
            $company = Company::findOrFail($companyId);
            
            // Obtener todas las facturas emitidas (incluyendo facturas normales, NC y ND)
            $pendingInvoices = Invoice::where('issuer_company_id', $companyId)
                ->where('status', '!=', 'cancelled')
                ->with(['client', 'receiverCompany', 'collections', 'creditNotes', 'debitNotes'])
                ->get()
                ->filter(function($invoice) use ($companyId) {
                    // Calcular monto cobrado
                    $collectedAmount = $invoice->collections
                        ->where('company_id', $companyId)
                        ->where('status', 'confirmed')
                        ->sum('amount');
                    
                    // Calcular NC/ND asociadas (solo para facturas normales)
                    $totalNC = $invoice->creditNotes
                        ->where('status', '!=', 'cancelled')
                        ->sum('total');
                    
                    $totalND = $invoice->debitNotes
                        ->where('status', '!=', 'cancelled')
                        ->sum('total');
                    
                    // Saldo pendiente = Total + ND - NC - Cobrado
                    $pendingAmount = ($invoice->total ?? 0) + $totalND - $totalNC - $collectedAmount;
                    
                    // Solo incluir si tiene saldo pendiente > 0
                    return $pendingAmount > 0.01;
                })
                ->map(function($invoice) use ($companyId) {
                    $clientName = null;
                    if ($invoice->client) {
                        $clientName = $invoice->client->business_name 
                            ?? ($invoice->client->first_name && $invoice->client->last_name 
                                ? trim($invoice->client->first_name . ' ' . $invoice->client->last_name) 
                                : null);
                    }
                    if (!$clientName && $invoice->receiverCompany) {
                        $clientName = $invoice->receiverCompany->business_name ?? $invoice->receiverCompany->name;
                    }
                    if (!$clientName) {
                        $clientName = $invoice->receiver_name ?? 'Sin nombre';
                    }
                    
                    // Calcular montos
                    $collectedAmount = $invoice->collections
                        ->where('company_id', $companyId)
                        ->where('status', 'confirmed')
                        ->sum('amount');
                    
                    $totalNC = $invoice->creditNotes
                        ->where('status', '!=', 'cancelled')
                        ->sum('total');
                    
                    $totalND = $invoice->debitNotes
                        ->where('status', '!=', 'cancelled')
                        ->sum('total');
                    
                    $adjustedTotal = ($invoice->total ?? 0) + $totalND - $totalNC;
                    $pendingAmount = $adjustedTotal - $collectedAmount;
                    
                    return [
                        'id' => $invoice->id,
                        'type' => $invoice->type,
                        'number' => $invoice->number,
                        'voucher_number' => $invoice->type . ' ' . str_pad($invoice->sales_point ?? 0, 4, '0', STR_PAD_LEFT) . '-' . str_pad($invoice->voucher_number ?? 0, 8, '0', STR_PAD_LEFT),
                        'issue_date' => $invoice->issue_date,
                        'due_date' => $invoice->due_date,
                        'client_name' => $clientName,
                        'total' => $invoice->total,
                        'adjusted_total' => $adjustedTotal,
                        'currency' => $invoice->currency ?? 'ARS',
                        'exchange_rate' => $invoice->exchange_rate ?? 1,
                        'collected_amount' => $collectedAmount,
                        'pending_amount' => max(0, $pendingAmount),
                        'has_nc' => $totalNC > 0,
                        'has_nd' => $totalND > 0,
                        'total_nc' => $totalNC,
                        'total_nd' => $totalND,
                    ];
                })
                ->sortByDesc('due_date')
                ->values();
            
            // Calcular totales
            $totalPending = $pendingInvoices->sum('pending_amount');
            $totalOverdue = $pendingInvoices->filter(function($inv) {
                return $inv['due_date'] && Carbon::parse($inv['due_date'])->isPast();
            })->sum('pending_amount');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'invoices' => $pendingInvoices->toArray(),
                    'summary' => [
                        'total_pending' => $totalPending,
                        'total_overdue' => $totalOverdue,
                        'count' => $pendingInvoices->count(),
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


