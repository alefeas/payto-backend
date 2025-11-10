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
            \Log::info('PDF Generation Start', [
                'invoice_id' => $invoice->id,
                'is_manual_load' => $invoice->is_manual_load,
                'manual_supplier' => $invoice->manual_supplier ?? false,
                'has_issuer_company' => $invoice->issuer_company_id ? true : false,
                'has_supplier' => $invoice->supplier_id ? true : false,
            ]);
            
            // Load relationships conditionally
            $with = [
                'client' => function($query) { $query->withTrashed(); },
                'items', 
                'perceptions'
            ];
            
            // Load supplier if exists
            if ($invoice->supplier_id) {
                $with['supplier'] = function($query) { $query->withTrashed(); };
            }
            
            // Only load issuerCompany if it exists and is not a manual received invoice
            if ($invoice->issuer_company_id && !$invoice->manual_supplier && !$invoice->supplier_id) {
                $with['issuerCompany.address'] = function($query) {};
                $with['issuerCompany.bankAccounts'] = function($query) {};
            }
            
            // Only load receiverCompany if it exists
            if ($invoice->receiver_company_id) {
                $with['receiverCompany.address'] = function($query) {};
            }
            
            $invoice->load($with);
            
            \Log::info('Relationships loaded', [
                'has_issuerCompany' => $invoice->issuerCompany ? true : false,
                'has_supplier' => $invoice->supplier ? true : false,
                'has_client' => $invoice->client ? true : false,
                'items_count' => $invoice->items->count(),
            ]);
            
            // Generate barcode and QR if CAE exists (only for non-manual invoices)
            $barcodeBase64 = null;
            $qrBase64 = null;
            if ($invoice->afip_cae && $invoice->issuerCompany) {
                try {
                    $barcodeBase64 = $this->generateAfipBarcode($invoice);
                } catch (\Exception $e) {
                    \Log::warning('Could not generate barcode', ['error' => $e->getMessage()]);
                    $barcodeBase64 = null;
                }
                try {
                    $qrBase64 = $this->generateAfipQR($invoice);
                } catch (\Exception $e) {
                    \Log::warning('Could not generate QR code', ['error' => $e->getMessage()]);
                    $qrBase64 = null;
                }
            }
            
            // For manual received invoices, the client/receiver could be in different fields
            $clientOrReceiver = null;
            if ($invoice->client) {
                $clientOrReceiver = $invoice->client;
            } elseif ($invoice->receiverCompany) {
                $clientOrReceiver = $invoice->receiverCompany;
            } elseif ($invoice->supplier) {
                $clientOrReceiver = $invoice->supplier;
            }
            
            // For manual received invoices, we need to handle the company/client differently
            $company = $invoice->issuerCompany ?? null;
            $client = $clientOrReceiver;
            
            // If this is a manual received invoice, the "issuer" in the PDF should be the supplier
            if ($invoice->is_manual_load && $invoice->supplier) {
                // Create a mock company object from supplier data for PDF display
                $supplierAddress = null;
                if ($invoice->supplier->address) {
                    $supplierAddress = (object) [
                        'street' => $invoice->supplier->address,
                        'street_number' => '',
                        'floor' => null,
                        'apartment' => null,
                        'city' => '',
                        'province' => '',
                        'postal_code' => '',
                        'country' => 'Argentina',
                    ];
                }
                
                $supplierAsCompany = (object) [
                    'name' => $invoice->supplier->business_name ?? trim($invoice->supplier->first_name . ' ' . $invoice->supplier->last_name),
                    'national_id' => $invoice->supplier->document_number,
                    'address' => $supplierAddress,
                    'email' => $invoice->supplier->email ?? null,
                    'phone' => $invoice->supplier->phone ?? null,
                    'tax_condition' => $invoice->supplier->tax_condition ?? null,
                    'bankAccounts' => collect([]),
                ];
                $company = $supplierAsCompany;
                
                // The client is the receiving company (current company)
                $client = $invoice->issuerCompany; // This is actually the receiver for manual invoices
            }
            
            $data = [
                'invoice' => $invoice,
                'company' => $company,
                'address' => $company ? ($company->address ?? null) : null,
                'client' => $client,
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
                'invoice_id' => $invoice->id ?? 'unknown',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
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
        $client = $invoice->client ?? $invoice->receiverCompany ?? $invoice->supplier;
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
        $client = $invoice->client ?? $invoice->receiverCompany ?? $invoice->supplier;
        return $client->document_number ?? $client->national_id ?? '0';
    }
    
    public function generateTxt(Invoice $invoice): string
    {
        $invoice->load([
            'issuerCompany', 
            'client' => function($query) { $query->withTrashed(); },
            'supplier' => function($query) { $query->withTrashed(); },
            'receiverCompany', 
            'items'
        ]);
        
        $lines = [];
        $lines[] = $invoice->number;
        $lines[] = $invoice->type;
        $lines[] = $invoice->issue_date->format('Ymd');
        
        $client = $invoice->client ?? $invoice->receiverCompany ?? $invoice->supplier;
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
            $taxCategory = $item->tax_category ?? 'taxed';
            $taxDisplay = $taxCategory === 'exempt' ? 'Exento' : 
                         ($taxCategory === 'not_taxed' ? 'No Gravado' : 
                         $item->tax_rate . '%');
            
            $itemLine = $item->description . '|' . 
                        $item->quantity . '|' . 
                        number_format($item->unit_price, 2, '.', '') . '|' . 
                        ($item->discount_percentage ?? 0) . '%|' . 
                        $taxDisplay . '|' . 
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
