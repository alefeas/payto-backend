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
            
            // Obtener NC/ND sin asociar (related_invoice_id es NULL)
            $unassociatedCreditNotes = Invoice::where('issuer_company_id', $companyId)
                ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
                ->whereNull('related_invoice_id')
                ->where('status', '!=', 'cancelled')
                ->where('afip_status', 'approved')
                ->with(['client', 'receiverCompany'])
                ->get()
                ->map(function($nc) {
                    $clientName = null;
                    if ($nc->client) {
                        $clientName = $nc->client->business_name 
                            ?? ($nc->client->first_name && $nc->client->last_name 
                                ? trim($nc->client->first_name . ' ' . $nc->client->last_name) 
                                : null);
                    }
                    if (!$clientName && $nc->receiverCompany) {
                        $clientName = $nc->receiverCompany->business_name ?? $nc->receiverCompany->name;
                    }
                    if (!$clientName) {
                        $clientName = 'Sin nombre';
                    }
                    
                    return [
                        'id' => $nc->id,
                        'type' => $nc->type,
                        'voucher_number' => $nc->type . ' ' . str_pad($nc->sales_point ?? 0, 4, '0', STR_PAD_LEFT) . '-' . str_pad($nc->voucher_number ?? 0, 8, '0', STR_PAD_LEFT),
                        'issue_date' => $nc->issue_date->format('Y-m-d'),
                        'due_date' => $nc->due_date?->format('Y-m-d'),
                        'client_name' => $clientName,
                        'total' => (float) $nc->total,
                        'pending_amount' => (float) $nc->total,
                        'balance_type' => 'credit',
                        'description' => 'A favor del cliente',
                        'currency' => $nc->currency ?? 'ARS',
                    ];
                });
            
            $unassociatedDebitNotes = Invoice::where('issuer_company_id', $companyId)
                ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
                ->whereNull('related_invoice_id')
                ->where('status', '!=', 'cancelled')
                ->where('afip_status', 'approved')
                ->with(['client', 'receiverCompany'])
                ->get()
                ->map(function($nd) {
                    $clientName = null;
                    if ($nd->client) {
                        $clientName = $nd->client->business_name 
                            ?? ($nd->client->first_name && $nd->client->last_name 
                                ? trim($nd->client->first_name . ' ' . $nd->client->last_name) 
                                : null);
                    }
                    if (!$clientName && $nd->receiverCompany) {
                        $clientName = $nd->receiverCompany->business_name ?? $nd->receiverCompany->name;
                    }
                    if (!$clientName) {
                        $clientName = 'Sin nombre';
                    }
                    
                    return [
                        'id' => $nd->id,
                        'type' => $nd->type,
                        'voucher_number' => $nd->type . ' ' . str_pad($nd->sales_point ?? 0, 4, '0', STR_PAD_LEFT) . '-' . str_pad($nd->voucher_number ?? 0, 8, '0', STR_PAD_LEFT),
                        'issue_date' => $nd->issue_date->format('Y-m-d'),
                        'due_date' => $nd->due_date?->format('Y-m-d'),
                        'client_name' => $clientName,
                        'total' => (float) $nd->total,
                        'pending_amount' => (float) $nd->total,
                        'balance_type' => 'debit',
                        'description' => 'A cobrar del cliente',
                        'currency' => $nd->currency ?? 'ARS',
                    ];
                });
            
            // Calcular resumen
            $totalCredits = $unassociatedCreditNotes->sum('total');
            $totalDebits = $unassociatedDebitNotes->sum('total');
            $netBalance = $totalDebits - $totalCredits;
            
            return response()->json([
                'data' => [
                    'credit_notes' => $unassociatedCreditNotes->values()->toArray(),
                    'debit_notes' => $unassociatedDebitNotes->values()->toArray(),
                    'summary' => [
                        'total_credits' => $totalCredits,
                        'total_debits' => $totalDebits,
                        'net_balance' => abs($netBalance),
                        'net_balance_type' => $netBalance >= 0 ? 'debit' : 'credit',
                    ],
                ]
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


