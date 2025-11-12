<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Invoice;
use App\Models\Company;
use App\Services\NotificationService;
use Carbon\Carbon;

class CheckOverdueInvoices extends Command
{
    protected $signature = 'invoices:check-overdue';
    protected $description = 'Check for overdue and upcoming due invoices and send notifications';

    private NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }

    public function handle()
    {
        $this->info('Checking for overdue and upcoming due invoices...');
        
        $today = Carbon::today();
        $threeDaysFromNow = Carbon::today()->addDays(3);
        
        $companies = Company::where('is_active', true)->get();
        
        foreach ($companies as $company) {
            $this->checkCompanyInvoices($company, $today, $threeDaysFromNow);
        }
        
        $this->info('Done!');
    }

    private function checkCompanyInvoices(Company $company, Carbon $today, Carbon $threeDaysFromNow)
    {
        // Facturas vencidas (emitidas por la empresa - por cobrar)
        $overdueReceivable = Invoice::where('issuer_company_id', $company->id)
            ->whereNull('supplier_id')
            ->whereNotIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE']) // Excluir NC
            ->whereNotIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE']) // Excluir ND asociadas y no asociadas
            ->whereDate('due_date', '<', $today)
            ->where('status', '!=', 'cancelled')
            ->where('status', '!=', 'rejected')
            ->where('afip_status', '!=', 'cancelled')
            ->get()
            ->filter(function($inv) use ($company) {
                $companyStatus = $inv->company_statuses[$company->id] ?? null;
                return $companyStatus !== 'collected' && $companyStatus !== 'paid';
            });

        // Facturas vencidas (recibidas por la empresa - por pagar)
        $overduePayable = Invoice::where('receiver_company_id', $company->id)
            ->where('issuer_company_id', '!=', $company->id)
            ->whereNotIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
            ->whereNotIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
            ->whereDate('due_date', '<', $today)
            ->where('status', '!=', 'cancelled')
            ->where('status', '!=', 'rejected')
            ->where('afip_status', '!=', 'cancelled')
            ->get()
            ->filter(function($inv) use ($company) {
                $companyStatus = $inv->company_statuses[$company->id] ?? null;
                return $companyStatus !== 'paid' && $companyStatus !== 'collected';
            });

        // Facturas próximas a vencer (por cobrar)
        $upcomingReceivable = Invoice::where('issuer_company_id', $company->id)
            ->whereNull('supplier_id')
            ->whereNotIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
            ->whereNotIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
            ->whereDate('due_date', '>=', $today)
            ->whereDate('due_date', '<=', $threeDaysFromNow)
            ->where('status', '!=', 'cancelled')
            ->where('status', '!=', 'rejected')
            ->where('afip_status', '!=', 'cancelled')
            ->get()
            ->filter(function($inv) use ($company) {
                $companyStatus = $inv->company_statuses[$company->id] ?? null;
                return $companyStatus !== 'collected' && $companyStatus !== 'paid';
            });

        // Facturas próximas a vencer (por pagar)
        $upcomingPayable = Invoice::where('receiver_company_id', $company->id)
            ->where('issuer_company_id', '!=', $company->id)
            ->whereNotIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
            ->whereNotIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
            ->whereDate('due_date', '>=', $today)
            ->whereDate('due_date', '<=', $threeDaysFromNow)
            ->where('status', '!=', 'cancelled')
            ->where('status', '!=', 'rejected')
            ->where('afip_status', '!=', 'cancelled')
            ->get()
            ->filter(function($inv) use ($company) {
                $companyStatus = $inv->company_statuses[$company->id] ?? null;
                return $companyStatus !== 'paid' && $companyStatus !== 'collected';
            });

        // Enviar notificaciones
        if ($overdueReceivable->count() > 0) {
            $this->notificationService->createForCompanyMembers(
                $company->id,
                'invoices_overdue_receivable',
                'Facturas vencidas por cobrar',
                "Tenés {$overdueReceivable->count()} factura" . ($overdueReceivable->count() > 1 ? 's' : '') . " vencida" . ($overdueReceivable->count() > 1 ? 's' : '') . " pendiente" . ($overdueReceivable->count() > 1 ? 's' : '') . " de cobro",
                ['count' => $overdueReceivable->count()]
            );
        }

        if ($overduePayable->count() > 0) {
            $this->notificationService->createForCompanyMembers(
                $company->id,
                'invoices_overdue_payable',
                'Facturas vencidas por pagar',
                "Tenés {$overduePayable->count()} factura" . ($overduePayable->count() > 1 ? 's' : '') . " vencida" . ($overduePayable->count() > 1 ? 's' : '') . " pendiente" . ($overduePayable->count() > 1 ? 's' : '') . " de pago",
                ['count' => $overduePayable->count()]
            );
        }

        if ($upcomingReceivable->count() > 0) {
            $this->notificationService->createForCompanyMembers(
                $company->id,
                'invoices_upcoming_receivable',
                'Facturas próximas a vencer',
                "{$upcomingReceivable->count()} factura" . ($upcomingReceivable->count() > 1 ? 's' : '') . " por cobrar vence" . ($upcomingReceivable->count() > 1 ? 'n' : '') . " en los próximos 3 días",
                ['count' => $upcomingReceivable->count()]
            );
        }

        if ($upcomingPayable->count() > 0) {
            $this->notificationService->createForCompanyMembers(
                $company->id,
                'invoices_upcoming_payable',
                'Facturas próximas a vencer',
                "{$upcomingPayable->count()} factura" . ($upcomingPayable->count() > 1 ? 's' : '') . " por pagar vence" . ($upcomingPayable->count() > 1 ? 'n' : '') . " en los próximos 3 días",
                ['count' => $upcomingPayable->count()]
            );
        }
    }
}
