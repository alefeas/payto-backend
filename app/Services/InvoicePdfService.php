<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoicePdfService
{
    public function generatePdf(Invoice $invoice): string
    {
        $invoice->load(['issuerCompany.address', 'client', 'receiverCompany', 'items', 'perceptions']);
        
        $data = [
            'invoice' => $invoice,
            'company' => $invoice->issuerCompany,
            'address' => $invoice->issuerCompany->address,
            'client' => $invoice->client ?? $invoice->receiverCompany,
        ];
        
        $pdf = Pdf::loadView('invoices.pdf', $data);
        
        $filename = "factura-{$invoice->number}.pdf";
        $path = "invoices/pdf/{$invoice->issuer_company_id}/{$filename}";
        
        \Storage::put($path, $pdf->output());
        
        return $path;
    }
    
    public function generateTxt(Invoice $invoice): string
    {
        $invoice->load(['issuerCompany', 'client', 'receiverCompany', 'items']);
        
        $lines = [];
        $lines[] = $invoice->number;
        $lines[] = $invoice->type;
        $lines[] = $invoice->issue_date->format('Ymd');
        
        $client = $invoice->client ?? $invoice->receiverCompany;
        $lines[] = ($client->document_type ?? 'CUIT') . '|' . ($client->document_number ?? $client->national_id);
        
        $lines[] = number_format($invoice->subtotal, 2, '.', '');
        $lines[] = number_format($invoice->total_taxes, 2, '.', '');
        $lines[] = number_format($invoice->total_perceptions, 2, '.', '');
        $lines[] = number_format($invoice->total, 2, '.', '');
        
        if ($invoice->afip_cae) {
            $lines[] = $invoice->afip_cae;
            $lines[] = $invoice->afip_cae_due_date->format('Ymd');
        }
        
        $content = implode("\n", $lines);
        $filename = "factura-{$invoice->number}.txt";
        $path = "invoices/txt/{$invoice->issuer_company_id}/{$filename}";
        
        \Storage::put($path, $content);
        
        return $path;
    }
}
