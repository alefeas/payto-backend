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

            $lastNumber = (int) $response->FECompUltimoAutorizadoResult->CbteNro;
            
            Log::info('AFIP last authorized invoice', [
                'sales_point' => $salesPoint,
                'invoice_type' => $invoiceType,
                'last_number' => $lastNumber,
            ]);
            
            return $lastNumber;
            
        } catch (\Exception $e) {
            Log::error('Failed to get last AFIP number', [
                'sales_point' => $salesPoint,
                'invoice_type' => $invoiceType,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('No se pudo consultar el último número autorizado en AFIP: ' . $e->getMessage());
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
             
             $invoiceType = $this->getAfipInvoiceType($invoice->type);
             
             $invoiceData = $this->buildInvoiceData($invoice);
             
             Log::info('Sending invoice to AFIP', [
                 'invoice_id' => $invoice->id,
                 'voucher_number' => $invoice->voucher_number,
                 'invoice_type' => $invoiceType,
                 'sales_point' => $invoice->sales_point,
             ]);

             // Calcular Tributos array primero para obtener el total exacto
             $tributosArray = $this->buildTributosArray($invoice);
             $totalPerceptions = 0;
             
             if ($tributosArray && isset($tributosArray['Tributo'])) {
                 foreach ($tributosArray['Tributo'] as $tributo) {
                     $totalPerceptions += $tributo['Importe'];
                 }
             }

             // Actualizar el invoiceData con el total de percepciones calculado
             if (isset($invoiceData['FeDetReq']['FECAEDetRequest']['ImpTrib'])) {
                 $invoiceData['FeDetReq']['FECAEDetRequest']['ImpTrib'] = $totalPerceptions;
             }

             $response = $soapClient->FECAESolicitar([
                 'Auth' => $auth,
                 'FeCAEReq' => $invoiceData,
             ]);

             $result = $response->FECAESolicitarResult;

             if (isset($result->Errors) && $result->Errors) {
                 $errors = is_array($result->Errors->Err) ? $result->Errors->Err : [$result->Errors->Err];
                 $errorMessages = array_map(fn($err) => "[{$err->Code}] {$err->Msg}", $errors);
                 
                 Log::error('AFIP returned errors', [
                     'invoice_id' => $invoice->id,
                     'errors' => $errors,
                 ]);
                 
                 throw new \Exception('AFIP rechazó la factura: ' . implode(' | ', $errorMessages));
             }

             $detail = $result->FeDetResp->FECAEDetResponse;

             if ($detail->Resultado !== 'A') {
                 $observations = [];
                 if (isset($detail->Observaciones)) {
                     $obs = is_array($detail->Observaciones->Obs) ? $detail->Observaciones->Obs : [$detail->Observaciones->Obs];
                     $observations = array_map(fn($o) => "[{$o->Code}] {$o->Msg}", $obs);
                 }
                 
                 Log::error('AFIP did not approve invoice', [
                     'invoice_id' => $invoice->id,
                     'resultado' => $detail->Resultado,
                     'observations' => $observations,
                 ]);
                 
                 $obsMsg = !empty($observations) ? implode(' | ', $observations) : 'Sin detalles';
                 throw new \Exception('AFIP no aprobó la factura: ' . $obsMsg);
             }

             return [
                 'cae' => $detail->CAE,
                 'cae_expiration' => Carbon::createFromFormat('Ymd', $detail->CAEFchVto)->format('Y-m-d'),
                 'afip_result' => $detail->Resultado,
                 'certificate_id' => $this->company->afipCertificate->id,
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
    
            // Calcular Tributos array primero para obtener el total exacto
            $tributosArray = $this->buildTributosArray($invoice);
            $totalPerceptions = 0;
            
            if ($tributosArray && isset($tributosArray['Tributo'])) {
                foreach ($tributosArray['Tributo'] as $tributo) {
                    $totalPerceptions += $tributo['Importe'];
                }
            }
    
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
                    'Imp_trib' => $totalPerceptions, // Usar el total calculado de los tributos redondeados
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
        Log::info('Building invoice data for AFIP', [
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'receiver_company_id' => $invoice->receiver_company_id,
            'has_client_relation' => !is_null($invoice->client),
            'has_receiverCompany_relation' => !is_null($invoice->receiverCompany),
        ]);
        
        // Obtener el receptor: puede ser un cliente externo o una empresa conectada
        $receptor = $invoice->client ?? $invoice->receiverCompany;
        
        if (!$receptor) {
            Log::error('No receptor found for invoice', [
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
                'receiver_company_id' => $invoice->receiver_company_id,
            ]);
            throw new \Exception('La factura debe tener un cliente o empresa receptora');
        }
        
        $condicionIva = $this->getAfipCondicionIva($receptor);
        $invoiceType = $this->getAfipInvoiceType($invoice->type);
        
        // Validar que Consumidor Final no reciba Factura A o M (AFIP error 10243)
        $tiposAyM = [1, 2, 3, 4, 5, 51, 52, 53, 201, 202, 203, 206, 207, 208, 211, 212, 213];
        if ($condicionIva == 5 && in_array($invoiceType, $tiposAyM)) {
            throw new \Exception(
                'No se puede emitir Factura A o M a un Consumidor Final. '
                . 'Debe emitir Factura B o C según corresponda.'
            );
        }
        
        $docType = $this->getAfipDocType($receptor);
        // Obtener concepto desde configuración
        $concept = config('afip_rules.concept_mapping.' . $invoice->concept, 1);

        // Validar fecha según concepto (AFIP error 10016)
        $issueDate = $invoice->issue_date;
        $today = now()->startOfDay();
        $maxDaysBack = $concept == 1 ? 5 : 10; // Productos: 5 días, Servicios: 10 días
        
        // No permitir fechas futuras (buena práctica contable)
        if ($issueDate->gt($today)) {
            throw new \Exception(
                "No se puede emitir un comprobante con fecha futura. "
                . "La fecha de emisión debe ser hoy ({$today->format('d/m/Y')}) o anterior."
            );
        }
        
        // Validar rango hacia atrás según concepto
        if ($issueDate->lt($today->copy()->subDays($maxDaysBack))) {
            $minDate = $today->copy()->subDays($maxDaysBack)->format('d/m/Y');
            $conceptName = $concept == 1 ? 'Productos' : 'Servicios';
            
            throw new \Exception(
                "La fecha del comprobante ({$issueDate->format('d/m/Y')}) es muy antigua. "
                . "Para {$conceptName}, la fecha debe ser posterior al {$minDate}."
            );
        }

        // Factura C: IVA incluido, no se discrimina
        $isTipoC = in_array($invoiceType, [11, 13, 12, 15]); // Factura C, NC C, ND C, Recibo C
        
        // Para empresas (Company), usar national_id; para clientes/suppliers, usar document_number
        $docNro = $this->cleanDocumentNumber(
            $receptor->document_number ?? $receptor->national_id ?? $receptor->cuit ?? '0'
        );
        
        // NOTA: AFIP no requiere domicilio fiscal en la emisión de comprobantes electrónicos
        // El domicilio se obtiene automáticamente del padrón de AFIP usando el CUIT
        
        // Validar CUIT para Facturas A y M (AFIP exige DocTipo=80 y CUIT válido)
        $tiposAyM = [1, 2, 3, 4, 5, 39, 40, 51, 52, 53, 60, 61, 63, 64, 201, 202, 203, 206, 207, 208, 211, 212, 213];
        if (in_array($invoiceType, $tiposAyM)) {
            if (empty($docNro) || strlen($docNro) != 11) {
                $clientName = $receptor->business_name ?? $receptor->name ?? 'el cliente';
                throw new \Exception(
                    "No se puede emitir este comprobante sin un CUIT válido. "
                    . "{$clientName} debe tener un CUIT de 11 dígitos."
                );
            }
            if (!\App\Services\CuitValidatorService::isValid($docNro)) {
                $clientName = $receptor->business_name ?? $receptor->name ?? 'el cliente';
                $formatted = \App\Services\CuitValidatorService::format($docNro);
                $fixed = \App\Services\CuitValidatorService::fix($docNro);
                throw new \Exception(
                    "El CUIT de {$clientName} ({$formatted}) no es válido. "
                    . "El CUIT correcto debería ser: {$fixed}"
                );
            }
        }
        
        // Para consumidores finales, siempre usar DocNro = 0 (AFIP requirement for Factura B/C)
        // CF no se identifica con CUIT en Factura B/C, aunque lo tenga
        if ($condicionIva == 5) {
            $docNro = '0';
            $docType = 99; // Sin identificar
        }
        
        // Validar que DocNro no esté vacío
        if (empty($docNro)) {
            $docNro = '0';
        }
        
        // Calcular IVA array primero para obtener valores exactos
        $ivaArray = null;
        $impNeto = 0;
        $impIVA = 0;
        
        if (!$isTipoC) {
            $ivaArray = $this->buildIvaArray($invoice);
            if ($ivaArray && isset($ivaArray['AlicIva'])) {
                foreach ($ivaArray['AlicIva'] as $alic) {
                    $impNeto += $alic['BaseImp'];
                    $impIVA += $alic['Importe'];
                }
            }
        } else {
            $impNeto = $invoice->subtotal;
        }
        
        // Calcular Tributos array primero para obtener el total exacto
        $tributosArray = $this->buildTributosArray($invoice);
        $totalPerceptions = 0;
        
        if ($tributosArray && isset($tributosArray['Tributo'])) {
            foreach ($tributosArray['Tributo'] as $tributo) {
                $totalPerceptions += $tributo['Importe'];
            }
        }
        
        $monId = $this->mapSystemCurrencyToAfip($invoice->currency ?? 'ARS');
        $monCotiz = ($invoice->currency ?? 'ARS') === 'ARS' ? 1 : ($invoice->exchange_rate ?? 1);
        
        Log::info('Currency data for AFIP', [
            'invoice_id' => $invoice->id,
            'invoice_currency' => $invoice->currency,
            'mapped_MonId' => $monId,
            'MonCotiz' => $monCotiz,
        ]);
        
        $detRequest = [
            'Concepto' => $concept,
            'DocTipo' => $docType,
            'DocNro' => $docNro,
            'CbteDesde' => $invoice->voucher_number,
            'CbteHasta' => $invoice->voucher_number,
            'CbteFch' => $issueDate->format('Ymd'),
            'ImpTotal' => $isTipoC ? $impNeto + $totalPerceptions : round($impNeto + $impIVA + $totalPerceptions, 2),
            'ImpTotConc' => 0,
            'ImpNeto' => round($impNeto, 2),
            'ImpOpEx' => 0,
            'ImpIVA' => round($impIVA, 2),
            'ImpTrib' => $totalPerceptions, // Usar el total calculado de los tributos redondeados
            'MonId' => $monId,
            'MonCotiz' => $monCotiz,
        ];
        
        // Agregar CondicionIVAReceptorId (RG 5616) - Campo obligatorio desde 2024
        $detRequest['CondicionIVAReceptorId'] = $condicionIva;
        
        // Factura C no lleva array de IVA
        // AFIP requiere el array Iva incluso cuando ImpIVA=0 (con Id=3 para IVA 0%)
        if (!$isTipoC && $ivaArray) {
            $detRequest['Iva'] = $ivaArray;
        }
        $detRequest['Tributos'] = $this->buildTributosArray($invoice);

        // NOTA: AFIP NO permite enviar notas de texto libre en el campo Opcionales
        // El campo Opcionales tiene IDs específicos con formatos estrictos (ej: 2101 requiere CBU)
        // Las notas del sistema se guardan en BD pero NO se envían a AFIP
        
        // Agregar comprobante asociado para NC/ND (OBLIGATORIO según AFIP)
        $category = \App\Services\VoucherTypeService::getCategory($invoice->type);
        if (in_array($category, ['credit_note', 'debit_note'])) {
            if ($invoice->related_invoice_id) {
                // Opción 1: Asociar a factura específica (CbteAsoc)
                $relatedInvoice = Invoice::find($invoice->related_invoice_id);
                if ($relatedInvoice) {
                    // Validar que la factura asociada tenga CAE (AFIP lo requiere)
                    if (!$relatedInvoice->afip_cae || $relatedInvoice->afip_status !== 'approved') {
                        throw new \Exception(
                            "No se puede emitir NC/ND electrónica sobre la factura {$relatedInvoice->number} porque no tiene CAE de AFIP. "
                            . "Solo se pueden asociar facturas que fueron autorizadas electrónicamente por AFIP. "
                            . "Para facturas manuales históricas sin CAE, emití la NC/ND sin asociar (se usará período asociado)."
                        );
                    }
                    
                    // Validar monto de NC no supere el balance pendiente
                    if ($category === 'credit_note') {
                        // Recalcular balance actual considerando NC/ND previas Y cobros/pagos
                        // Usar el mismo cálculo que en VoucherValidationService y InvoiceController
                        $totalNC = Invoice::where('related_invoice_id', $relatedInvoice->id)
                            ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
                            ->where('status', '!=', 'cancelled')
                            ->where('afip_status', 'approved')
                            ->sum('total');
                        
                        $totalND = Invoice::where('related_invoice_id', $relatedInvoice->id)
                            ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
                            ->where('status', '!=', 'cancelled')
                            ->where('afip_status', 'approved')
                            ->sum('total');
                        
                        // Determinar si la factura es emitida o recibida
                        $isIssued = (string)$relatedInvoice->issuer_company_id === (string)$invoice->issuer_company_id;
                        
                        // Balance = Total + ND - NC (SIN incluir pagos/cobros)
                        $currentBalance = ($relatedInvoice->total ?? 0) + $totalND - $totalNC;
                        $currentBalance = round(max(0, $currentBalance), 2); // Ensure non-negative and rounded
                        
                        Log::info('Balance calculation for NC validation', [
                            'related_invoice_id' => $relatedInvoice->id,
                            'related_invoice_total' => $relatedInvoice->total,
                            'total_nc_approved' => $totalNC,
                            'total_nd_approved' => $totalND,
                            'calculated_balance' => $currentBalance,
                            'new_nc_amount' => $invoice->total,
                        ]);
                        
                        // Redondear el total de la nota también para comparar correctamente
                        $invoiceTotal = round($invoice->total, 2);
                        
                        if ($invoiceTotal > $currentBalance) {
                            throw new \Exception(
                                "El monto de la Nota de Crédito (\${$invoiceTotal}) no puede superar el saldo pendiente de la factura (\${$currentBalance}). "
                                . "Factura original: \${$relatedInvoice->total} | NC previas: \${$totalNC} | ND previas: \${$totalND}"
                            );
                        }
                    }
                    
                    $detRequest['CbtesAsoc'] = [
                        'CbteAsoc' => [
                            'Tipo' => $this->getAfipInvoiceType($relatedInvoice->type),
                            'PtoVta' => $relatedInvoice->sales_point,
                            'Nro' => $relatedInvoice->voucher_number,
                        ],
                    ];
                    
                    Log::info('Added CbteAsoc for NC/ND', [
                        'voucher_id' => $invoice->id,
                        'related_invoice_id' => $relatedInvoice->id,
                        'related_type' => $relatedInvoice->type,
                        'related_number' => $relatedInvoice->number,
                        'related_cae' => $relatedInvoice->afip_cae,
                    ]);
                }
            } else {
                // Opción 2: Sin factura específica, usar PeriodoAsoc (período del mes)
                $issueDate = $invoice->issue_date;
                $periodFrom = $issueDate->copy()->startOfMonth();
                $periodTo = $issueDate->copy(); // Hasta la fecha de emisión (no puede ser posterior)
                
                $detRequest['PeriodoAsoc'] = [
                    'FchDesde' => $periodFrom->format('Ymd'),
                    'FchHasta' => $periodTo->format('Ymd'),
                ];
                
                Log::info('Added PeriodoAsoc for NC/ND without specific invoice', [
                    'voucher_id' => $invoice->id,
                    'period_from' => $periodFrom->format('Y-m-d'),
                    'period_to' => $periodTo->format('Y-m-d'),
                ]);
            }
        }

        // Agregar fecha de vencimiento para FCE MiPyME
        if ($category === 'fce_mipyme' && $invoice->payment_due_date) {
            $detRequest['FchVtoPago'] = $invoice->payment_due_date->format('Ymd');
        }

        // Agregar fechas de servicio (obligatorias para concepto 2 y 3)
        if ($concept !== 1) {
            if ($invoice->service_date_from && $invoice->service_date_to) {
                $detRequest['FchServDesde'] = $invoice->service_date_from->format('Ymd');
                $detRequest['FchServHasta'] = $invoice->service_date_to->format('Ymd');
                if ($category !== 'fce_mipyme' && $invoice->due_date) {
                    $detRequest['FchVtoPago'] = $invoice->due_date->format('Ymd');
                }
            } else {
                $detRequest['FchServDesde'] = $issueDate->format('Ymd');
                $detRequest['FchServHasta'] = $issueDate->format('Ymd');
                if ($category !== 'fce_mipyme' && $invoice->due_date) {
                    $detRequest['FchVtoPago'] = $invoice->due_date->format('Ymd');
                }
            }
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
        // Si es una empresa (Company), siempre es CUIT
        if ($client instanceof \App\Models\Company) {
            return 80; // CUIT
        }
        
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
     * Get AFIP Condicion IVA code (RG 5616)
     */
    private function getAfipCondicionIva($client): int
    {
        if (!$client) {
            Log::error('Client is null in getAfipCondicionIva');
            return 5; // Default: Consumidor Final
        }
        
        // Si es una empresa (Company), obtener su tax_condition directamente
        if ($client instanceof \App\Models\Company) {
            $taxCondition = $client->tax_condition;
            Log::info('Getting AFIP Condicion IVA from Company', [
                'company_id' => $client->id,
                'tax_condition' => $taxCondition,
            ]);
        } else {
            // Intentar obtener tax_condition de diferentes formas (Client/Supplier)
            $taxCondition = $client->tax_condition ?? $client->taxCondition ?? null;
        }
        
            Log::info('Getting AFIP Condicion IVA', [
                'client_id' => $client->id ?? 'unknown',
                'tax_condition_raw' => $taxCondition,
                'document_type' => $client->document_type ?? 'NOT SET',
            ]);
        
        // Si no tiene tax_condition, inferir del tipo de documento
        if (!$taxCondition) {
            $docType = $client->document_type ?? 'DNI';
            $taxCondition = in_array($docType, ['CUIT', 'CUIL']) ? 'registered_taxpayer' : 'final_consumer';
            
            Log::warning('Client missing tax_condition, inferred from document type', [
                'client_id' => $client->id ?? 'unknown',
                'document_type' => $docType,
                'inferred_condition' => $taxCondition,
            ]);
        }
        
        $conditions = [
            'registered_taxpayer' => 1, // Responsable Inscripto
            'monotax' => 6, // Monotributo
            'exempt' => 4, // Exento
            'final_consumer' => 5, // Consumidor Final
        ];
        
        $code = $conditions[$taxCondition] ?? 5;
        
        Log::info('AFIP Condicion IVA result', [
            'tax_condition' => $taxCondition,
            'afip_code' => $code,
        ]);
        
        return $code;
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
    private function buildIvaArray(Invoice $invoice): ?array
    {
        $afipIvaIds = [
            '0' => 3,
            '2.5' => 9,
            '5' => 8,
            '10.5' => 4,
            '21' => 5,
            '27' => 6,
        ];
        
        $afipRates = [
            3 => 0,
            9 => 2.5,
            8 => 5,
            4 => 10.5,
            5 => 21,
            6 => 27,
        ];
        
        // Agrupar por ID de AFIP (no por alícuota)
        $ivaGroupsByAfipId = [];
        
        foreach ($invoice->items as $item) {
            $taxRate = $item->tax_rate;
            $taxCategory = $item->tax_category ?? 'taxed';
            
            // Exento y No Gravado no van en AlicIva
            if ($taxCategory === 'exempt' || $taxCategory === 'not_taxed') {
                continue;
            }
            
            // IVA 0% SÍ va en AlicIva con Id=3 (AFIP lo requiere)
            
            // Calcular subtotal con descuento
            $itemBase = $item->quantity * $item->unit_price;
            $discount = ($item->discount_percentage ?? 0) / 100;
            $itemSubtotal = $itemBase * (1 - $discount);
            
            // Obtener ID de AFIP para esta alícuota (incluye 0%)
            $rateKey = (string)$taxRate;
            $afipId = $afipIvaIds[$rateKey] ?? 5;
            
            // Agrupar por ID de AFIP (solo base, el impuesto se calcula después)
            if (!isset($ivaGroupsByAfipId[$afipId])) {
                $ivaGroupsByAfipId[$afipId] = 0;
            }
            
            $ivaGroupsByAfipId[$afipId] += $itemSubtotal;
        }
        
        $alicIva = [];
        foreach ($ivaGroupsByAfipId as $afipId => $baseAmount) {
            $baseRounded = round($baseAmount, 2);
            $rate = $afipRates[$afipId];
            $taxAmount = round($baseRounded * $rate / 100, 2);
            
            $alicIva[] = [
                'Id' => $afipId,
                'BaseImp' => $baseRounded,
                'Importe' => $taxAmount,
            ];
        }
        
        // AFIP requiere el array Iva incluso si está vacío o solo tiene IVA 0%
        if (empty($alicIva)) {
            return null;
        }
        
        Log::info('IVA Array built for AFIP', [
            'invoice_id' => $invoice->id,
            'alic_iva' => $alicIva,
        ]);
        
        return ['AlicIva' => $alicIva];
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
        $totalTributos = 0; // Variable para acumular la suma exacta de tributos
        
        foreach ($invoice->perceptions as $perception) {
            $importeRedondeado = round($perception->amount, 2);
            $tributos[] = [
                'Id' => $this->getAfipTributoId($perception->type),
                'Desc' => $perception->name,
                'BaseImp' => round($perception->base_amount, 2),
                'Alic' => round($perception->rate, 2),
                'Importe' => $importeRedondeado,
            ];
            $totalTributos += $importeRedondeado; // Acumular la suma de Importes redondeados
        }

        // Asegurar que ImpTrib coincida exactamente con la suma de los Importes redondeados
        // Esto previene el error AFIP 10029
        if (abs($totalTributos - $invoice->total_perceptions) > 0.01) {
            Log::warning('Ajustando ImpTrib para coincidir con suma de tributos redondeados', [
                'invoice_id' => $invoice->id,
                'total_perceptions_original' => $invoice->total_perceptions,
                'total_tributos_redondeados' => $totalTributos,
                'diferencia' => $totalTributos - $invoice->total_perceptions,
            ]);
            
            // Actualizar el valor en la factura para que coincida
            $invoice->total_perceptions = $totalTributos;
            $invoice->saveQuietly(); // Guardar sin disparar eventos
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
     * Map AFIP currency codes to system format
     */
    private function mapAfipCurrency(string $afipCurrency): string
    {
        $currencyMap = [
            'PES' => 'ARS',  // Pesos argentinos
            'DOL' => 'USD',  // Dólares estadounidenses
            '060' => 'EUR',  // Euros (código numérico AFIP)
            'EUR' => 'EUR',  // Euros (por compatibilidad)
            'Real' => 'BRL', // Reales brasileños
        ];
        
        return $currencyMap[$afipCurrency] ?? 'ARS';
    }

    /**
     * Map system currency codes to AFIP format
     */
    private function mapSystemCurrencyToAfip(?string $systemCurrency): string
    {
        if (empty($systemCurrency)) {
            return 'PES';
        }
        
        $currencyMap = [
            'ARS' => 'PES',  // Pesos argentinos
            'USD' => 'DOL',  // Dólares estadounidenses
            'EUR' => '060',  // Euros (código numérico AFIP)
            'BRL' => 'Real', // Reales brasileños
        ];
        
        return $currencyMap[strtoupper($systemCurrency)] ?? 'PES';
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
                throw new \Exception('Factura no encontrada en AFIP');
            }

            $invoice = $result->ResultGet;
            
            // DEBUG: Log completo de la respuesta de AFIP
            Log::info('AFIP FECompConsultar raw response', [
                'DocTipo' => $invoice->DocTipo ?? 'NULL',
                'DocNro' => $invoice->DocNro ?? 'NULL',
                'DocNro_type' => gettype($invoice->DocNro ?? null),
                'DocNro_length' => isset($invoice->DocNro) ? strlen((string)$invoice->DocNro) : 0,
                'full_invoice_data' => json_encode($invoice, JSON_PRETTY_PRINT),
            ]);

            $issueDate = isset($invoice->CbteFch) ? Carbon::createFromFormat('Ymd', $invoice->CbteFch)->format('Y-m-d') : null;
            $dueDate = $issueDate ? Carbon::parse($issueDate)->addDays(30)->format('Y-m-d') : null;
            
            $afipCurrency = $invoice->MonId ?? 'PES';
            $systemCurrency = $this->mapAfipCurrency($afipCurrency);
            
            // AFIP devuelve la cotización multiplicada por 10000
            // Ejemplo: EUR a 1152.125 pesos = 11521250 en AFIP
            $afipExchangeRate = $invoice->MonCotiz ?? 1;
            $exchangeRate = $afipExchangeRate > 100 ? $afipExchangeRate / 10000 : $afipExchangeRate;
            
            Log::info('Currency mapping from AFIP', [
                'afip_currency' => $afipCurrency,
                'system_currency' => $systemCurrency,
                'afip_exchange_rate' => $afipExchangeRate,
                'exchange_rate' => $exchangeRate,
            ]);

            // AFIP puede devolver CUIT con o sin guiones dependiendo de cómo se cargó originalmente
            $rawDocNumber = $invoice->DocNro ?? null;
            $normalizedDocNumber = $rawDocNumber ? $this->cleanDocumentNumber($rawDocNumber) : null;
            
            Log::info('AFIP returned document number', [
                'raw_doc_number' => $rawDocNumber,
                'normalized' => $normalizedDocNumber,
            ]);

            return [
                'found' => true,
                'cae' => $invoice->CodAutorizacion ?? null,
                'cae_expiration' => isset($invoice->FchVto) ? Carbon::createFromFormat('Ymd', $invoice->FchVto)->format('Y-m-d') : null,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'doc_type' => $invoice->DocTipo ?? null,
                'doc_number' => $normalizedDocNumber,
                'subtotal' => $invoice->ImpNeto ?? 0,
                'total_taxes' => $invoice->ImpIVA ?? 0,
                'total_perceptions' => $invoice->ImpTrib ?? 0,
                'total' => $invoice->ImpTotal ?? 0,
                'currency' => $systemCurrency,
                'exchange_rate' => $exchangeRate,
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
