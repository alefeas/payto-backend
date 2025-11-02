<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateInvoiceBalances extends Command
{
    protected $signature = 'invoices:recalculate-balances';
    protected $description = 'Recalcular balance_pending de todas las facturas';

    public function handle()
    {
        $this->info('Recalculando balance_pending...');
        
        $invoices = Invoice::whereNotIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE', 'NDA', 'NDB', 'NDC', 'NDM', 'NDE'])->get();
        
        $bar = $this->output->createProgressBar($invoices->count());
        $bar->start();
        
        foreach ($invoices as $invoice) {
            $totalNC = Invoice::where('related_invoice_id', $invoice->id)
                ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
                ->where('status', '!=', 'cancelled')
                ->sum('total');

            $totalND = Invoice::where('related_invoice_id', $invoice->id)
                ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
                ->where('status', '!=', 'cancelled')
                ->sum('total');

            $paidAmount = $invoice->collections()->where('status', 'confirmed')->sum('amount');
            $paidAmount += DB::table('invoice_payments_tracking')
                ->where('invoice_id', $invoice->id)
                ->whereIn('status', ['confirmed', 'in_process'])
                ->sum('amount');

            $total = $invoice->total ?? 0;
            $adjustedTotal = $total + $totalND - $totalNC;
            $balancePending = $adjustedTotal - $paidAmount;

            $invoice->updateQuietly(['balance_pending' => $balancePending]);
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine();
        $this->info('✓ Completado: ' . $invoices->count() . ' facturas');
        
        return 0;
    }
}
