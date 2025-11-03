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
     * Get balances including unassociated NC/ND
     */
    public function getBalances(string $companyId)
    {
        try {
            $company = Company::findOrFail($companyId);
            
            // NC/ND sin factura asociada (solo emitidas por nuestra empresa)
            // Estas son saldos pendientes que deben manejarse independientemente
            $unassociatedCreditNotes = Invoice::where('issuer_company_id', $companyId)
                ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
                ->whereNull('related_invoice_id')
                ->where('status', '!=', 'cancelled')
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
                        $clientName = $nc->receiver_name ?? 'Sin nombre';
                    }
                    
                    // Para NC emitidas, debemos cobrarlas (son saldo negativo para nosotros)
                    // Calcular si ya fueron cobradas
                    $collectedAmount = $nc->collections()
                        ->where('status', 'confirmed')
                        ->sum('amount');
                    
                    return [
                        'id' => $nc->id,
                        'type' => $nc->type,
                        'number' => $nc->number,
                        'voucher_number' => ($nc->type ?? 'NC') . ' ' . str_pad($nc->sales_point ?? 0, 4, '0', STR_PAD_LEFT) . '-' . str_pad($nc->voucher_number ?? 0, 8, '0', STR_PAD_LEFT),
                        'issue_date' => $nc->issue_date,
                        'due_date' => $nc->due_date,
                        'client_name' => $clientName,
                        'total' => $nc->total,
                        'collected_amount' => $collectedAmount,
                        'pending_amount' => max(0, $nc->total - $collectedAmount),
                        'balance_type' => 'credit', // Saldo negativo - debemos cobrar (redujo nuestra factura original)
                        'description' => 'Nota de Crédito sin factura asociada',
                    ];
                });
            
            $unassociatedDebitNotes = Invoice::where('issuer_company_id', $companyId)
                ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
                ->whereNull('related_invoice_id')
                ->where('status', '!=', 'cancelled')
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
                        $clientName = $nd->receiver_name ?? 'Sin nombre';
                    }
                    
                    // Para ND emitidas, debemos cobrarlas (son saldo positivo adicional)
                    // Calcular si ya fueron cobradas
                    $collectedAmount = $nd->collections()
                        ->where('status', 'confirmed')
                        ->sum('amount');
                    
                    return [
                        'id' => $nd->id,
                        'type' => $nd->type,
                        'number' => $nd->number,
                        'voucher_number' => ($nd->type ?? 'ND') . ' ' . str_pad($nd->sales_point ?? 0, 4, '0', STR_PAD_LEFT) . '-' . str_pad($nd->voucher_number ?? 0, 8, '0', STR_PAD_LEFT),
                        'issue_date' => $nd->issue_date,
                        'due_date' => $nd->due_date,
                        'client_name' => $clientName,
                        'total' => $nd->total,
                        'collected_amount' => $collectedAmount,
                        'pending_amount' => max(0, $nd->total - $collectedAmount),
                        'balance_type' => 'debit', // Saldo positivo adicional - debemos cobrar
                        'description' => 'Nota de Débito sin factura asociada',
                    ];
                });
            
            // Calcular totales
            $totalCredits = $unassociatedCreditNotes->sum('pending_amount');
            $totalDebits = $unassociatedDebitNotes->sum('pending_amount');
            $netBalance = $totalDebits - $totalCredits; // Positivo = debemos cobrar más, Negativo = tenemos crédito pendiente
            
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


