<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    public function getSummary(Request $request, $companyId)
    {
        $period = $request->query('period', 'month');
        $now = Carbon::now();
        
        if ($period === 'custom') {
            $startOfMonth = Carbon::parse($request->query('start_date'));
            $endOfMonth = Carbon::parse($request->query('end_date'));
        } else {
            switch ($period) {
                case 'quarter':
                    $startOfMonth = $now->copy()->startOfQuarter();
                    $endOfMonth = $now->copy()->endOfQuarter();
                    break;
                case 'year':
                    $startOfMonth = $now->copy()->startOfYear();
                    $endOfMonth = $now->copy()->endOfYear();
                    break;
                default: // month
                    $startOfMonth = $now->copy()->startOfMonth();
                    $endOfMonth = $now->copy()->endOfMonth();
                    break;
            }
        }

        // Issued invoices (sales)
        $issuedInvoices = Invoice::where('issuer_company_id', $companyId)
            ->whereIn('status', ['issued', 'approved', 'paid'])
            ->whereBetween('issue_date', [$startOfMonth, $endOfMonth])
            ->get();

        // Received invoices (purchases)
        $receivedInvoices = Invoice::where('receiver_company_id', $companyId)
            ->whereIn('status', ['issued', 'approved', 'paid'])
            ->whereBetween('issue_date', [$startOfMonth, $endOfMonth])
            ->get();

        // Agrupar por moneda
        $salesByCurrency = [
            'ARS' => ['total' => 0, 'count' => 0],
            'USD' => ['total' => 0, 'count' => 0],
            'EUR' => ['total' => 0, 'count' => 0]
        ];
        $purchasesByCurrency = [
            'ARS' => ['total' => 0, 'count' => 0],
            'USD' => ['total' => 0, 'count' => 0],
            'EUR' => ['total' => 0, 'count' => 0]
        ];

        foreach ($issuedInvoices as $invoice) {
            $currency = $invoice->currency ?? 'ARS';
            if (isset($salesByCurrency[$currency])) {
                $salesByCurrency[$currency]['total'] += $invoice->total;
                $salesByCurrency[$currency]['count']++;
            }
        }

        foreach ($receivedInvoices as $invoice) {
            $currency = $invoice->currency ?? 'ARS';
            if (isset($purchasesByCurrency[$currency])) {
                $purchasesByCurrency[$currency]['total'] += $invoice->total;
                $purchasesByCurrency[$currency]['count']++;
            }
        }

        return response()->json([
            'period' => [
                'start' => $startOfMonth->format('Y-m-d'),
                'end' => $endOfMonth->format('Y-m-d'),
                'month' => $now->format('F Y')
            ],
            'sales' => [
                'total' => $issuedInvoices->sum('total'),
                'count' => $issuedInvoices->count(),
                'average' => $issuedInvoices->count() > 0 ? $issuedInvoices->sum('total') / $issuedInvoices->count() : 0
            ],
            'purchases' => [
                'total' => $receivedInvoices->sum('total'),
                'count' => $receivedInvoices->count(),
                'average' => $receivedInvoices->count() > 0 ? $receivedInvoices->sum('total') / $receivedInvoices->count() : 0
            ],
            'balance' => $issuedInvoices->sum('total') - $receivedInvoices->sum('total'),
            'sales_by_currency' => $salesByCurrency,
            'purchases_by_currency' => $purchasesByCurrency
        ]);
    }

    public function getRevenueTrend(Request $request, $companyId)
    {
        $period = $request->query('period', 'month');
        $now = Carbon::now();
        $months = [];
        
        if ($period === 'custom') {
            $startDate = Carbon::parse($request->query('start_date'));
            $endDate = Carbon::parse($request->query('end_date'));
            $diffInMonths = $startDate->diffInMonths($endDate) + 1;
            
            for ($i = 0; $i < min($diffInMonths, 12); $i++) {
                $date = $startDate->copy()->addMonths($i);
                $startOfMonth = $date->copy()->startOfMonth();
                $endOfMonth = $date->copy()->endOfMonth();
                if ($endOfMonth->gt($endDate)) $endOfMonth = $endDate;

                $salesInvoices = Invoice::where('issuer_company_id', $companyId)
                    ->whereIn('status', ['issued', 'approved', 'paid'])
                    ->whereBetween('issue_date', [$startOfMonth, $endOfMonth])
                    ->get();

                $purchasesInvoices = Invoice::where('receiver_company_id', $companyId)
                    ->whereIn('status', ['issued', 'approved', 'paid'])
                    ->whereBetween('issue_date', [$startOfMonth, $endOfMonth])
                    ->get();

                $months[] = [
                    'month' => $date->format('M Y'),
                    'sales' => $salesInvoices->sum('total'),
                    'purchases' => $purchasesInvoices->sum('total'),
                    'balance' => $salesInvoices->sum('total') - $purchasesInvoices->sum('total'),
                    'sales_ARS' => $salesInvoices->where('currency', 'ARS')->sum('total'),
                    'sales_USD' => $salesInvoices->where('currency', 'USD')->sum('total'),
                    'sales_EUR' => $salesInvoices->where('currency', 'EUR')->sum('total'),
                    'purchases_ARS' => $purchasesInvoices->where('currency', 'ARS')->sum('total'),
                    'purchases_USD' => $purchasesInvoices->where('currency', 'USD')->sum('total'),
                    'purchases_EUR' => $purchasesInvoices->where('currency', 'EUR')->sum('total')
                ];
            }
        } else {
            $monthsToShow = match($period) {
                'year' => 12,
                'quarter' => 3,
                default => 6
            };
            
            for ($i = $monthsToShow - 1; $i >= 0; $i--) {
                $date = $now->copy()->subMonths($i);
                $startOfMonth = $date->copy()->startOfMonth();
                $endOfMonth = $date->copy()->endOfMonth();

                $salesInvoices = Invoice::where('issuer_company_id', $companyId)
                    ->whereIn('status', ['issued', 'approved', 'paid'])
                    ->whereBetween('issue_date', [$startOfMonth, $endOfMonth])
                    ->get();

                $purchasesInvoices = Invoice::where('receiver_company_id', $companyId)
                    ->whereIn('status', ['issued', 'approved', 'paid'])
                    ->whereBetween('issue_date', [$startOfMonth, $endOfMonth])
                    ->get();

                $months[] = [
                    'month' => $date->format('M Y'),
                    'sales' => $salesInvoices->sum('total'),
                    'purchases' => $purchasesInvoices->sum('total'),
                    'balance' => $salesInvoices->sum('total') - $purchasesInvoices->sum('total'),
                    'sales_ARS' => $salesInvoices->where('currency', 'ARS')->sum('total'),
                    'sales_USD' => $salesInvoices->where('currency', 'USD')->sum('total'),
                    'sales_EUR' => $salesInvoices->where('currency', 'EUR')->sum('total'),
                    'purchases_ARS' => $purchasesInvoices->where('currency', 'ARS')->sum('total'),
                    'purchases_USD' => $purchasesInvoices->where('currency', 'USD')->sum('total'),
                    'purchases_EUR' => $purchasesInvoices->where('currency', 'EUR')->sum('total')
                ];
            }
        }

        return response()->json($months);
    }

    public function getTopClients(Request $request, $companyId)
    {
        $period = $request->query('period', 'month');
        $now = Carbon::now();
        
        if ($period === 'custom') {
            $startDate = Carbon::parse($request->query('start_date'));
            $endDate = Carbon::parse($request->query('end_date'));
        } else {
            switch ($period) {
                case 'quarter':
                    $startDate = $now->copy()->startOfQuarter();
                    $endDate = $now->copy()->endOfQuarter();
                    break;
                case 'year':
                    $startDate = $now->copy()->startOfYear();
                    $endDate = $now->copy()->endOfYear();
                    break;
                default: // month
                    $startDate = $now->copy()->startOfMonth();
                    $endDate = $now->copy()->endOfMonth();
                    break;
            }
        }
        
        $topClients = Invoice::where('issuer_company_id', $companyId)
            ->whereIn('status', ['issued', 'approved', 'paid'])
            ->whereBetween('issue_date', [$startDate, $endDate])
            ->whereNotNull('client_id')
            ->select('client_id', DB::raw('SUM(total) as total_amount'), DB::raw('COUNT(*) as invoice_count'))
            ->groupBy('client_id')
            ->orderBy('total_amount', 'desc')
            ->limit(5)
            ->with('client:id,first_name,last_name,business_name')
            ->get()
            ->filter(function ($item) {
                return $item->client !== null;
            })
            ->map(function ($item) {
                return [
                    'client_id' => $item->client_id,
                    'client_name' => $item->client->business_name ?? ($item->client->first_name . ' ' . $item->client->last_name),
                    'total_amount' => $item->total_amount,
                    'invoice_count' => $item->invoice_count
                ];
            })
            ->values();

        return response()->json($topClients);
    }

    public function getPendingInvoices($companyId)
    {
        try {
            // Badge "Cuentas por Cobrar": Facturas emitidas por mí (issuer) que están issued o approved
            // Son facturas que emití a clientes y aún no están pagadas
            $toCollect = Invoice::where('issuer_company_id', $companyId)
                ->whereIn('status', ['issued', 'approved'])
                ->count();

            // Badge "Cuentas por Pagar": Facturas recibidas (receiver) de proveedores externos (supplier_id) aprobadas
            // Son facturas de proveedores que ya aprobé pero aún no pagué
            $toPay = Invoice::where('receiver_company_id', $companyId)
                ->whereNotNull('supplier_id')
                ->where('status', 'approved')
                ->count();

            // Badge "Aprobar Facturas": Facturas recibidas que necesitan aprobación
            // Incluye facturas de empresas conectadas (status=issued) y proveedores externos (status=pending_approval)
            $company = \App\Models\Company::findOrFail($companyId);
            $pendingApprovals = Invoice::where('receiver_company_id', $companyId)
                ->where(function($q) use ($company) {
                    // Facturas de empresas conectadas con status=issued que requieren aprobación
                    $q->where(function($sub) use ($company) {
                        $sub->where('status', 'issued')
                            ->where('receiver_company_id', $company->id)
                            ->where(function($check) {
                                $check->whereNotNull('issuer_company_id')
                                      ->where('issuer_company_id', '!=', DB::raw('receiver_company_id'));
                            });
                    })
                    // O facturas de proveedores externos con status=pending_approval
                    ->orWhere(function($sub) {
                        $sub->where('status', 'pending_approval')
                            ->whereNotNull('supplier_id');
                    });
                })
                ->count();

            return response()->json([
                'to_collect' => $toCollect,
                'to_pay' => $toPay,
                'pending_approvals' => $pendingApprovals
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getPendingInvoices: ' . $e->getMessage());
            return response()->json([
                'to_collect' => 0,
                'to_pay' => 0,
                'pending_approvals' => 0
            ]);
        }
    }
}
