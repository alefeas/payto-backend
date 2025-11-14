<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Client;
use App\Services\Afip\AfipInvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class InvoiceSyncService
{
    public function __construct(
        private CuitHelperService $cuitHelper
    ) {}

    /**
     * Sync single invoice from AFIP
     */
    public function syncSingleInvoice(Company $company, AfipInvoiceService $afipService, array $validated): array
    {
        $invoiceType = \App\Services\VoucherTypeService::getTypeByCode($validated['invoice_type']) ?? $validated['invoice_type'];
        $invoiceTypeCode = (int) \App\Services\VoucherTypeService::getAfipCode($invoiceType);
        
        try {
            Log::info('🔍 CALLING AFIP consultInvoice (single)', [
                'issuer_cuit' => $company->national_id,
                'invoice_type_code' => $invoiceTypeCode,
                'sales_point' => $validated['sales_point'],
                'voucher_number' => $validated['invoice_number'],
            ]);
            
            $afipData = $afipService->consultInvoice(
                $company->national_id,
                $invoiceTypeCode,
                $validated['sales_point'],
                $validated['invoice_number']
            );
            
            Log::info('📥 AFIP RESPONSE RECEIVED (single)', [
                'found' => $afipData['found'] ?? false,
                'doc_number_raw' => $afipData['doc_number'] ?? 'NULL',
                'cae' => $afipData['cae'] ?? 'NULL',
                'issue_date' => $afipData['issue_date'] ?? 'NULL',
            ]);

            if ($afipData['found']) {
                DB::beginTransaction();
                
                $formattedNumber = sprintf('%04d-%08d', $validated['sales_point'], $validated['invoice_number']);
                
                // Eliminar factura soft-deleted si existe
                Invoice::onlyTrashed()
                    ->where('issuer_company_id', $company->id)
                    ->where('type', $invoiceType)
                    ->where('sales_point', $validated['sales_point'])
                    ->where('voucher_number', $validated['invoice_number'])
                    ->forceDelete();
                
                $exists = Invoice::where('issuer_company_id', $company->id)
                    ->where('type', $invoiceType)
                    ->where('sales_point', $validated['sales_point'])
                    ->where('voucher_number', $validated['invoice_number'])
                    ->exists();
                
                Log::info('Checking if invoice exists', [
                    'company_id' => $company->id,
                    'type' => $invoiceType,
                    'sales_point' => $validated['sales_point'],
                    'voucher_number' => $validated['invoice_number'],
                    'exists' => $exists,
                ]);
                
                if (!$exists) {
                    $receiverData = $this->processReceiverFromAfip($company, $afipData);
                    
                    // AFIP no devuelve el concepto - usar default 'products'
                    $concept = 'products';
                    
                    $invoice = Invoice::create([
                        'number' => $formattedNumber,
                        'type' => $invoiceType,
                        'sales_point' => $validated['sales_point'],
                        'voucher_number' => $validated['invoice_number'],
                        'concept' => $concept,
                        'issuer_company_id' => $company->id,
                        'receiver_company_id' => $receiverData['receiver_company_id'],
                        'client_id' => $receiverData['client_id'],
                        'issue_date' => $afipData['issue_date'],
                        'due_date' => Carbon::parse($afipData['issue_date'])->addDays(30)->format('Y-m-d'),
                        'subtotal' => $afipData['subtotal'],
                        'total_taxes' => $afipData['total_taxes'],
                        'total_perceptions' => $afipData['total_perceptions'],
                        'total' => $afipData['total'],
                        'currency' => $afipData['currency'],
                        'exchange_rate' => $afipData['exchange_rate'],
                        'status' => 'issued',
                        'afip_status' => 'approved',
                        'afip_cae' => $afipData['cae'],
                        'afip_cae_due_date' => $afipData['cae_expiration'],
                        'receiver_name' => $receiverData['receiver_name'],
                        'receiver_document' => $receiverData['receiver_document'],
                        'needs_review' => $receiverData['auto_created_client'] ?? false,
                        'synced_from_afip' => true,
                        'created_by' => auth()->id(),
                    ]);
                    
                    // Crear item genérico ya que AFIP no devuelve detalle
                    $invoice->items()->create([
                        'description' => 'Productos/Servicios',
                        'quantity' => 1,
                        'unit_price' => $afipData['subtotal'],
                        'discount_percentage' => 0,
                        'tax_rate' => $afipData['subtotal'] > 0 ? ($afipData['total_taxes'] / $afipData['subtotal']) * 100 : 0,
                        'tax_amount' => $afipData['total_taxes'],
                        'subtotal' => $afipData['subtotal'],
                        'order_index' => 0,
                    ]);
                }
                
                DB::commit();
                
                $message = ($receiverData['auto_created_client'] ?? false)
                    ? '⚠️ Factura sincronizada. Se creó automáticamente un cliente archivado con CUIT ' . $receiverData['receiver_document'] . '. Debes completar sus datos en la sección de Clientes Archivados antes de poder emitirle facturas.'
                    : 'Factura sincronizada correctamente.';
                
                return response()->json([
                    'success' => true,
                    'imported_count' => 1,
                    'auto_created_clients' => ($receiverData['auto_created_client'] ?? false) ? 1 : 0,
                    'invoices' => [[
                        'sales_point' => $validated['sales_point'],
                        'type' => $validated['invoice_type'],
                        'number' => $validated['invoice_number'],
                        'formatted_number' => $formattedNumber,
                        'data' => $afipData,
                        'saved' => !$exists,
                        'auto_created_client' => $receiverData['auto_created_client'] ?? false,
                    ]],
                    'message' => $message,
                ])->getData(true);
            } else {
                return [
                    'success' => false,
                    'message' => 'Factura no encontrada en AFIP',
                ];
            }
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Single invoice sync failed', [
                'company_id' => $company->id,
                'sales_point' => $validated['sales_point'],
                'invoice_type' => $validated['invoice_type'],
                'invoice_number' => $validated['invoice_number'],
                'error' => $e->getMessage(),
            ]);
            
            throw new \Exception('No se pudo sincronizar la factura desde AFIP. Verificá el punto de venta, tipo y número de comprobante: ' . $e->getMessage());
        }
    }

    /**
     * Sync invoices by date range from AFIP
     */
    public function syncByDateRange(Company $company, AfipInvoiceService $afipService, array $validated): array
    {
        try {
            set_time_limit(600);
            ini_set('max_execution_time', 600);

            $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
            $dateTo = Carbon::parse($validated['date_to'])->endOfDay();

            if ($dateFrom->diffInDays($dateTo) > 90) {
                return [
                    'success' => false,
                    'message' => 'El rango de fechas no puede superar los 90 días',
                ];
            }

            $voucherTypes = ['A', 'B', 'C', 'M', 'NCA', 'NCB', 'NCC', 'NCM', 'NDA', 'NDB', 'NDC', 'NDM'];
            $salesPoints = $this->getAuthorizedSalesPoints($company);

            if (empty($salesPoints)) {
                return [
                    'success' => false,
                    'message' => 'No se encontraron puntos de venta autorizados',
                ];
            }

            $imported = [];
            $summary = [];
            $autoCreatedClientsCount = 0;

            // Fetch all existing invoices in batch to avoid N queries
            $existingInvoices = Invoice::where('issuer_company_id', $company->id)
                ->select('type', 'sales_point', 'voucher_number')
                ->get()
                ->mapWithKeys(function($inv) {
                    return ["{$inv->type}-{$inv->sales_point}-{$inv->voucher_number}" => true];
                })->toArray();

            foreach ($voucherTypes as $type) {
                $invoiceType = \App\Services\VoucherTypeService::getTypeByCode($type) ?? $type;
                $invoiceTypeCode = (int) \App\Services\VoucherTypeService::getAfipCode($invoiceType);

                foreach ($salesPoints as $salesPoint) {
                    try {
                        $lastAfipNumber = $afipService->getLastAuthorizedInvoice($salesPoint, $invoiceTypeCode);

                        if (!$lastAfipNumber || $lastAfipNumber == 0) continue;

                        $summary[] = [
                            'sales_point' => $salesPoint,
                            'type' => $type,
                            'last_number' => $lastAfipNumber,
                        ];

                        for ($num = 1; $num <= $lastAfipNumber; $num++) {
                            try {
                                Log::info('🔍 CALLING AFIP consultInvoice', [
                                    'issuer_cuit' => $company->national_id,
                                    'invoice_type_code' => $invoiceTypeCode,
                                    'sales_point' => $salesPoint,
                                    'voucher_number' => $num,
                                ]);
                                
                                $afipData = $afipService->consultInvoice($company->national_id, $invoiceTypeCode, $salesPoint, $num);
                                
                                Log::info('📥 AFIP RESPONSE RECEIVED', [
                                    'found' => $afipData['found'] ?? false,
                                    'doc_number_raw' => $afipData['doc_number'] ?? 'NULL',
                                    'cae' => $afipData['cae'] ?? 'NULL',
                                    'issue_date' => $afipData['issue_date'] ?? 'NULL',
                                ]);
                                
                                if (!$afipData['found']) continue;

                                $issueDate = Carbon::parse($afipData['issue_date']);

                                // Skip if invoice is outside date range
                                if ($issueDate->lt($dateFrom) || $issueDate->gt($dateTo)) continue;

                                $formattedNumber = sprintf('%04d-%08d', $salesPoint, $num);

                                $key = "{$invoiceType}-{$salesPoint}-{$num}";
                                $exists = isset($existingInvoices[$key]);

                                if (!$exists) {
                                    $receiverData = $this->processReceiverFromAfip($company, $afipData);
                                    
                                    // Verificar si se creó cliente automáticamente
                                    $needsReview = false;
                                    if ($receiverData['client_id']) {
                                        $clientCreated = Client::withTrashed()
                                            ->where('id', $receiverData['client_id'])
                                            ->whereNotNull('deleted_at')
                                            ->exists();
                                        if ($clientCreated) {
                                            $needsReview = true;
                                            $autoCreatedClientsCount++;
                                        }
                                    }
                                    
                                    // AFIP no devuelve el concepto - usar default 'products'
                                    $concept = 'products';
                                    
                                    $invoice = Invoice::create([
                                        'number' => $formattedNumber,
                                        'type' => $invoiceType,
                                        'sales_point' => $salesPoint,
                                        'voucher_number' => $num,
                                        'concept' => $concept,
                                        'issuer_company_id' => $company->id,
                                        'receiver_company_id' => $receiverData['receiver_company_id'],
                                        'client_id' => $receiverData['client_id'],
                                        'issue_date' => $afipData['issue_date'],
                                        'due_date' => Carbon::parse($afipData['issue_date'])->addDays(30),
                                        'subtotal' => $afipData['subtotal'],
                                        'total_taxes' => $afipData['total_taxes'],
                                        'total_perceptions' => $afipData['total_perceptions'],
                                        'total' => $afipData['total'],
                                        'currency' => $afipData['currency'],
                                        'exchange_rate' => $afipData['exchange_rate'],
                                        'status' => 'issued',
                                        'afip_status' => 'approved',
                                        'afip_cae' => $afipData['cae'],
                                        'afip_cae_due_date' => $afipData['cae_expiration'],
                                        'receiver_name' => $receiverData['receiver_name'],
                                        'receiver_document' => $receiverData['receiver_document'],
                                        'needs_review' => $needsReview,
                                        'synced_from_afip' => true,
                                        'created_by' => auth()->id(),
                                    ]);
                                    
                                    // Crear item genérico ya que AFIP no devuelve detalle
                                    $invoice->items()->create([
                                        'description' => 'Productos/Servicios',
                                        'quantity' => 1,
                                        'unit_price' => $afipData['subtotal'],
                                        'discount_percentage' => 0,
                                        'tax_rate' => $afipData['subtotal'] > 0 ? ($afipData['total_taxes'] / $afipData['subtotal']) * 100 : 0,
                                        'tax_amount' => $afipData['total_taxes'],
                                        'subtotal' => $afipData['subtotal'],
                                        'order_index' => 0,
                                    ]);
                                }

                                $imported[] = [
                                    'sales_point' => $salesPoint,
                                    'type' => $type,
                                    'number' => $num,
                                    'formatted_number' => $formattedNumber,
                                    'saved' => !$exists,
                                ];
                            } catch (\Exception $e) {
                                // Skip failed invoice silently
                            }
                        }
                    } catch (\Exception $e) {
                        // Skip failed sales point
                    }
                }
            }

            $message = 'Sincronización completada.';
            if ($autoCreatedClientsCount > 0) {
                $message .= " ⚠️ Se crearon automáticamente {$autoCreatedClientsCount} cliente(s) archivado(s) desde AFIP. Debes completar sus datos en la sección 'Clientes Archivados' antes de poder emitirles facturas. Estos clientes tienen solo el CUIT y necesitan nombre, email y otros datos.";
            }
            
            return [
                'success' => true,
                'imported_count' => count($imported),
                'auto_created_clients' => $autoCreatedClientsCount,
                'summary' => $summary,
                'invoices' => $imported,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'message' => $message,
            ];
        } catch (\Exception $e) {
            Log::error('Sync by date range failed completely', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \Exception('Error al sincronizar: ' . $e->getMessage());
        }
    }

    /**
     * Process receiver data from AFIP response
     */
    private function processReceiverFromAfip(Company $company, array $afipData): array
    {
        $receiverCompanyId = null;
        $clientId = null;
        $receiverName = null;
        $receiverDocument = null;
        $autoCreatedClient = false;
        
        if (isset($afipData['doc_number']) && $afipData['doc_number'] != '0') {
            // AFIP devuelve CUIT sin guiones (20123456789)
            $normalizedCuit = $this->cuitHelper->normalizeCuit($afipData['doc_number']);
            $receiverDocument = $this->cuitHelper->formatCuitWithHyphens($normalizedCuit);
            
            Log::info('Processing CUIT from AFIP', [
                'afip_raw' => $afipData['doc_number'],
                'normalized' => $normalizedCuit,
                'formatted' => $receiverDocument,
            ]);
            
            // 1. Buscar empresa conectada PRIMERO
            $receiverCompany = $this->cuitHelper->findConnectedCompanyByCuit($company->id, $normalizedCuit);
            
            if ($receiverCompany) {
                // Empresa conectada encontrada - usar receiver_company_id
                $receiverCompanyId = $receiverCompany->id;
                $receiverName = $receiverCompany->name;
                // NO usar client_id cuando hay empresa conectada
            } else {
                // 2. No hay empresa conectada - buscar cliente externo (incluir archivados)
                $client = Client::withTrashed()
                    ->where('company_id', $company->id)
                    ->whereRaw('REPLACE(document_number, "-", "") = ?', [$normalizedCuit])
                    ->first();
                
                if (!$client) {
                    $client = Client::create([
                        'company_id' => $company->id,
                        'document_type' => 'CUIT',
                        'document_number' => $receiverDocument,
                        'business_name' => 'Cliente AFIP - ' . $receiverDocument,
                        'tax_condition' => 'monotax',
                        'address' => null,
                        'incomplete_data' => true,
                    ]);
                    $client->delete(); // Archivar inmediatamente
                    $autoCreatedClient = true;
                }
                
                $clientId = $client->id;
                $receiverName = $client->business_name ?? trim($client->first_name . ' ' . $client->last_name);
            }
        } else {
            // AFIP no devolvió CUIT (Consumidor Final sin DNI o error)
            // Crear cliente genérico archivado
            Log::info('No CUIT from AFIP - creating generic client', [
                'doc_number' => $afipData['doc_number'] ?? 'NULL',
            ]);
            
            // Buscar si ya existe un cliente genérico "Sin CUIT" para esta empresa (reutilizar el mismo)
            $client = Client::withTrashed()
                ->where('company_id', $company->id)
                ->where('business_name', 'Cliente AFIP - Sin CUIT')
                ->whereNull('document_number')
                ->first();
            
            if (!$client) {
                $client = Client::create([
                    'company_id' => $company->id,
                    'document_type' => 'DNI', // Usar DNI como placeholder
                    'document_number' => '00000000', // Placeholder que debe ser reemplazado
                    'business_name' => 'Cliente AFIP - Sin CUIT',
                    'tax_condition' => 'final_consumer',
                    'address' => null,
                    'incomplete_data' => true,
                ]);
                $client->delete(); // Archivar inmediatamente
                $autoCreatedClient = true;
            }
            
            $clientId = $client->id;
            $receiverName = 'Cliente AFIP - Sin CUIT';
            $receiverDocument = 'N/A';
        }
        
        return [
            'receiver_company_id' => $receiverCompanyId,
            'client_id' => $clientId,
            'receiver_name' => $receiverName,
            'receiver_document' => $receiverDocument,
            'auto_created_client' => $autoCreatedClient,
        ];
    }

    /**
     * Get authorized sales points from AFIP
     */
    private function getAuthorizedSalesPoints(Company $company): array
    {
        try {
            $client = new \App\Services\Afip\AfipWebServiceClient($company->afipCertificate);
            $salesPoints = $client->getSalesPoints();
            
            // Filtrar solo puntos activos (no bloqueados y sin fecha de baja)
            $activePoints = array_filter($salesPoints, function($sp) {
                return !$sp['blocked'] && empty($sp['drop_date']);
            });
            
            $numbers = array_map(fn($sp) => $sp['point_number'], $activePoints);
            
            Log::info('Retrieved authorized sales points from AFIP', [
                'company_id' => $company->id,
                'points' => $numbers,
            ]);
            
            return !empty($numbers) ? $numbers : range(1, 10);
        } catch (\Exception $e) {
            Log::warning('Could not get sales points from AFIP, using fallback', [
                'error' => $e->getMessage()
            ]);
            return range(1, 10);
        }
    }
}

