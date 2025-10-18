<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Picqer\Barcode\BarcodeGeneratorPNG;

class InvoicePdfService
{
    public function generatePdf(Invoice $invoice): string
    {
        try {
            $invoice->load(['issuerCompany.address', 'issuerCompany.bankAccounts', 'client', 'receiverCompany', 'items', 'perceptions']);
            
            // Generate barcode and QR if CAE exists
            $barcodeBase64 = null;
            $qrBase64 = null;
            if ($invoice->afip_cae) {
                $barcodeBase64 = $this->generateAfipBarcode($invoice);
                try {
                    $qrBase64 = $this->generateAfipQR($invoice);
                } catch (\Exception $e) {
                    \Log::warning('Could not generate QR code', ['error' => $e->getMessage()]);
                    $qrBase64 = null;
                }
            }
            
            $data = [
                'invoice' => $invoice,
                'company' => $invoice->issuerCompany,
                'address' => $invoice->issuerCompany->address ?? null,
                'client' => $invoice->client ?? $invoice->receiverCompany,
                'barcodeBase64' => $barcodeBase64,
                'qrBase64' => $qrBase64,
            ];
            
            \Log::info('Generating PDF', ['invoice_id' => $invoice->id]);
            
            $pdf = Pdf::loadView('invoices.pdf', $data);
            
            $filename = "factura-{$invoice->number}.pdf";
            $path = "invoices/pdf/{$invoice->issuer_company_id}/{$filename}";
            $fullPath = storage_path('app/' . $path);
            
            // Ensure directory exists
            $directory = dirname($fullPath);
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }
            
            file_put_contents($fullPath, $pdf->output());
            
            \Log::info('PDF saved', ['path' => $path, 'exists' => file_exists($fullPath)]);
            
            return $path;
        } catch (\Exception $e) {
            \Log::error('Error generating PDF', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    private function generateAfipBarcode(Invoice $invoice): string
    {
        $cuit = str_replace('-', '', $invoice->issuerCompany->national_id);
        $tipoComprobante = str_pad(\App\Services\VoucherTypeService::getAfipCode($invoice->type), 3, '0', STR_PAD_LEFT);
        $puntoVenta = str_pad($invoice->sales_point, 5, '0', STR_PAD_LEFT);
        $cae = $invoice->afip_cae;
        $vencimientoCae = $invoice->afip_cae_due_date->format('Ymd');
        
        $barcodeData = $cuit . $tipoComprobante . $puntoVenta . $cae . $vencimientoCae;
        
        $generator = new BarcodeGeneratorPNG();
        $barcode = $generator->getBarcode($barcodeData, $generator::TYPE_INTERLEAVED_2_5, 2, 50);
        
        return base64_encode($barcode);
    }
    
    private function generateAfipQR(Invoice $invoice): string
    {
        $qrData = json_encode([
            'ver' => 1,
            'fecha' => $invoice->issue_date->format('Y-m-d'),
            'cuit' => (int)str_replace('-', '', $invoice->issuerCompany->national_id),
            'ptoVta' => $invoice->sales_point,
            'tipoCmp' => (int)\App\Services\VoucherTypeService::getAfipCode($invoice->type),
            'nroCmp' => $invoice->voucher_number,
            'importe' => (float)$invoice->total,
            'moneda' => $invoice->currency,
            'ctz' => (float)$invoice->exchange_rate,
            'tipoDocRec' => $this->getAfipDocType($invoice),
            'nroDocRec' => (int)str_replace('-', '', $this->getClientDocument($invoice)),
            'tipoCodAut' => 'E',
            'codAut' => (int)$invoice->afip_cae,
        ], JSON_UNESCAPED_UNICODE);
        
        $qrUrl = 'https://www.afip.gob.ar/fe/qr/?p=' . base64_encode($qrData);
        
        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(200),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );
        $writer = new \BaconQrCode\Writer($renderer);
        $qrCode = $writer->writeString($qrUrl);
        
        return base64_encode($qrCode);
    }
    
    private function getAfipDocType(Invoice $invoice): int
    {
        $client = $invoice->client ?? $invoice->receiverCompany;
        $docType = $client->document_type ?? 'CUIT';
        
        return match($docType) {
            'CUIT' => 80,
            'CUIL' => 86,
            'DNI' => 96,
            'Pasaporte' => 94,
            default => 99,
        };
    }
    
    private function getClientDocument(Invoice $invoice): string
    {
        $client = $invoice->client ?? $invoice->receiverCompany;
        return $client->document_number ?? $client->national_id ?? '0';
    }
    
    public function generateTxt(Invoice $invoice): string
    {
        $invoice->load(['issuerCompany', 'client', 'receiverCompany', 'items']);
        
        $lines = [];
        $lines[] = $invoice->number;
        $lines[] = $invoice->type;
        $lines[] = $invoice->issue_date->format('Ymd');
        
        $client = $invoice->client ?? $invoice->receiverCompany;
        $lines[] = ($client->document_type ?? 'CUIT') . '|' . ($client->document_number ?? $client->national_id ?? '');
        
        $lines[] = number_format($invoice->subtotal, 2, '.', '');
        $lines[] = number_format($invoice->total_taxes, 2, '.', '');
        $lines[] = number_format($invoice->total_perceptions, 2, '.', '');
        $lines[] = number_format($invoice->total, 2, '.', '');
        
        if ($invoice->afip_cae) {
            $lines[] = $invoice->afip_cae;
            $lines[] = $invoice->afip_cae_due_date->format('Ymd');
        }
        
        // Add items detail with discounts
        $lines[] = '';
        $lines[] = 'ITEMS:';
        foreach ($invoice->items as $item) {
            $itemLine = $item->description . '|' . 
                        $item->quantity . '|' . 
                        number_format($item->unit_price, 2, '.', '') . '|' . 
                        ($item->discount_percentage ?? 0) . '%|' . 
                        $item->tax_rate . '%|' . 
                        number_format($item->subtotal, 2, '.', '');
            $lines[] = $itemLine;
        }
        
        $content = implode("\n", $lines);
        $filename = "factura-{$invoice->number}.txt";
        $path = "invoices/txt/{$invoice->issuer_company_id}/{$filename}";
        $fullPath = storage_path('app/' . $path);
        
        // Ensure directory exists
        $directory = dirname($fullPath);
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }
        
        file_put_contents($fullPath, $content);
        
        return $path;
    }
}
