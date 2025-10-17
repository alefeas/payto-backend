<?php

namespace App\Jobs;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckFCEAcceptanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $invoiceId;

    public function __construct(int $invoiceId)
    {
        $this->invoiceId = $invoiceId;
    }

    public function handle(): void
    {
        $invoice = Invoice::find($this->invoiceId);

        if (!$invoice || !$invoice->cae) {
            return;
        }

        try {
            // TODO: Consultar estado de aceptación del comprador en AFIP
            // Por ahora solo registramos que se debe verificar
            Log::info('FCE acceptance check scheduled', [
                'invoice_id' => $invoice->id,
                'cae' => $invoice->cae,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to check FCE acceptance', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
