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
     * Authorize voucher with AFIP and get CAE (supports all voucher types)
     */
    public function authorizeInvoice(Invoice $invoice): array
    {
        try {
            $webService = $this->getWebServiceForType($invoice->type);
            
            // Usar Web Service específico según tipo
            switch ($webService) {
                case 'WSFEX':
                    return $this->authorizeFCEMipyme($invoice);
                case 'WSCTG':
                    return $this->authorizeRemito($invoice);
                default:
                    return $this->authorizeStandardVoucher($invoice);
            }
        } catch (\Exception $e) {
            Log::error('Failed to authorize voucher with AFIP', [
                'invoice_id' => $invoice->id,
                'type' => $invoice->type,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Authorize standard voucher (Facturas, NC, ND, Recibos)
     */
    private function authorizeStandardVoucher(Invoice $invoice): array
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
            throw $e;
        }
    }

    /**
     * Authorize FCE MiPyME (Factura de Crédito Electrónica)
     */
    private function authorizeFCEMipyme(Invoice $invoice): array
    {
        if (!$invoice->payment_due_date) {
            throw new \Exception('FCE MiPyME requiere fecha de vencimiento de pago');
        }

        $cbu = $invoice->issuer_cbu ?? $this->company->cbu;
        if (!$cbu) {
            throw new \Exception('FCE MiPyME requiere CBU del emisor');
        }

        try {
            $client = new AfipWebServiceClient($this->company->afipCertificate, 'wsfex');
            $soapClient = $client->getWSFEXClient();
            $auth = $client->getAuthArray();

            $invoiceType = $this->getAfipInvoiceType($invoice->type);
            $docType = $this->getAfipDocType($invoice->client);

            $fceData = [
                'Auth' => $auth,
                'Cmp' => [
                    'Id' => 1,
                    'Tipo_cbte' => $invoiceType,
                    'Punto_vta' => $invoice->sales_point,
                    'Cbte_nro' => $invoice->voucher_number,
                    'Fecha_cbte' => $invoice->issue_date->format('Ymd'),
                    'Imp_total' => $invoice->total,
                    'Imp_tot_conc' => 0,
                    'Imp_neto' => $invoice->subtotal,
                    'Imp_iva' => $invoice->total_taxes,
                    'Imp_trib' => $invoice->total_perceptions,
                    'Imp_op_ex' => 0,
                    'Fecha_cbte_hasta' => $invoice->issue_date->format('Ymd'),
                    'Fecha_venc_pago' => $invoice->payment_due_date->format('Ymd'),
                    'Mon_id' => 'PES',
                    'Mon_cotiz' => 1,
                    'Concepto' => 1,
                    'Tipo_doc' => $docType,
                    'Nro_doc' => $this->cleanDocumentNumber($invoice->client->document_number),
                    'Cbu' => $cbu,
                    'Iva' => $this->buildIvaArrayFEX($invoice),
                ],
            ];

            $response = $soapClient->FEXAuthorize($fceData);
            $result = $response->FEXAuthorizeResult;

            if (isset($result->Errors) && $result->Errors) {
                throw new \Exception('AFIP rechazó FCE: ' . $result->Errors->Err->Msg);
            }

            // Despachar Job para consultar aceptación
            \App\Jobs\CheckFCEAcceptanceJob::dispatch($invoice->id)->delay(now()->addHours(1));

            return [
                'cae' => $result->Cae,
                'cae_expiration' => Carbon::createFromFormat('Ymd', $result->Fch_venc_Cae)->format('Y-m-d'),
                'afip_result' => 'A',
            ];
        } catch (\Exception $e) {
            throw $e;
        }
    }

    private function buildIvaArrayFEX(Invoice $invoice): ?array
    {
        if ($invoice->total_taxes <= 0) {
            return null;
        }

        return [
            [
                'Id' => 5,
                'Base_imp' => $invoice->subtotal,
                'Importe' => $invoice->total_taxes,
            ],
        ];
    }

    /**
     * Authorize Remito Electrónico
     */
    private function authorizeRemito(Invoice $invoice): array
    {
        // TODO: Implementar Web Service WSCTG
        // Los remitos no tienen CAE tradicional, usan CTG (Código de Trazabilidad)
        throw new \Exception('Remitos electrónicos requieren implementación de WSCTG');
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

        $detRequest = [
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
            'ImpTrib' => $invoice->total_perceptions,
            'MonId' => 'PES',
            'MonCotiz' => 1,
            'Iva' => $this->buildIvaArray($invoice),
            'Tributos' => $this->buildTributosArray($invoice),
        ];

        // Agregar comprobante asociado para NC/ND
        $category = \App\Services\VoucherTypeService::getCategory($invoice->type);
        if (in_array($category, ['credit_note', 'debit_note']) && $invoice->related_invoice_id) {
            $relatedInvoice = Invoice::find($invoice->related_invoice_id);
            if ($relatedInvoice) {
                $detRequest['CbtesAsoc'] = [
                    'CbteAsoc' => [
                        'Tipo' => $this->getAfipInvoiceType($relatedInvoice->type),
                        'PtoVta' => $relatedInvoice->sales_point,
                        'Nro' => $relatedInvoice->voucher_number,
                    ],
                ];
            }
        }

        // Agregar fecha de vencimiento para FCE MiPyME
        if ($category === 'fce_mipyme' && $invoice->payment_due_date) {
            $detRequest['FchVtoPago'] = $invoice->payment_due_date->format('Ymd');
        }

        return [
            'FeCabReq' => [
                'CantReg' => 1,
                'PtoVta' => $invoice->sales_point,
                'CbteTipo' => $invoiceType,
            ],
            'FeDetReq' => [
                'FECAEDetRequest' => $detRequest,
            ],
        ];
    }

    /**
     * Get AFIP invoice type code from VoucherTypeService
     */
    private function getAfipInvoiceType(string $invoiceType): int
    {
        return (int) \App\Services\VoucherTypeService::getAfipCode($invoiceType);
    }

    /**
     * Determine which AFIP Web Service to use based on voucher type
     */
    private function getWebServiceForType(string $type): string
    {
        $category = \App\Services\VoucherTypeService::getCategory($type);
        
        // FCE MiPyME usa Web Service específico
        if ($category === 'fce_mipyme') {
            return 'WSFEX'; // Factura de Crédito Electrónica
        }
        
        // Remitos usan Web Service de Código de Trazabilidad de Granos
        if ($category === 'remito') {
            return 'WSCTG'; // Código de Trazabilidad de Granos
        }
        
        // Liquidaciones usan Web Service específico
        if (in_array($category, ['used_goods', 'used_goods_purchase'])) {
            return 'WSFE'; // Mismo que facturas normales
        }
        
        // Facturas, NC, ND, Recibos usan WSFE (Web Service de Facturación Electrónica)
        return 'WSFE';
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
     * Build Tributos (perceptions) array for AFIP request
     */
    private function buildTributosArray(Invoice $invoice): ?array
    {
        if ($invoice->total_perceptions <= 0) {
            return null;
        }

        $tributos = [];
        foreach ($invoice->perceptions as $perception) {
            $tributos[] = [
                'Id' => $this->getAfipTributoId($perception->type),
                'Desc' => $perception->name,
                'BaseImp' => $perception->base_amount,
                'Alic' => $perception->rate,
                'Importe' => $perception->amount,
            ];
        }

        return ['Tributo' => $tributos];
    }

    /**
     * Get AFIP tributo ID for perception type
     */
    private function getAfipTributoId(string $type): int
    {
        $types = [
            'vat_perception' => 1, // Percepción IVA
            'gross_income_perception' => 2, // Percepción IIBB
            'social_security_perception' => 6, // Percepción SUSS
        ];

        return $types[$type] ?? 99; // 99 = Otros
    }

    /**
     * Consult invoice from AFIP by CAE or invoice number
     */
    public function consultInvoice(string $issuerCuit, int $invoiceType, int $salesPoint, int $voucherNumber): array
    {
        try {
            $soapClient = $this->client->getWSFEClient();
            $auth = $this->client->getAuthArray();

            $response = $soapClient->FECompConsultar([
                'Auth' => $auth,
                'FeCompConsReq' => [
                    'CbteTipo' => $invoiceType,
                    'CbteNro' => $voucherNumber,
                    'PtoVta' => $salesPoint,
                ],
            ]);

            $result = $response->FECompConsultarResult;

            if (isset($result->Errors) && $result->Errors) {
                throw new \Exception('Invoice not found in AFIP');
            }

            $invoice = $result->ResultGet;

            return [
                'found' => true,
                'cae' => $invoice->CodAutorizacion ?? null,
                'cae_expiration' => isset($invoice->FchVto) ? Carbon::createFromFormat('Ymd', $invoice->FchVto)->format('Y-m-d') : null,
                'issue_date' => isset($invoice->CbteFch) ? Carbon::createFromFormat('Ymd', $invoice->CbteFch)->format('Y-m-d') : null,
                'doc_type' => $invoice->DocTipo ?? null,
                'doc_number' => $invoice->DocNro ?? null,
                'subtotal' => $invoice->ImpNeto ?? 0,
                'total_taxes' => $invoice->ImpIVA ?? 0,
                'total_perceptions' => $invoice->ImpTrib ?? 0,
                'total' => $invoice->ImpTotal ?? 0,
                'currency' => $invoice->MonId ?? 'PES',
                'exchange_rate' => $invoice->MonCotiz ?? 1,
                'result' => $invoice->Resultado ?? null,
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to consult invoice from AFIP', [
                'issuer_cuit' => $issuerCuit,
                'invoice_type' => $invoiceType,
                'sales_point' => $salesPoint,
                'voucher_number' => $voucherNumber,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
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
