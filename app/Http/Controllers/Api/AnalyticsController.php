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
            'balance' => $issuedInvoices->sum('total') - $receivedInvoices->sum('total')
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

                $sales = Invoice::where('issuer_company_id', $companyId)
                    ->whereIn('status', ['issued', 'approved', 'paid'])
                    ->whereBetween('issue_date', [$startOfMonth, $endOfMonth])
                    ->sum('total');

                $purchases = Invoice::where('receiver_company_id', $companyId)
                    ->whereIn('status', ['issued', 'approved', 'paid'])
                    ->whereBetween('issue_date', [$startOfMonth, $endOfMonth])
                    ->sum('total');

                $months[] = [
                    'month' => $date->format('M Y'),
                    'sales' => $sales,
                    'purchases' => $purchases,
                    'balance' => $sales - $purchases
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

                $sales = Invoice::where('issuer_company_id', $companyId)
                    ->whereIn('status', ['issued', 'approved', 'paid'])
                    ->whereBetween('issue_date', [$startOfMonth, $endOfMonth])
                    ->sum('total');

                $purchases = Invoice::where('receiver_company_id', $companyId)
                    ->whereIn('status', ['issued', 'approved', 'paid'])
                    ->whereBetween('issue_date', [$startOfMonth, $endOfMonth])
                    ->sum('total');

                $months[] = [
                    'month' => $date->format('M Y'),
                    'sales' => $sales,
                    'purchases' => $purchases,
                    'balance' => $sales - $purchases
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
            ->map(function ($item) {
                return [
                    'client_id' => $item->client_id,
                    'client_name' => $item->client->business_name ?? ($item->client->first_name . ' ' . $item->client->last_name),
                    'total_amount' => $item->total_amount,
                    'invoice_count' => $item->invoice_count
                ];
            });

        return response()->json($topClients);
    }

    public function getPendingInvoices($companyId)
    {
        // Facturas a cobrar (emitidas por mi, no pagadas)
        $toCollect = Invoice::where('issuer_company_id', $companyId)
            ->whereIn('status', ['issued', 'approved'])
            ->count();

        // Facturas a pagar (recibidas, aprobadas pero no pagadas)
        $toPay = Invoice::where('receiver_company_id', $companyId)
            ->where('status', 'approved')
            ->count();

        // Facturas pendientes de aprobar
        $pendingApprovals = Invoice::where('receiver_company_id', $companyId)
            ->where('status', 'pending_approval')
            ->count();

        return response()->json([
            'to_collect' => $toCollect,
            'to_pay' => $toPay,
            'pending_approvals' => $pendingApprovals
        ]);
    }
}
