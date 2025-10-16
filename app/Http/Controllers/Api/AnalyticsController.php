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
    public function getSummary($companyId)
    {
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();

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

    public function getRevenueTrend($companyId)
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
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

        return response()->json($months);
    }

    public function getTopClients($companyId)
    {
        $topClients = Invoice::where('issuer_company_id', $companyId)
            ->whereIn('status', ['issued', 'approved', 'paid'])
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
