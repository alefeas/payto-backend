<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SendInvoiceDueRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting invoice due reminders job');

        try {
            // Get invoices due in 7 days, 3 days, 1 day, and overdue
            $today = Carbon::today();
            
            // Due in 7 days
            $this->notifyInvoicesDueInDays(7, $today->copy()->addDays(7));
            
            // Due in 3 days
            $this->notifyInvoicesDueInDays(3, $today->copy()->addDays(3));
            
            // Due in 1 day
            $this->notifyInvoicesDueInDays(1, $today->copy()->addDays(1));
            
            // Overdue invoices (due date passed but not fully paid)
            $this->notifyOverdueInvoices($today);

            Log::info('Completed invoice due reminders job');
        } catch (\Exception $e) {
            Log::error('Failed to send invoice due reminders', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function notifyInvoicesDueInDays(int $days, Carbon $dueDate): void
    {
        $invoices = Invoice::where('due_date', $dueDate)
            ->where('balance_pending', '>', 0)
            ->whereNotIn('status', ['cancelled', 'paid'])
            ->get();

        foreach ($invoices as $invoice) {
            $this->notifyInvoiceDue($invoice, $days);
        }

        Log::info("Notified {$invoices->count()} invoices due in {$days} days");
    }

    private function notifyOverdueInvoices(Carbon $today): void
    {
        $invoices = Invoice::where('due_date', '<', $today)
            ->where('balance_pending', '>', 0)
            ->whereNotIn('status', ['cancelled', 'paid'])
            ->get();

        foreach ($invoices as $invoice) {
            $daysOverdue = $today->diffInDays($invoice->due_date);
            $this->notifyInvoiceOverdue($invoice, $daysOverdue);
        }

        Log::info("Notified {$invoices->count()} overdue invoices");
    }

    private function notifyInvoiceDue(Invoice $invoice, int $daysUntilDue): void
    {
        $notificationService = app(NotificationService::class);
        
        $title = "Factura por vencer";
        $message = "La factura {$invoice->number} vence en {$daysUntilDue} días";
        
        if ($daysUntilDue === 1) {
            $title = "Factura vence mañana";
            $message = "La factura {$invoice->number} vence mañana";
        }

        $notificationService->createForAllCompanyMembers(
            $invoice->issuer_company_id,
            'invoice_due_reminder',
            $title,
            $message,
            [
                'entityType' => 'invoice',
                'entityId' => $invoice->id,
                'invoiceNumber' => $invoice->number,
                'dueDate' => $invoice->due_date->format('Y-m-d'),
                'daysUntilDue' => $daysUntilDue,
                'amount' => $invoice->balance_pending,
            ]
        );
    }

    private function notifyInvoiceOverdue(Invoice $invoice, int $daysOverdue): void
    {
        $notificationService = app(NotificationService::class);
        
        $title = "Factura vencida";
        $message = "La factura {$invoice->number} está vencida hace {$daysOverdue} días";

        $notificationService->createForAllCompanyMembers(
            $invoice->issuer_company_id,
            'invoice_overdue',
            $title,
            $message,
            [
                'entityType' => 'invoice',
                'entityId' => $invoice->id,
                'invoiceNumber' => $invoice->number,
                'dueDate' => $invoice->due_date->format('Y-m-d'),
                'daysOverdue' => $daysOverdue,
                'amount' => $invoice->balance_pending,
            ]
        );
    }
}