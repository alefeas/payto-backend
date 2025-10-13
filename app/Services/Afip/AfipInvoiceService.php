<?php

namespace App\Services\Afip;

use App\Models\Company;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AfipInvoiceService
{
    private AfipWebServiceClient $client;
    private Company $company;

    public function __construct(Company $company)
    {
        $this->company = $company;
        
        if (!$company->afipCertificate || !$company->afipCertificate->is_active) {
            throw new \Exception('No active AFIP certificate found for this company');
        }

        $this->client = new AfipWebServiceClient($company->afipCertificate);
    }

    /**
     * Get last authorized invoice number for a sales point and invoice type
     */
    public function getLastAuthorizedInvoice(int $salesPoint, int $invoiceType): int
    {
        try {
            $soapClient = $this->client->getWSFEClient();
            $auth = $this->client->getAuthArray();

            $response = $soapClient->FECompUltimoAutorizado([
                'Auth' => $auth,
                'PtoVta' => $salesPoint,
                'CbteTipo' => $invoiceType,
            ]);

            return (int) $response->FECompUltimoAutorizadoResult->CbteNro;
            
        } catch (\Exception $e) {
            Log::error('Failed to get last authorized invoice from AFIP', [
                'company_id' => $this->company->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Authorize invoice with AFIP and get CAE
     */
    public function authorizeInvoice(Invoice $invoice): array
    {
        try {
            $soapClient = $this->client->getWSFEClient();
            $auth = $this->client->getAuthArray();

            $invoiceData = $this->buildInvoiceData($invoice);

            $response = $soapClient->FECAESolicitar([
                'Auth' => $auth,
                'FeCAEReq' => $invoiceData,
            ]);

            $result = $response->FECAESolicitarResult;

            if (isset($result->Errors) && $result->Errors) {
                $errors = is_array($result->Errors->Err) ? $result->Errors->Err : [$result->Errors->Err];
                $errorMessages = array_map(fn($err) => "{$err->Code}: {$err->Msg}", $errors);
                throw new \Exception('AFIP authorization failed: ' . implode(', ', $errorMessages));
            }

            $detail = $result->FeDetResp->FECAEDetResponse;

            if ($detail->Resultado !== 'A') {
                $obs = isset($detail->Observaciones) ? $detail->Observaciones->Obs : null;
                $obsMsg = $obs ? "{$obs->Code}: {$obs->Msg}" : 'Unknown error';
                throw new \Exception('Invoice not approved by AFIP: ' . $obsMsg);
            }

            return [
                'cae' => $detail->CAE,
                'cae_expiration' => Carbon::createFromFormat('Ymd', $detail->CAEFchVto)->format('Y-m-d'),
                'afip_result' => $detail->Resultado,
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to authorize invoice with AFIP', [
                'invoice_id' => $invoice->id,
                'company_id' => $this->company->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Build invoice data array for AFIP request
     */
    private function buildInvoiceData(Invoice $invoice): array
    {
        $invoiceType = $this->getAfipInvoiceType($invoice->type);
        $docType = $this->getAfipDocType($invoice->client);
        $concept = $invoice->concept === 'services' ? 2 : ($invoice->concept === 'products_and_services' ? 3 : 1);

        $total = $invoice->total;
        $netAmount = $invoice->subtotal;
        $taxAmount = $invoice->total_taxes;
        $exemptAmount = 0;

        return [
            'FeCabReq' => [
                'CantReg' => 1,
                'PtoVta' => $invoice->sales_point,
                'CbteTipo' => $invoiceType,
            ],
            'FeDetReq' => [
                'FECAEDetRequest' => [
                    'Concepto' => $concept,
                    'DocTipo' => $docType,
                    'DocNro' => $this->cleanDocumentNumber($invoice->client->document_number),
                    'CbteDesde' => $invoice->voucher_number,
                    'CbteHasta' => $invoice->voucher_number,
                    'CbteFch' => $invoice->issue_date->format('Ymd'),
                    'ImpTotal' => $total,
                    'ImpTotConc' => 0,
                    'ImpNeto' => $netAmount,
                    'ImpOpEx' => $exemptAmount,
                    'ImpIVA' => $taxAmount,
                    'ImpTrib' => 0,
                    'MonId' => 'PES',
                    'MonCotiz' => 1,
                    'Iva' => $this->buildIvaArray($invoice),
                ],
            ],
        ];
    }

    /**
     * Get AFIP invoice type code
     */
    private function getAfipInvoiceType(string $invoiceType): int
    {
        $types = [
            'A' => 1,
            'B' => 6,
            'C' => 11,
            'E' => 19,
            'M' => 51,
        ];

        return $types[$invoiceType] ?? 6;
    }

    /**
     * Get AFIP document type code
     */
    private function getAfipDocType($client): int
    {
        $docType = $client->document_type ?? 'DNI';
        
        $types = [
            'CUIT' => 80,
            'CUIL' => 86,
            'DNI' => 96,
            'passport' => 94,
            'CDI' => 87,
        ];
        
        return $types[$docType] ?? 96;
    }

    /**
     * Clean document number (remove dashes)
     */
    private function cleanDocumentNumber(string $docNumber): string
    {
        return preg_replace('/[^0-9]/', '', $docNumber);
    }

    /**
     * Build IVA array for AFIP request
     */
    private function buildIvaArray(Invoice $invoice): array
    {
        if ($invoice->total_taxes <= 0) {
            return null;
        }

        return [
            'AlicIva' => [
                [
                    'Id' => 5, // 21%
                    'BaseImp' => $invoice->subtotal,
                    'Importe' => $invoice->total_taxes,
                ],
            ],
        ];
    }

    /**
     * Test connection to AFIP web services
     */
    public function testConnection(): array
    {
        try {
            $soapClient = $this->client->getWSFEClient();

            $response = $soapClient->FEDummy();

            return [
                'success' => true,
                'app_server' => $response->FEDummyResult->AppServer ?? 'Unknown',
                'db_server' => $response->FEDummyResult->DbServer ?? 'Unknown',
                'auth_server' => $response->FEDummyResult->AuthServer ?? 'Unknown',
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
