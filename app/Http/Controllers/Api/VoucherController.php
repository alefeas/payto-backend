<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Afip\AfipInvoiceService;
use App\Services\VoucherTypeService;
use App\Services\VoucherValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VoucherController extends Controller
{
    private VoucherValidationService $validationService;

    public function __construct(VoucherValidationService $validationService)
    {
        $this->validationService = $validationService;
    }

    /**
     * Get available voucher types for company
     */
    public function getAvailableTypes($companyId)
    {
        $company = Company::findOrFail($companyId);
        
        // Filtrar por características de la empresa
        $isMipyme = $company->is_mipyme ?? false;
        $types = VoucherTypeService::getAvailableTypes($isMipyme);
        
        return response()->json([
            'types' => $types,
            'company_is_mipyme' => $isMipyme,
        ]);
    }

    /**
     * Get compatible invoices for manual ISSUED NC/ND (facturas que YO emití)
     */
    public function getCompatibleInvoicesForIssued($companyId, Request $request)
    {
        $voucherType = $request->query('voucher_type');
        
        // Determinar letra según tipo de NC/ND
        $invoiceType = null;
        if (in_array($voucherType, ['003', '002'])) $invoiceType = 'A'; // NCA, NDA
        elseif (in_array($voucherType, ['008', '007'])) $invoiceType = 'B'; // NCB, NDB
        elseif (in_array($voucherType, ['013', '012'])) $invoiceType = 'C'; // NCC, NDC
        elseif (in_array($voucherType, ['053', '052'])) $invoiceType = 'M'; // NCM, NDM
        
        $query = Invoice::where('issuer_company_id', $companyId)
            ->whereNotIn('status', ['cancelled'])
            ->where(function($q) {
                $q->whereNull('balance_pending')
                  ->orWhere('balance_pending', '>', 0);
            });
        
        if ($invoiceType) {
            $query->where('type', $invoiceType);
        }
        
        $invoices = $query->with(['client' => function($query) { $query->withTrashed(); }, 'receiverCompany'])
            ->orderBy('issue_date', 'desc')
            ->get()
            ->map(function($invoice) {
                $clientName = $invoice->receiver_name 
                    ?? $invoice->receiverCompany?->name 
                    ?? $invoice->client?->business_name 
                    ?? ($invoice->client ? trim($invoice->client->first_name . ' ' . $invoice->client->last_name) : null)
                    ?? 'Sin cliente';
                
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->number,
                    'invoice_type' => $invoice->type,
                    'sales_point' => $invoice->sales_point,
                    'issue_date' => $invoice->issue_date->format('Y-m-d'),
                    'client_name' => $clientName,
                    'total_amount' => $invoice->total,
                    'available_balance' => $invoice->balance_pending ?? $invoice->total,
                    'concept' => $invoice->concept ?? 'products',
                    'service_date_from' => $invoice->service_date_from?->format('Y-m-d'),
                    'service_date_to' => $invoice->service_date_to?->format('Y-m-d'),
                    'currency' => $invoice->currency ?? 'ARS',
                    'exchange_rate' => $invoice->exchange_rate ?? 1,
                ];
            });
        
        return response()->json(['invoices' => $invoices]);
    }
    
    /**
     * Get compatible invoices for manual RECEIVED NC/ND (facturas que YO recibí)
     */
    public function getCompatibleInvoicesForReceived($companyId, Request $request)
    {
        $voucherType = $request->query('voucher_type');
        
        // Determinar letra según tipo de NC/ND
        $invoiceType = null;
        if (in_array($voucherType, ['003', '002'])) $invoiceType = 'A'; // NCA, NDA
        elseif (in_array($voucherType, ['008', '007'])) $invoiceType = 'B'; // NCB, NDB
        elseif (in_array($voucherType, ['013', '012'])) $invoiceType = 'C'; // NCC, NDC
        elseif (in_array($voucherType, ['053', '052'])) $invoiceType = 'M'; // NCM, NDM
        
        $query = Invoice::where('receiver_company_id', $companyId)
            ->whereNotIn('status', ['cancelled'])
            ->where(function($q) {
                $q->whereNull('balance_pending')
                  ->orWhere('balance_pending', '>', 0);
            });
        
        if ($invoiceType) {
            $query->where('type', $invoiceType);
        }
        
        $invoices = $query->with(['supplier' => function($query) { $query->withTrashed(); }, 'issuerCompany'])
            ->orderBy('issue_date', 'desc')
            ->get()
            ->map(function($invoice) {
                $supplierName = $invoice->issuer_name
                    ?? $invoice->issuerCompany?->name 
                    ?? $invoice->supplier?->business_name 
                    ?? ($invoice->supplier ? trim($invoice->supplier->first_name . ' ' . $invoice->supplier->last_name) : null)
                    ?? 'Sin proveedor';
                
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->number,
                    'invoice_type' => $invoice->type,
                    'sales_point' => $invoice->sales_point,
                    'issue_date' => $invoice->issue_date->format('Y-m-d'),
                    'client_name' => $supplierName,
                    'total_amount' => $invoice->total,
                    'available_balance' => $invoice->balance_pending ?? $invoice->total,
                    'concept' => $invoice->concept ?? 'products',
                    'service_date_from' => $invoice->service_date_from?->format('Y-m-d'),
                    'service_date_to' => $invoice->service_date_to?->format('Y-m-d'),
                    'currency' => $invoice->currency ?? 'ARS',
                    'exchange_rate' => $invoice->exchange_rate ?? 1,
                ];
            });
        
        return response()->json(['invoices' => $invoices]);
    }

    /**
     * Get available balance for invoice (for NC/ND)
     */
    public function getInvoiceBalance($companyId, $invoiceId)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)
            ->findOrFail($invoiceId);
        
        $creditNotes = $invoice->creditNotes()->sum('total');
        $debitNotes = $invoice->debitNotes()->sum('total');
        
        $availableBalance = $invoice->total - $creditNotes + $debitNotes;
        
        return response()->json([
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'type' => $invoice->type,
                'total' => $invoice->total,
                'balance_pending' => $invoice->balance_pending ?? $invoice->total,
                'available_balance' => $availableBalance,
            ],
            'credit_notes_total' => $creditNotes,
            'debit_notes_total' => $debitNotes,
        ]);
    }

    /**
     * Get invoices compatible with voucher type (for NC/ND)
     */
    public function getCompatibleInvoices($companyId, Request $request)
    {
        $voucherTypeCode = $request->query('voucher_type');
        
        // Convertir código AFIP a clave interna
        $voucherType = VoucherTypeService::getTypeByCode($voucherTypeCode);
        
        if (!$voucherType || !VoucherTypeService::requiresAssociation($voucherType)) {
            return response()->json(['invoices' => []]);
        }
        
        // Obtener tipos compatibles
        $types = VoucherTypeService::getVoucherTypes();
        $compatibleWith = $types[$voucherType]['compatible_with'] ?? [];
        
        if (empty($compatibleWith)) {
            return response()->json(['invoices' => []]);
        }
        
        // Buscar facturas compatibles (excluir solo anuladas totalmente)
        $invoices = Invoice::where('issuer_company_id', $companyId)
            ->whereIn('type', $compatibleWith)
            ->whereNotIn('status', ['cancelled'])
            ->where(function($query) {
                $query->whereNull('balance_pending')
                      ->orWhere('balance_pending', '>', 0);
            })
            ->where(function($query) {
                $query->whereNotNull('client_id')
                      ->orWhereNotNull('receiver_company_id')
                      ->orWhereNotNull('receiver_name');
            })
            ->with([
                'client' => function($query) { $query->withTrashed(); },
                'receiverCompany'
            ])
            ->select('*') // Asegurar que se carguen todos los campos incluyendo concept
            ->orderBy('issue_date', 'desc')
            ->get()
            ->map(function($invoice) use ($companyId) {
                Log::info('Processing invoice for selector', [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->number,
                    'invoice_concept_raw' => $invoice->concept,
                    'invoice_concept_is_null' => is_null($invoice->concept),
                    'invoice_concept_empty' => empty($invoice->concept),
                    'has_client' => !is_null($invoice->client),
                    'client_id' => $invoice->client_id,
                    'has_receiverCompany' => !is_null($invoice->receiverCompany),
                    'receiver_company_id' => $invoice->receiver_company_id,
                    'receiver_name' => $invoice->receiver_name,
                    'receiver_cuit' => $invoice->receiver_cuit
                ]);
                // Determinar nombre según dirección
                $clientName = 'Sin cliente';
                if ($invoice->issuer_company_id == $companyId) {
                    // Factura emitida: mostrar receptor
                    if ($invoice->client) {
                        $clientName = $invoice->client->business_name ?? "{$invoice->client->first_name} {$invoice->client->last_name}";
                    } elseif ($invoice->receiverCompany) {
                        $clientName = $invoice->receiverCompany->name;
                    } elseif ($invoice->receiver_name) {
                        $clientName = $invoice->receiver_name;
                    }
                } else {
                    // Factura recibida: mostrar emisor
                    if ($invoice->supplier) {
                        $clientName = $invoice->supplier->business_name ?? "{$invoice->supplier->first_name} {$invoice->supplier->last_name}";
                    } elseif ($invoice->issuerCompany) {
                        $clientName = $invoice->issuerCompany->name;
                    } elseif ($invoice->issuer_name) {
                        $clientName = $invoice->issuer_name;
                    }
                }
                
                $result = [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->number,
                    'invoice_type' => $invoice->type,
                    'sales_point' => $invoice->sales_point,
                    'issue_date' => $invoice->issue_date->format('Y-m-d'),
                    'client_name' => $clientName,
                    'total_amount' => $invoice->total,
                    'available_balance' => $invoice->balance_pending ?? $invoice->total,
                    'concept' => $invoice->concept ?? 'products',
                    'service_date_from' => $invoice->service_date_from?->format('Y-m-d'),
                    'service_date_to' => $invoice->service_date_to?->format('Y-m-d'),
                    'currency' => $invoice->currency ?? 'ARS',
                    'exchange_rate' => $invoice->exchange_rate ?? 1,
                    'client_id' => $invoice->client_id,
                    'receiver_company_id' => $invoice->receiver_company_id,
                    'receiver_name' => $invoice->receiver_name,
                    'receiver_cuit' => $invoice->receiver_cuit,
                    'receiver_address' => $invoice->receiver_address,
                    'receiver_tax_condition' => $invoice->receiver_tax_condition,
                ];
                
                Log::info('Invoice mapped for selector', array_merge($result, [
                    'original_concept' => $invoice->concept,
                    'concept_source' => $invoice->concept ? 'database' : 'fallback_products',
                    'fallback_applied' => !$invoice->concept
                ]));
                
                return $result;
            });
        
        return response()->json(['invoices' => $invoices]);
    }

    /**
     * Create voucher (NC, ND, Receipt, etc.)
     */
    public function store(Request $request, $companyId)
    {
        $company = Company::with('afipCertificate')->findOrFail($companyId);
        
        // TODO: Descomentar en producción
        // Validar que tenga certificado AFIP activo
        // if (!$company->afipCertificate || !$company->afipCertificate->is_active) {
        //     return response()->json([
        //         'message' => 'No se puede emitir comprobantes sin certificado AFIP',
        //         'error' => 'Debes subir y activar tu certificado AFIP desde Configuración → AFIP/ARCA para poder emitir comprobantes electrónicos.',
        //     ], 403);
        // }
        
        $voucherTypeCode = $request->input('voucher_type');
        
        // Convertir código AFIP a clave interna
        $voucherType = VoucherTypeService::getTypeByCode($voucherTypeCode);
        
        if (!$voucherType) {
            return response()->json([
                'message' => 'Tipo de comprobante inválido',
            ], 422);
        }
        
        // Log request data for debugging
        Log::info('Voucher creation request', [
            'company_id' => $companyId,
            'voucher_type' => $voucherType,
            'voucher_type_code' => $voucherTypeCode,
            'has_related_invoice' => isset($request->all()['related_invoice_id']),
            'sales_point' => $request->input('sales_point'),
            'data' => $request->all()
        ]);
        
        // Validar
        $validation = $this->validationService->validateVoucher($request->all(), $voucherType, $companyId);
        if (!$validation['valid']) {
            Log::warning('Voucher validation failed', [
                'company_id' => $companyId,
                'voucher_type' => $voucherType,
                'errors' => $validation['errors'],
                'data' => $request->all()
            ]);
            
            return response()->json([
                'message' => 'Error de validación',
                'error' => 'Los datos del comprobante no son válidos. Revise los campos requeridos.',
                'errors' => $validation['errors']
            ], 422);
        }
        
        DB::beginTransaction();
        try {
            // Crear comprobante
            $voucher = $this->createVoucher($request->all(), $voucherType, $company);
            
            // Si es NC/ND, actualizar factura original
            $category = VoucherTypeService::getCategory($voucherType);
            if (in_array($category, ['credit_note', 'debit_note'])) {
                $this->updateRelatedInvoice($voucher, $category);
            }
            
            // Solicitar CAE a AFIP
            if ($company->afipCertificate && $company->afipCertificate->is_active) {
                try {
                    // Cargar relaciones necesarias para AFIP
                    $voucher->load(['perceptions', 'client' => function($query) { $query->withTrashed(); }, 'receiverCompany', 'supplier' => function($query) { $query->withTrashed(); }, 'issuerCompany']);
                    $afipService = new AfipInvoiceService($company);
                    $afipResult = $afipService->authorizeInvoice($voucher);
                    
                    $voucher->update([
                        'afip_cae' => $afipResult['cae'],
                        'afip_cae_due_date' => $afipResult['cae_expiration'],
                        'afip_status' => 'approved',
                        'status' => 'issued',
                        'afip_sent_at' => now(),
                    ]);
                    
                    // Generate PDF and TXT
                    $pdfService = new \App\Services\InvoicePdfService();
                    $pdfPath = $pdfService->generatePdf($voucher);
                    $txtPath = $pdfService->generateTxt($voucher);
                    
                    $voucher->update([
                        'pdf_url' => $pdfPath,
                        'afip_txt_url' => $txtPath,
                    ]);
                } catch (\Exception $e) {
                    DB::rollBack();
                    
                    Log::error('AFIP authorization failed for voucher', [
                        'company_id' => $companyId,
                        'voucher_type' => $voucherType,
                        'voucher_type_code' => $voucherTypeCode,
                        'error' => $e->getMessage(),
                    ]);
                    
                    return response()->json([
                        'message' => 'AFIP rechazó el comprobante',
                        'error' => $e->getMessage(),
                    ], 422);
                }
            }
            
            DB::commit();
            
            // Enviar notificación si el receptor es una empresa conectada
            if ($voucher->receiver_company_id) {
                \App\Helpers\NotificationHelper::notifyInvoiceReceived($voucher, auth()->id());
            }
            
            return response()->json([
                'message' => 'Comprobante creado exitosamente',
                'voucher' => $voucher->load(['client', 'items', 'relatedInvoice', 'perceptions']),
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Voucher creation failed', [
                'company_id' => $companyId,
                'voucher_type' => $voucherType,
                'voucher_type_code' => $voucherTypeCode,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Error al crear comprobante',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function createVoucher(array $data, string $type, Company $company): Invoice
    {
        // Consultar último número autorizado en AFIP
        $afipService = new AfipInvoiceService($company);
        $invoiceTypeCode = (int) VoucherTypeService::getAfipCode($type);
        
        try {
            $lastAfipNumber = $afipService->getLastAuthorizedInvoice(
                $data['sales_point'],
                $invoiceTypeCode
            );
            $voucherNumber = $lastAfipNumber + 1;
            
            Log::info('Using AFIP last number for voucher', [
                'sales_point' => $data['sales_point'],
                'type' => $type,
                'last_afip' => $lastAfipNumber,
                'next_number' => $voucherNumber
            ]);
        } catch (\Exception $e) {
            Log::error('AFIP query failed for voucher', [
                'company_id' => $company->id,
                'sales_point' => $data['sales_point'],
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('No se pudo consultar AFIP: ' . $e->getMessage());
        }
        
        // Calcular totales (considerando descuentos igual que en otros lugares)
        $subtotal = 0;
        $totalTaxes = 0;
        foreach ($data['items'] as $item) {
            $itemBase = $item['quantity'] * $item['unit_price'];
            
            // Considerar descuentos si existen
            $discountPercentage = isset($item['discount_percentage']) ? (float)$item['discount_percentage'] : 0;
            $discount = $itemBase * ($discountPercentage / 100);
            $itemSubtotal = $itemBase - $discount;
            
            // Exento (-1) y No Gravado (-2) tienen IVA = 0
            $taxRate = isset($item['tax_rate']) ? (float)$item['tax_rate'] : 0;
            $itemTax = ($taxRate > 0) ? $itemSubtotal * ($taxRate / 100) : 0;
            
            $subtotal += $itemSubtotal;
            $totalTaxes += $itemTax;
        }
        
        // Calcular percepciones
        $totalPerceptions = 0;
        if (isset($data['perceptions'])) {
            foreach ($data['perceptions'] as $perception) {
                // Determinar base según base_type (similar al frontend y otros servicios)
                $baseType = $perception['base_type'] ?? 'net';
                $baseAmount = match($baseType) {
                    'vat' => $totalTaxes,
                    'total' => $subtotal + $totalTaxes,
                    'net' => $subtotal,
                    default => $subtotal,
                };
                
                // Compatibilidad con formatos antiguos (type vs rate)
                $rate = isset($perception['rate']) ? (float)$perception['rate'] : 
                       (isset($perception['percentage']) ? (float)$perception['percentage'] : 0);
                
                $totalPerceptions += $baseAmount * ($rate / 100);
            }
        }
        
        $total = $subtotal + $totalTaxes + $totalPerceptions;
        
        // Si tiene factura relacionada, SIEMPRE heredar receptor y moneda
        if (isset($data['related_invoice_id'])) {
            $relatedInvoice = Invoice::with(['client', 'receiverCompany'])->find($data['related_invoice_id']);
            if (!$relatedInvoice) {
                throw new \Exception('Factura relacionada no encontrada');
            }
            
            Log::info('Inheriting receptor from related invoice', [
                'related_invoice_id' => $relatedInvoice->id,
                'related_invoice_number' => $relatedInvoice->number,
                'client_id' => $relatedInvoice->client_id,
                'receiver_company_id' => $relatedInvoice->receiver_company_id,
                'receiver_name' => $relatedInvoice->receiver_name,
                'supplier_id' => $relatedInvoice->supplier_id,
                'issuer_company_id' => $relatedInvoice->issuer_company_id,
            ]);
            
            // Heredar TODOS los campos de receptor (emitidas y recibidas)
            $clientId = $relatedInvoice->client_id;
            $receiverCompanyId = $relatedInvoice->receiver_company_id;
            $receiverName = $relatedInvoice->receiver_name;
            $receiverCuit = $relatedInvoice->receiver_cuit;
            $receiverAddress = $relatedInvoice->receiver_address;
            $receiverTaxCondition = $relatedInvoice->receiver_tax_condition;
            
            // Para facturas recibidas, heredar supplier e issuer
            $supplierId = $relatedInvoice->supplier_id;
            $issuerCompanyId = $relatedInvoice->issuer_company_id;
            $issuerName = $relatedInvoice->issuer_name;
            $issuerCuit = $relatedInvoice->issuer_cuit;
            $issuerAddress = $relatedInvoice->issuer_address;
            $issuerTaxCondition = $relatedInvoice->issuer_tax_condition;
            
            $currency = $relatedInvoice->currency;
            $exchangeRate = $relatedInvoice->exchange_rate;
            
            // Validar que tenga al menos un receptor O emisor
            if (!$clientId && !$receiverCompanyId && !$receiverName && !$supplierId && !$issuerCompanyId && !$issuerName) {
                throw new \Exception('La factura original no tiene datos del receptor/emisor. No se puede emitir NC/ND sobre facturas sin cliente/proveedor.');
            }
        } else {
            // Sin factura relacionada, usar datos del request
            $clientId = $data['client_id'] ?? null;
            $receiverCompanyId = $data['receiver_company_id'] ?? null;
            $receiverName = null;
            $receiverCuit = null;
            $receiverAddress = null;
            $receiverTaxCondition = null;
            $supplierId = null;
            $issuerCompanyId = null;
            $issuerName = null;
            $issuerCuit = null;
            $issuerAddress = null;
            $issuerTaxCondition = null;
            $currency = $data['currency'] ?? 'ARS';
            $exchangeRate = $data['exchange_rate'] ?? 1;
        }
        
        // Crear comprobante
        Log::info('Creating voucher with receptor data', [
            'client_id' => $clientId,
            'receiver_company_id' => $receiverCompanyId,
            'receiver_name' => $receiverName,
            'supplier_id' => $supplierId ?? null,
            'issuer_company_id_inherited' => $issuerCompanyId ?? null,
        ]);
        
        // Heredar estado de la factura relacionada y validar saldo
        $initialStatus = 'pending_approval';
        $initialAfipStatus = 'pending';
        if (isset($data['related_invoice_id'])) {
            $relatedInvoice = Invoice::find($data['related_invoice_id']);
            if ($relatedInvoice) {
                $initialStatus = $relatedInvoice->status;
                $initialAfipStatus = $relatedInvoice->afip_status;
                
                // Validar que NC no deje saldo negativo
                $isNC = in_array($type, ['NCA', 'NCB', 'NCC', 'NCM', 'NCE']);
                if ($isNC) {
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
                    $availableBalance = ($relatedInvoice->total ?? 0) + $totalND - $totalNC;
                    
                    if ($total > $availableBalance) {
                        throw new \Exception("El monto de la NC no puede superar el saldo disponible. Saldo disponible: " . number_format($availableBalance, 2) . " " . ($relatedInvoice->currency ?? 'ARS') . ". Monto ingresado: " . number_format($total, 2) . " " . $currency);
                    }
                }
            }
        }
        
        // Heredar due_date de factura relacionada si existe
        $dueDate = $data['due_date'] ?? now()->addDays(30);
        if (isset($data['related_invoice_id']) && $relatedInvoice) {
            $dueDate = $relatedInvoice->due_date;
        }
        
        $voucher = Invoice::create([
            'number' => sprintf('%04d-%08d', $data['sales_point'], $voucherNumber),
            'type' => $type,
            'sales_point' => $data['sales_point'],
            'voucher_number' => $voucherNumber,
            'concept' => $data['concept'] ?? 'products',
            'issuer_company_id' => $company->id,
            'client_id' => $clientId,
            'receiver_company_id' => $receiverCompanyId,
            'receiver_name' => $receiverName,
            'receiver_cuit' => $receiverCuit,
            'receiver_address' => $receiverAddress,
            'receiver_tax_condition' => $receiverTaxCondition,
            'supplier_id' => $supplierId ?? null,
            'issuer_name' => $issuerName ?? null,
            'issuer_cuit' => $issuerCuit ?? null,
            'issuer_address' => $issuerAddress ?? null,
            'issuer_tax_condition' => $issuerTaxCondition ?? null,
            'related_invoice_id' => $data['related_invoice_id'] ?? null,
            'issue_date' => $data['issue_date'],
            'due_date' => $dueDate,
            'service_date_from' => !empty($data['service_date_from']) ? $data['service_date_from'] : null,
            'service_date_to' => !empty($data['service_date_to']) ? $data['service_date_to'] : null,
            'subtotal' => $subtotal,
            'total_taxes' => $totalTaxes,
            'total_perceptions' => $totalPerceptions,
            'total' => $total,
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'notes' => $data['notes'] ?? null,
            'status' => $initialStatus,
            'afip_status' => $initialAfipStatus,
            'approvals_required' => 0,
            'approvals_received' => 0,
            'created_by' => auth()->id(),
            'balance_pending' => $total,
        ]);
        
        // Crear items
        foreach ($data['items'] as $index => $item) {
            $itemSubtotal = $item['quantity'] * $item['unit_price'];
            $itemTax = $itemSubtotal * ($item['tax_rate'] / 100);
            
            $voucher->items()->create([
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'tax_rate' => $item['tax_rate'],
                'tax_amount' => $itemTax,
                'subtotal' => $itemSubtotal,
                'order_index' => $index,
            ]);
        }
        
        // Crear percepciones
        if (isset($data['perceptions'])) {
            foreach ($data['perceptions'] as $perception) {
                $baseAmount = $perception['type'] === 'vat_perception' 
                    ? $totalTaxes 
                    : ($subtotal + $totalTaxes);
                $amount = $baseAmount * ($perception['rate'] / 100);

                $voucher->perceptions()->create([
                    'type' => $perception['type'],
                    'name' => $perception['name'],
                    'rate' => $perception['rate'],
                    'base_amount' => $baseAmount,
                    'amount' => $amount,
                ]);
            }
        }
        
        return $voucher;
    }

    private function updateRelatedInvoice(Invoice $voucher, string $category): void
    {
        $relatedInvoice = Invoice::find($voucher->related_invoice_id);
        if (!$relatedInvoice) {
            return;
        }
        
        // RECALCULAR SALDO: Total + ND - NC (SIN incluir pagos/cobros)
        $totalNC = Invoice::where('related_invoice_id', $relatedInvoice->id)
            ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
            ->where('status', '!=', 'cancelled')
            ->sum('total');
        
        $totalND = Invoice::where('related_invoice_id', $relatedInvoice->id)
            ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
            ->where('status', '!=', 'cancelled')
            ->sum('total');
        
        // Saldo = Total + ND - NC (solo ND/NC afectan el balance)
        $relatedInvoice->balance_pending = $relatedInvoice->total + $totalND - $totalNC;
        
        // Redondear para evitar problemas de precisión
        $relatedInvoice->balance_pending = round($relatedInvoice->balance_pending, 2);
        
        Log::info('Recalculated invoice balance', [
            'invoice_id' => $relatedInvoice->id,
            'invoice_number' => $relatedInvoice->number,
            'total' => $relatedInvoice->total,
            'total_nc' => $totalNC,
            'total_nd' => $totalND,
            'balance_pending' => $relatedInvoice->balance_pending,
        ]);
        
        // Actualizar estado según el saldo
        if ($relatedInvoice->balance_pending < 0.01) { // Solo anular si saldo < $0.01
            $relatedInvoice->status = 'cancelled';
            $relatedInvoice->balance_pending = 0;
            
            // Agregar nota automática
            $noteType = $category === 'credit_note' ? 'Nota de Crédito' : 'Nota de Débito';
            $existingNotes = $relatedInvoice->notes ?? '';
            $newNote = "\n[" . now()->format('Y-m-d H:i') . "] Anulada automáticamente por {$noteType} {$voucher->number} por el total.";
            $relatedInvoice->notes = $existingNotes . $newNote;
            
            Log::info('Invoice automatically cancelled by credit note', [
                'invoice_id' => $relatedInvoice->id,
                'invoice_number' => $relatedInvoice->number,
                'voucher_id' => $voucher->id,
                'voucher_number' => $voucher->number,
                'voucher_type' => $voucher->type,
            ]);
        } else if ($relatedInvoice->balance_pending < $relatedInvoice->total) {
            // Anulación parcial - NO cambiar el estado, mantener el original
            $noteType = $category === 'credit_note' ? 'Nota de Crédito' : 'Nota de Débito';
            $existingNotes = $relatedInvoice->notes ?? '';
            $newNote = "\n[" . now()->format('Y-m-d H:i') . "] Ajustada por {$noteType} {$voucher->number}. Nuevo saldo: $" . number_format($relatedInvoice->balance_pending, 2) . ".";
            $relatedInvoice->notes = $existingNotes . $newNote;
            
            Log::info('Invoice partially cancelled', [
                'invoice_id' => $relatedInvoice->id,
                'invoice_number' => $relatedInvoice->number,
                'voucher_id' => $voucher->id,
                'voucher_number' => $voucher->number,
                'new_balance' => $relatedInvoice->balance_pending,
            ]);
        }
        
        $relatedInvoice->save();
    }
}
