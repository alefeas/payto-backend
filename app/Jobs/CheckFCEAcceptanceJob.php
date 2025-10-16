<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Afip\AfipWebServiceClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckFCEAcceptanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private int $invoiceId
    ) {}

    public function handle(): void
    {
        $invoice = Invoice::with('issuerCompany.afipCertificate')->find($this->invoiceId);
        
        if (!$invoice || $invoice->acceptance_status !== 'pending_acceptance') {
            return;
        }

        try {
            $client = new AfipWebServiceClient($invoice->issuerCompany->afipCertificate, 'wsfex');
            $soapClient = $client->getWSFEXClient();
            $auth = $client->getAuthArray();

            $response = $soapClient->FEXGetCMP([
                'Auth' => $auth,
                'Cmp' => [
                    'Tipo_cbte' => (int) \App\Services\VoucherTypeService::getAfipCode($invoice->type),
                    'Punto_vta' => $invoice->sales_point,
                    'Cbte_nro' => $invoice->voucher_number,
                ],
            ]);

            $result = $response->FEXGetCMPResult;

            if (isset($result->FEXResultGet)) {
                $status = $result->FEXResultGet->Estado ?? null;
                
                if ($status === 'ACE') {
                    $invoice->update([
                        'acceptance_status' => 'accepted',
                        'acceptance_date' => now(),
                    ]);
                } elseif ($status === 'REC') {
                    $invoice->update([
                        'acceptance_status' => 'rejected',
                        'acceptance_date' => now(),
                    ]);
                } else {
                    // Aún pendiente, reprogramar
                    self::dispatch($this->invoiceId)->delay(now()->addHours(2));
                }
            }
        } catch (\Exception $e) {
            Log::error('FCE acceptance check failed', [
                'invoice_id' => $this->invoiceId,
                'error' => $e->getMessage(),
            ]);
            
            // Reintentar en 2 horas
            self::dispatch($this->invoiceId)->delay(now()->addHours(2));
        }
    }
}
