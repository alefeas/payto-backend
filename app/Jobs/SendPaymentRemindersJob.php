<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPaymentRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting payment reminders job');

        try {
            // Get invoices with payment due dates approaching
            $this->notifyPaymentDueInvoices();
            
            Log::info('Completed payment reminders job');
        } catch (\Exception $e) {
            Log::error('Failed to send payment reminders', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function notifyPaymentDueInvoices(): void
    {
        // Get invoices that need payment reminders (issued invoices awaiting payment)
        $invoices = Invoice::where('issuer_company_id', '>', 0)
            ->where('balance_pending', '>', 0)
            ->whereNotIn('status', ['cancelled', 'paid'])
            ->where('payment_due_date', '>=', now())
            ->get();

        foreach ($invoices as $invoice) {
            $this->notifyPaymentExpected($invoice);
        }

        Log::info("Notified {$invoices->count()} invoices expecting payment");
    }

    private function notifyPaymentExpected(Invoice $invoice): void
    {
        $notificationService = app(NotificationService::class);
        
        $title = "Pago pendiente";
        $message = "Se espera pago por factura {$invoice->number} por {$invoice->balance_pending}";

        $notificationService->createForAllCompanyMembers(
            $invoice->issuer_company_id,
            'payment_expected',
            $title,
            $message,
            [
                'entityType' => 'invoice',
                'entityId' => $invoice->id,
                'invoiceNumber' => $invoice->number,
                'paymentDueDate' => $invoice->payment_due_date?->format('Y-m-d'),
                'amount' => $invoice->balance_pending,
            ]
        );
    }
}