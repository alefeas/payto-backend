<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Afip\AfipInvoiceService;
use App\Services\InvoiceService;
use App\Services\InvoiceSyncService;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\StoreManualIssuedInvoiceRequest;
use App\Http\Requests\StoreManualReceivedInvoiceRequest;
use App\Http\Requests\UpdateSyncedInvoiceRequest;
use App\Http\Requests\SyncFromAfipRequest;
use App\Http\Requests\ValidateWithAfipRequest;
use App\Http\Requests\GetNextNumberRequest;
use App\Http\Requests\GetAssociableInvoicesRequest;
use App\Http\Requests\DownloadBulkRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class InvoiceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private InvoiceService $invoiceService,
        private InvoiceSyncService $invoiceSyncService
    ) {}
    
    // normalizeCuit, formatCuitWithHyphens, findConnectedCompanyByCuit moved to CuitHelperService
    public function index(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        
        $this->authorize('viewAny', [Invoice::class, $company]);

        $filters = [
            'status' => $request->query('status'),
            'search' => $request->query('search'),
            'type' => $request->query('type'),
            'client' => $request->query('client'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'exclude_associated_notes' => $request->query('exclude_associated_notes', false),
        ];

        $invoices = $this->invoiceService->getInvoices($companyId, $filters);

        return response()->json($invoices);
    }

    public function store(StoreInvoiceRequest $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        
        $this->authorize('create', [Invoice::class, $company]);

        $hasAfipCertificate = $company->afipCertificate && $company->afipCertificate->is_active;
        
        if (!$hasAfipCertificate) {
            return response()->json([
                'message' => 'Certificado AFIP requerido',
                'error' => 'Debes configurar tu certificado AFIP para emitir facturas electrónicas. Ve a Configuración → Verificar Perfil Fiscal.',
            ], 403);
        }

        $validated = $request->validated();

        try {
            DB::beginTransaction();

            // Create client if client_data is provided
            if (!isset($validated['client_id']) && isset($validated['client_data'])) {
                $client = \App\Models\Client::create([
                    'company_id' => $companyId,
                    ...$validated['client_data']
                ]);
                $validated['client_id'] = $client->id;
            }

            // Consultar último número autorizado en AFIP (SIEMPRE)
            $afipService = new AfipInvoiceService($company);
            // Convertir código a tipo interno si es necesario
            $invoiceType = \App\Services\VoucherTypeService::getTypeByCode($validated['invoice_type']) ?? $validated['invoice_type'];
            $invoiceTypeCode = (int) \App\Services\VoucherTypeService::getAfipCode($invoiceType);
            
            try {
                $lastAfipNumber = $afipService->getLastAuthorizedInvoice(
                    $validated['sales_point'],
                    $invoiceTypeCode
                );
                // AFIP es la fuente de verdad, usar su número + 1
                $voucherNumber = $lastAfipNumber + 1;
                
                Log::info('Using AFIP last number', [
                    'sales_point' => $validated['sales_point'],
                    'type' => $invoiceType,
                    'last_afip' => $lastAfipNumber,
                    'next_number' => $voucherNumber
                ]);
            } catch (\Exception $e) {
                // NO usar fallback - AFIP debe responder siempre
                DB::rollBack();
                
                Log::error('AFIP query failed - Cannot create invoice', [
                    'company_id' => $companyId,
                    'sales_point' => $validated['sales_point'],
                    'error' => $e->getMessage()
                ]);
                
                return response()->json([
                    'message' => 'No se pudo consultar AFIP',
                    'error' => 'Error al obtener el próximo número de comprobante desde AFIP: ' . $e->getMessage(),
                    'suggestion' => 'Verifica tu conexión y certificado AFIP. Si el problema persiste, contacta soporte.'
                ], 422);
            }

            $subtotal = 0;
            $totalTaxes = 0;

            foreach ($validated['items'] as $item) {
                $discount = $item['discount_percentage'] ?? 0;
                $taxRate = $item['tax_rate'] ?? 0;
                $itemBase = $item['quantity'] * $item['unit_price'];
                $itemDiscount = $itemBase * ($discount / 100);
                $itemSubtotal = $itemBase - $itemDiscount;
                // Exento (-1) y No Gravado (-2) tienen IVA = 0
                $itemTax = ($taxRate > 0) ? $itemSubtotal * ($taxRate / 100) : 0;
                
                $subtotal += $itemSubtotal;
                $totalTaxes += $itemTax;
            }

            // Auto-apply perceptions if company is perception agent
            $perceptionsToApply = $validated['perceptions'] ?? [];
            if ($company->is_perception_agent && $company->auto_perceptions) {
                foreach ($company->auto_perceptions as $autoPerception) {
                    $alreadyAdded = collect($perceptionsToApply)->contains('type', $autoPerception['type']);
                    if (!$alreadyAdded) {
                        $perceptionsToApply[] = $autoPerception;
                    }
                }
            }

            // Calculate perceptions
            $totalPerceptions = 0;
            if (!empty($perceptionsToApply)) {
                foreach ($perceptionsToApply as $perception) {
                    $baseAmount = $this->calculatePerceptionBase(
                        $perception['type'],
                        $perception['base_type'] ?? null,
                        $subtotal,
                        $totalTaxes
                    );
                    $totalPerceptions += $baseAmount * ($perception['rate'] / 100);
                }
            }

            $total = $subtotal + $totalTaxes + $totalPerceptions;
            
            // Validar que el total no sea 0
            if ($total <= 0) {
                DB::rollBack();
                return response()->json([
                    'message' => 'No se puede emitir una factura con monto $0',
                    'error' => 'El total de la factura debe ser mayor a $0. Si aplicaste 100% de descuento, considera emitir una Nota de Crédito en lugar de una factura.',
                ], 422);
            }

            // Determinar receptor
            $receiverCompanyId = $validated['receiver_company_id'] ?? null;
            $clientId = $validated['client_id'] ?? null;
            
            // Obtener datos del receptor para guardar en la factura
            $receiverName = null;
            $receiverDocument = null;
            
            if ($receiverCompanyId) {
                // Si seleccionó empresa conectada, crear cliente automáticamente
                $receiverCompany = Company::findOrFail($receiverCompanyId);
                $receiverName = $receiverCompany->name;
                $receiverDocument = $receiverCompany->national_id;
                
                // Buscar o crear cliente con estos datos
                $client = \App\Models\Client::firstOrCreate(
                    [
                        'company_id' => $companyId,
                        'document_number' => $receiverDocument,
                    ],
                    [
                        'document_type' => 'CUIT',
                        'business_name' => $receiverName,
                        'tax_condition' => $receiverCompany->tax_condition ?? 'registered_taxpayer',
                        'email' => $receiverCompany->email ?? null,
                        'phone' => $receiverCompany->phone ?? null,
                        'address' => $receiverCompany->address ? ($receiverCompany->address->street . ' ' . $receiverCompany->address->street_number) : null,
                        'city' => $receiverCompany->address->city ?? null,
                        'province' => $receiverCompany->address->province ?? null,
                        'postal_code' => $receiverCompany->address->postal_code ?? null,
                    ]
                );
                $clientId = $client->id;
            } elseif ($clientId) {
                $client = \App\Models\Client::findOrFail($clientId);
                $receiverName = $client->business_name 
                    ?? trim($client->first_name . ' ' . $client->last_name)
                    ?: null;
                $receiverDocument = $client->document_number;
            }
            
            // Crear factura (si hay empresa conectada, usar receiver_company_id)
            $invoice = Invoice::create([
                'number' => sprintf('%04d-%08d', $validated['sales_point'], $voucherNumber),
                'type' => $invoiceType,
                'sales_point' => $validated['sales_point'],
                'voucher_number' => $voucherNumber,
                'concept' => $validated['concept'],
                'service_date_from' => $validated['service_date_from'] ?? null,
                'service_date_to' => $validated['service_date_to'] ?? null,
                'issuer_company_id' => $companyId,
                'receiver_company_id' => $receiverCompanyId,
                'client_id' => $clientId,
                'receiver_name' => $receiverName,
                'receiver_document' => $receiverDocument,
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'] ?? now()->addDays(30),
                'subtotal' => $subtotal,
                'total_taxes' => $totalTaxes,
                'total_perceptions' => $totalPerceptions,
                'total' => $total,
                'currency' => $validated['currency'] ?? 'ARS',
                'exchange_rate' => $validated['exchange_rate'] ?? 1,
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending_approval',
                'afip_status' => 'pending',
                'approvals_required' => 0,
                'approvals_received' => 0,
                'created_by' => auth()->id(),
            ]);

            foreach ($validated['items'] as $index => $item) {
                $discount = $item['discount_percentage'] ?? 0;
                $taxRate = $item['tax_rate'] ?? 0;
                $itemBase = $item['quantity'] * $item['unit_price'];
                $itemDiscount = $itemBase * ($discount / 100);
                $itemSubtotal = $itemBase - $itemDiscount;
                // Exento (-1) y No Gravado (-2) tienen IVA = 0
                $itemTax = ($taxRate > 0) ? $itemSubtotal * ($taxRate / 100) : 0;

                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_percentage' => $discount,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $itemTax,
                    'subtotal' => $itemSubtotal,
                    'order_index' => $index,
                ]);
            }

            // Create perceptions
            if (!empty($perceptionsToApply)) {
                foreach ($perceptionsToApply as $perception) {
                    $baseAmount = $this->calculatePerceptionBase(
                        $perception['type'],
                        $perception['base_type'] ?? null,
                        $subtotal,
                        $totalTaxes
                    );
                    $amount = $baseAmount * ($perception['rate'] / 100);

                    $invoice->perceptions()->create([
                        'type' => $perception['type'],
                        'name' => $perception['name'],
                        'rate' => $perception['rate'] ?? 0,
                        'base_type' => $perception['base_type'] ?? $this->getDefaultBaseType($perception['type']),
                        'jurisdiction' => $perception['jurisdiction'] ?? null,
                        'base_amount' => $baseAmount,
                        'amount' => $amount,
                    ]);
                }
            }

            // Si es NC/ND con factura relacionada, validar que no esté pagada/cobrada
            if (!empty($validated['related_invoice_id'])) {
                $relatedInvoice = Invoice::find($validated['related_invoice_id']);
                if ($relatedInvoice) {
                    $companyStatuses = $relatedInvoice->company_statuses ?? [];
                    foreach ($companyStatuses as $status) {
                        if (in_array($status, ['paid', 'collected'])) {
                            DB::rollBack();
                            return response()->json([
                                'message' => 'No se puede asociar NC/ND a una factura ya pagada/cobrada',
                                'error' => 'La factura relacionada ya fue marcada como pagada o cobrada por alguna empresa.'
                            ], 422);
                        }
                    }
                }
            }

            // Authorize with AFIP
            try {
                $invoice->load('perceptions');
                $afipService = new AfipInvoiceService($company);
                $afipResult = $afipService->authorizeInvoice($invoice);
                
                // Si hay receiver_company_id, el status es issued para emisor pero pending_approval para receptor
                // Usamos company_statuses JSON para manejar estados por empresa
                $companyStatuses = [];
                $companyStatuses[(string)$companyId] = 'issued'; // Emisor ve issued
                if ($receiverCompanyId) {
                    $companyStatuses[(string)$receiverCompanyId] = 'pending_approval'; // Receptor ve pending_approval
                }
                
                $invoice->update([
                    'afip_cae' => $afipResult['cae'],
                    'afip_cae_due_date' => $afipResult['cae_expiration'],
                    'afip_status' => 'approved',
                    'status' => 'issued', // Status global para el emisor
                    'company_statuses' => $companyStatuses,
                    'afip_sent_at' => now(),
                ]);
                
                // Generate PDF and TXT
                $pdfService = new \App\Services\InvoicePdfService();
                $pdfPath = $pdfService->generatePdf($invoice);
                $txtPath = $pdfService->generateTxt($invoice);
                
                $invoice->update([
                    'pdf_url' => $pdfPath,
                    'afip_txt_url' => $txtPath,
                ]);

                // Auditoría empresa: factura emitida y autorizada por AFIP
                app(\App\Services\AuditService::class)->log(
                    (string) $companyId,
                    (string) (auth()->id() ?? ''),
                    'invoice.issued',
                    'Factura emitida y autorizada AFIP',
                    'Invoice',
                    (string) $invoice->id,
                    [
                        'afip_cae' => $afipResult['cae'] ?? null,
                        'afip_cae_due_date' => $afipResult['cae_expiration'] ?? null,
                        'total' => $total,
                        'receiver_company_id' => $receiverCompanyId,
                    ]
                );

                // Si hay empresa receptora, registrar auditoría en su contexto también
                if ($receiverCompanyId) {
                    app(\App\Services\AuditService::class)->log(
                        (string) $receiverCompanyId,
                        (string) (auth()->id() ?? ''),
                        'invoice.received.pending_approval',
                        'Factura recibida pendiente de aprobación',
                        'Invoice',
                        (string) $invoice->id,
                        [
                            'issuer_company_id' => (string) $companyId,
                            'total' => $total,
                        ]
                    );
                }
                
                // NO crear factura duplicada - cada empresa verá la misma factura según su rol
            } catch (\Exception $e) {
                DB::rollBack();
                
                Log::error('AFIP authorization failed - Invoice not created', [
                    'company_id' => $companyId,
                    'voucher_number' => $voucherNumber,
                    'error' => $e->getMessage(),
                ]);
                
                return response()->json([
                    'message' => 'AFIP rechazó la factura',
                    'error' => $e->getMessage(),
                ], 422);
            }

            // Update company's last invoice number
            $company->update([
                'last_invoice_number' => $voucherNumber
            ]);

            // Send notifications
            if ($receiverCompanyId) {
                \App\Helpers\NotificationHelper::notifyInvoiceReceived($invoice, auth()->id());
                
                $receiverCompany = Company::find($receiverCompanyId);
                if ($receiverCompany && $receiverCompany->required_approvals > 0) {
                    \App\Helpers\NotificationHelper::notifyInvoicePendingApproval($invoice, auth()->id());
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Factura autorizada por AFIP exitosamente',
                'invoice' => $invoice->load(['client', 'receiverCompany', 'items', 'perceptions']),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Invoice creation failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error al crear la factura',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($companyId, $id)
    {
        $company = Company::findOrFail($companyId);
        
        try {
            $invoice = $this->invoiceService->getInvoice($companyId, $id);
            $this->authorize('view', $invoice);
            return response()->json($invoice);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'La factura no existe o fue eliminada',
            ], 404);
        }
    }

    public function destroy($companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)->findOrFail($id);

        $this->authorize('delete', $invoice);

        // Check if invoice has payments
        if ($invoice->payments()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar una factura con pagos registrados',
            ], 422);
        }

        // En homologación, permitir borrar cualquier factura para testing
        $company = Company::findOrFail($companyId);
        $isHomologation = $company->afipCertificate && $company->afipCertificate->environment === 'testing';
        
        if (!$isHomologation) {
            // En producción, aplicar validaciones estrictas
            if (in_array($invoice->status, ['issued', 'approved', 'paid'])) {
                return response()->json([
                    'message' => 'No se puede eliminar facturas emitidas. Usá notas de crédito para anularlas.',
                ], 422);
            }

            if ($invoice->afip_cae && !str_starts_with($invoice->afip_cae, 'SIM-')) {
                return response()->json([
                    'message' => 'No se puede eliminar facturas con CAE real. Usá notas de crédito.',
                ], 422);
            }
        }

        $invoice->delete();

        return response()->json([
            'message' => 'Factura eliminada correctamente',
        ]);
    }

    public function deleteAll($companyId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('viewAny', [Invoice::class, $company]);

        DB::beginTransaction();
        try {
            Invoice::where('issuer_company_id', $companyId)
                ->orWhere('receiver_company_id', $companyId)
                ->delete();
            
            DB::commit();

            // Auditoría empresa: carga manual de factura recibida
            app(\App\Services\AuditService::class)->log(
                (string) $companyId,
                (string) (auth()->id() ?? ''),
                'invoice.manual_received.created',
                'Factura recibida cargada manualmente',
                'Invoice',
                (string) $invoice->id,
                [
                    'supplier_id' => $supplierId,
                    'total' => $total,
                ]
            );
            return response()->json(['message' => 'Todas las facturas fueron eliminadas']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al eliminar facturas'], 500);
        }
    }

    public function cancel($companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)->findOrFail($id);

        $this->authorize('delete', $invoice);

        if ($invoice->status === 'cancelled') {
            return response()->json([
                'message' => 'Invoice is already cancelled',
            ], 422);
        }

        // Mark as cancelled instead of deleting
        $invoice->update([
            'status' => 'cancelled',
        ]);

        return response()->json([
            'message' => 'Invoice cancelled successfully',
            'note' => 'To legally cancel this invoice, you should issue a credit note.',
        ]);
    }

    public function archive($companyId, $id)
    {
        $invoice = Invoice::where('receiver_company_id', $companyId)->findOrFail($id);

        $this->authorize('update', $invoice);

        if ($invoice->status !== 'rejected') {
            return response()->json([
                'message' => 'Only rejected invoices can be archived',
            ], 422);
        }

        $invoice->update([
            'status' => 'archived',
        ]);

        return response()->json([
            'message' => 'Invoice archived successfully',
        ]);
    }

    public function validateWithAfip(ValidateWithAfipRequest $request, $companyId)
    {
        $company = Company::with('afipCertificate')->findOrFail($companyId);
        
        $this->authorize('create', [Invoice::class, $company]);

        $validated = $request->validated();

        try {
            // Parse invoice number
            [$salesPoint, $voucherNumber] = explode('-', $validated['invoice_number']);
            $salesPoint = (int) $salesPoint;
            $voucherNumber = (int) $voucherNumber;

            $afipService = new AfipInvoiceService($company);
            $invoiceType = $this->getAfipInvoiceTypeCode($validated['invoice_type']);
            
            $result = $afipService->consultInvoice(
                $validated['issuer_cuit'],
                $invoiceType,
                $salesPoint,
                $voucherNumber
            );

            return response()->json([
                'success' => true,
                'invoice' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo validar la factura con AFIP',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function syncFromAfip(SyncFromAfipRequest $request, $companyId)
    {
        $company = Company::with('afipCertificate')->findOrFail($companyId);
        
        $this->authorize('create', [Invoice::class, $company]);

        $validated = $request->validated();

        if (!$company->afipCertificate || !$company->afipCertificate->is_active) {
            return response()->json([
                'success' => false,
                'error' => 'No active AFIP certificate found for this company',
            ], 403);
        }

        try {
            $afipService = new AfipInvoiceService($company);
            
            if ($validated['mode'] === 'single') {
                $result = $this->invoiceSyncService->syncSingleInvoice($company, $afipService, $validated);
                
                // Handle response format
                if (isset($result['success']) && !$result['success']) {
                    return response()->json($result, 404);
                }
                
                return response()->json($result, 200);
            } else {
                $result = $this->invoiceSyncService->syncByDateRange($company, $afipService, $validated);
                
                // Handle response format
                if (isset($result['success']) && !$result['success']) {
                    return response()->json($result, 422);
                }
                
                return response()->json($result, 200);
            }

        } catch (\Exception $e) {
            Log::error('Sync from AFIP failed', [
                'company_id' => $companyId,
                'mode' => $validated['mode'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // syncSingleInvoice and syncByDateRange moved to InvoiceSyncService

    private function getAfipInvoiceTypeCode(string $type): int
    {
        $types = ['A' => 1, 'B' => 6, 'C' => 11, 'E' => 19];
        return $types[$type] ?? 6;
    }

    public function storeManualIssued(StoreManualIssuedInvoiceRequest $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('create', [Invoice::class, $company]);
        
        $validated = $request->validated();

        try {
            DB::beginTransaction();

            // Calculate totals
            $subtotal = 0;
            $totalTaxes = 0;
            foreach ($validated['items'] as $item) {
                $itemBase = $item['quantity'] * $item['unit_price'];
                $itemDiscount = $itemBase * (($item['discount_percentage'] ?? 0) / 100);
                $itemSubtotal = $itemBase - $itemDiscount;
                $taxRate = $item['tax_rate'] ?? 0;
                // Exento (-1) y No Gravado (-2) tienen IVA = 0
                $itemTax = ($taxRate > 0) ? $itemSubtotal * ($taxRate / 100) : 0;
                $subtotal += $itemSubtotal;
                $totalTaxes += $itemTax;
            }
            
            $total = $subtotal + $totalTaxes;

            // Validate total amount limit
            if ($total > 999999999.99) {
                return response()->json([
                    'message' => 'El monto total del comprobante no puede superar los $999.999.999,99. Verifique los importes ingresados.',
                    'errors' => ['total' => ['El monto total es demasiado alto']]
                ], 422);
            }

            // Handle different invoice number formats
            $salesPoint = $validated['sales_point'] ?? 1;
            $voucherNumber = null;
            $invoiceNumber = null;
            
            if ($validated['invoice_number'] ?? null) {
                $invoiceParts = explode('-', $validated['invoice_number']);
                $salesPoint = isset($invoiceParts[0]) ? (int)$invoiceParts[0] : $salesPoint;
                $voucherNumber = isset($invoiceParts[1]) ? (int)$invoiceParts[1] : 0;
                $invoiceNumber = $validated['invoice_number'];
            } elseif ($validated['voucher_number'] ?? null) {
                $voucherNumber = (int)$validated['voucher_number'];
                $invoiceNumber = sprintf('%04d-%08d', $salesPoint, $voucherNumber);
            } elseif ($validated['number'] ?? null) {
                if (strpos($validated['number'], '-') !== false) {
                    $invoiceParts = explode('-', $validated['number']);
                    $salesPoint = isset($invoiceParts[0]) ? (int)$invoiceParts[0] : $salesPoint;
                    $voucherNumber = isset($invoiceParts[1]) ? (int)$invoiceParts[1] : 0;
                } else {
                    $voucherNumber = (int)$validated['number'];
                }
                $invoiceNumber = sprintf('%04d-%08d', $salesPoint, $voucherNumber);
            }
            
            if (!$voucherNumber) {
                return response()->json([
                    'message' => 'Número de factura requerido',
                    'debug' => 'Campos recibidos: ' . implode(', ', array_keys($request->all()))
                ], 422);
            }
            
            // Convert invoice type code to internal type
            $invoiceTypeInternal = \App\Services\VoucherTypeService::getTypeByCode($validated['invoice_type']) ?? $validated['invoice_type'];
            
            // Si es NC/ND con factura relacionada, heredar receptor
            $clientId = null;
            $receiverCompanyId = null;
            $clientName = null;
            $clientDocument = null;
            
            if (!empty($validated['related_invoice_id'])) {
                $relatedInvoice = Invoice::with(['client', 'receiverCompany'])->find($validated['related_invoice_id']);
                if ($relatedInvoice) {
                    // PRIORIDAD 1: Si tiene client_id, usarlo
                    if ($relatedInvoice->client_id) {
                        $clientId = $relatedInvoice->client_id;
                        $client = \App\Models\Client::withTrashed()->find($clientId);
                        if ($client) {
                            $clientName = $client->business_name ?? trim($client->first_name . ' ' . $client->last_name);
                            $clientDocument = $client->document_number;
                        }
                    }
                    // PRIORIDAD 2: Si tiene receiver_name/receiver_document pero no client_id, usar esos datos
                    elseif ($relatedInvoice->receiver_name && $relatedInvoice->receiver_document) {
                        $clientName = $relatedInvoice->receiver_name;
                        $clientDocument = $relatedInvoice->receiver_document;
                        // Intentar encontrar o crear el cliente
                        $client = \App\Models\Client::withTrashed()
                            ->where('company_id', $companyId)
                            ->where('document_number', $clientDocument)
                            ->first();
                        if ($client) {
                            $clientId = $client->id;
                        }
                    }
                    // PRIORIDAD 3: Si tiene receiverCompany, crear cliente desde ahí
                    elseif ($relatedInvoice->receiverCompany) {
                        $clientName = $relatedInvoice->receiverCompany->name;
                        $clientDocument = $relatedInvoice->receiverCompany->national_id;
                        $client = \App\Models\Client::firstOrCreate(
                            [
                                'company_id' => $companyId,
                                'document_number' => $clientDocument,
                            ],
                            [
                                'document_type' => 'CUIT',
                                'business_name' => $clientName,
                                'tax_condition' => $relatedInvoice->receiverCompany->tax_condition ?? 'registered_taxpayer',
                            ]
                        );
                        $clientId = $client->id;
                    }
                    
                    Log::info('Inherited client from related invoice', [
                        'related_invoice_id' => $relatedInvoice->id,
                        'client_id' => $clientId,
                        'client_name' => $clientName,
                        'client_document' => $clientDocument,
                    ]);
                }
            } else {
                $receiverCompanyId = $validated['receiver_company_id'] ?? null;
                
                Log::info('Manual issued invoice - Processing receiver', [
                    'receiver_company_id' => $receiverCompanyId,
                    'client_id_from_request' => $validated['client_id'] ?? null,
                ]);
                
                // Si seleccionó empresa conectada, crear cliente automáticamente
                if ($receiverCompanyId) {
                    $receiverCompany = Company::findOrFail($receiverCompanyId);
                    $clientName = $receiverCompany->name;
                    $clientDocument = $receiverCompany->national_id;
                    
                    Log::info('Creating/finding client from connected company', [
                        'company_name' => $clientName,
                        'document' => $clientDocument,
                    ]);
                    
                    $client = \App\Models\Client::firstOrCreate(
                        [
                            'company_id' => $companyId,
                            'document_number' => $clientDocument,
                        ],
                        [
                            'document_type' => 'CUIT',
                            'business_name' => $clientName,
                            'tax_condition' => $receiverCompany->tax_condition ?? 'registered_taxpayer',
                            'email' => $receiverCompany->email ?? null,
                            'phone' => $receiverCompany->phone ?? null,
                            'address' => $receiverCompany->address ? ($receiverCompany->address->street . ' ' . $receiverCompany->address->street_number) : null,
                            'city' => $receiverCompany->address->city ?? null,
                            'province' => $receiverCompany->address->province ?? null,
                            'postal_code' => $receiverCompany->address->postal_code ?? null,
                        ]
                    );
                    $clientId = $client->id;
                    
                    Log::info('Client created/found', [
                        'client_id' => $clientId,
                        'client_name' => $client->business_name,
                    ]);
                } else {
                    $clientId = $validated['client_id'] ?? null;
                    $clientName = $validated['client_name'] ?? null;
                    $clientDocument = $validated['client_document'] ?? null;
                    
                    // Si tiene client_id pero no tiene nombre, obtenerlo del cliente
                    if ($clientId && !$clientName) {
                        $client = \App\Models\Client::withTrashed()->find($clientId);
                        if ($client) {
                            $clientName = $client->business_name ?? trim($client->first_name . ' ' . $client->last_name);
                            $clientDocument = $client->document_number;
                        }
                    }
                    
                    Log::info('Using existing client or manual data', [
                        'client_id' => $clientId,
                        'client_name' => $clientName,
                        'client_document' => $clientDocument,
                    ]);
                }
            }
            
            // Check for duplicate invoice
            $existingInvoice = Invoice::where('issuer_company_id', $companyId)
                ->where('type', $invoiceTypeInternal)
                ->where('sales_point', $salesPoint)
                ->where('voucher_number', $voucherNumber)
                ->first();
                
            if ($existingInvoice) {
                return response()->json([
                    'message' => 'Ya existe una factura con el mismo tipo, punto de venta y número. Verifique los datos ingresados.',
                    'errors' => ['voucher_number' => ['Factura duplicada']]
                ], 422);
            }
            
            // Create manual issued invoice (NO enviar a empresa conectada)
            Log::info('Creating manual issued invoice with data', [
                'client_id' => $clientId,
                'receiver_name' => $clientName,
                'receiver_document' => $clientDocument,
            ]);
            
            $invoice = Invoice::create([
                'number' => $invoiceNumber,
                'type' => $invoiceTypeInternal,
                'sales_point' => $salesPoint,
                'voucher_number' => $voucherNumber,
                'concept' => $validated['concept'] ?? 'products',
                'issuer_company_id' => $companyId,
                'receiver_company_id' => null, // NO usar receiver_company_id en carga manual
                'client_id' => $clientId,
                'receiver_name' => $clientName,
                'receiver_document' => $clientDocument,
                'related_invoice_id' => $validated['related_invoice_id'] ?? null,
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'subtotal' => $subtotal,
                'total_taxes' => $totalTaxes,
                'total_perceptions' => 0,
                'total' => $total,
                'currency' => $validated['currency'],
                'exchange_rate' => $validated['exchange_rate'] ?? 1,
                'notes' => $validated['notes'] ?? null,
                'status' => 'issued',
                'afip_status' => 'approved',
                'afip_cae' => $validated['cae'] ?? null,
                'afip_cae_due_date' => $validated['cae_due_date'] ?? null,
                'is_manual_load' => true,
                'created_by' => auth()->id(),
            ]);

            // Create items
            foreach ($validated['items'] as $index => $item) {
                $itemBase = $item['quantity'] * $item['unit_price'];
                $itemDiscount = $itemBase * (($item['discount_percentage'] ?? 0) / 100);
                $itemSubtotal = $itemBase - $itemDiscount;
                $itemTax = $itemSubtotal * (($item['tax_rate'] ?? 0) / 100);

                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_percentage' => $item['discount_percentage'] ?? 0,
                    'tax_rate' => $item['tax_rate'] ?? 0,
                    'tax_amount' => $itemTax,
                    'subtotal' => $itemSubtotal,
                    'order_index' => $index,
                ]);
            }

            // Si es NC/ND con factura relacionada, validar que no esté pagada/cobrada
            if (isset($validated['related_invoice_id'])) {
                $relatedInvoice = Invoice::find($validated['related_invoice_id']);
                if ($relatedInvoice) {
                    $companyStatuses = $relatedInvoice->company_statuses ?? [];
                    foreach ($companyStatuses as $status) {
                        if (in_array($status, ['paid', 'collected'])) {
                            DB::rollBack();
                            return response()->json([
                                'message' => 'No se puede asociar NC/ND a una factura ya pagada/cobrada',
                                'error' => 'La factura relacionada ya fue marcada como pagada o cobrada por alguna empresa.'
                            ], 422);
                        }
                    }
                }
                
                $this->invoiceService->updateRelatedInvoiceBalance($validated['related_invoice_id']);
            }
            
            DB::commit();

            // Auditoría empresa: creación de factura recibida
            app(\App\Services\AuditService::class)->log(
                (string) $companyId,
                (string) (auth()->id() ?? ''),
                'invoice.received.created',
                'Factura recibida creada',
                'Invoice',
                (string) $invoice->id,
                [
                    'supplier_id' => $supplier->id,
                    'total' => $total,
                ]
            );

            // Determinar si se creó un cliente automáticamente
            $autoCreatedClient = false;
            if ($receiverCompanyId && $clientId) {
                $autoCreatedClient = true;
            }

            $message = 'Factura emitida creada exitosamente';
            if ($autoCreatedClient) {
                $message .= '. Se creó automáticamente el cliente en tu lista de clientes externos.';
            }

            return response()->json([
                'message' => $message,
                'invoice' => $invoice->load(['items']),
                'auto_created_client' => $autoCreatedClient,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Manual issued invoice creation failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error al crear la factura emitida',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function storeManualReceived(StoreManualReceivedInvoiceRequest $request, $companyId)
    {
        try {
            Log::info('=== storeManualReceived START ===', [
                'company_id' => $companyId,
                'request_data' => $request->all(),
                'headers' => $request->headers->all(),
            ]);
            
            $company = Company::findOrFail($companyId);
            $this->authorize('create', [Invoice::class, $company]);
            
            Log::info('Authorization passed, starting validation');
        } catch (\Exception $e) {
            Log::error('Error before validation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        $validated = $request->validate([
            'supplier_id' => 'required_without_all:issuer_company_id,supplier_name,related_invoice_id|exists:suppliers,id',
            'issuer_company_id' => 'required_without_all:supplier_id,supplier_name,related_invoice_id|string',
            'supplier_name' => 'required_without_all:issuer_company_id,supplier_id,related_invoice_id|string|max:200',
            'supplier_document' => 'required_with:supplier_name|string|max:20',
            'related_invoice_id' => 'nullable|exists:invoices,id',
            'invoice_type' => 'required|string',
            'invoice_number' => 'nullable|string|max:50',
            'number' => 'nullable|string|max:50',
            'voucher_number' => 'nullable|max:50',
            'sales_point' => 'nullable|integer|min:1|max:9999',
            'concept' => 'nullable|in:products,services,products_services',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date|before:2030-01-01',
            'currency' => 'required|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:200',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount_percentage' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_rate' => 'nullable|numeric|min:-2|max:100',
            'cae' => 'nullable|string|max:20',
            'cae_due_date' => 'nullable|date',
            'perceptions' => 'nullable|array',
            'perceptions.*.type' => 'required|string|max:100',
            'perceptions.*.name' => 'required|string|max:100',
            'perceptions.*.rate' => 'nullable|numeric|min:0|max:100',
            'perceptions.*.amount' => 'nullable|numeric|min:0',
            'perceptions.*.jurisdiction' => 'nullable|string|max:100',
            'perceptions.*.base_type' => 'nullable|in:net,total,vat',
        ], [
            'supplier_id.exists' => 'El proveedor seleccionado no existe.',
            'supplier_name.required_without' => 'El nombre del proveedor es obligatorio cuando no se selecciona un proveedor existente.',
            'supplier_name.max' => 'El nombre del proveedor no puede superar los 200 caracteres.',
            'supplier_document.required_without' => 'El documento del proveedor es obligatorio cuando no se selecciona un proveedor existente.',
            'supplier_document.max' => 'El documento del proveedor no puede superar los 20 caracteres.',
            'invoice_type.required' => 'El tipo de factura es obligatorio.',
            'invoice_type.in' => 'El tipo de factura seleccionado no es válido.',
            'invoice_number.required' => 'El número de factura es obligatorio.',
            'invoice_number.max' => 'El número de factura no puede superar los 50 caracteres.',
            'issue_date.required' => 'La fecha de emisión es obligatoria.',
            'issue_date.date' => 'La fecha de emisión debe ser una fecha válida.',
            'due_date.required' => 'La fecha de vencimiento es obligatoria.',
            'due_date.date' => 'La fecha de vencimiento debe ser una fecha válida.',
            'due_date.after_or_equal' => 'La fecha de vencimiento debe ser igual o posterior a la fecha de emisión.',
            'due_date.before' => 'La fecha de vencimiento no puede ser posterior al año 2030.',
            'currency.required' => 'La moneda es obligatoria.',
            'currency.size' => 'La moneda debe tener exactamente 3 caracteres.',
            'exchange_rate.numeric' => 'El tipo de cambio debe ser un número.',
            'exchange_rate.min' => 'El tipo de cambio debe ser mayor o igual a 0.',
            'notes.max' => 'Las notas no pueden superar los 500 caracteres.',
            'items.required' => 'Debe agregar al menos un ítem.',
            'items.min' => 'Debe agregar al menos un ítem.',
            'items.*.description.required' => 'La descripción del ítem es obligatoria.',
            'items.*.description.max' => 'La descripción del ítem no puede superar los 200 caracteres.',
            'items.*.quantity.required' => 'La cantidad es obligatoria.',
            'items.*.quantity.numeric' => 'La cantidad debe ser un número.',
            'items.*.quantity.min' => 'La cantidad debe ser mayor a 0.',
            'items.*.unit_price.required' => 'El precio unitario es obligatorio.',
            'items.*.unit_price.numeric' => 'El precio unitario debe ser un número.',
            'items.*.unit_price.min' => 'El precio unitario debe ser mayor o igual a 0.',
            'items.*.tax_rate.numeric' => 'La tasa de impuesto debe ser un número.',
            'items.*.tax_rate.min' => 'La tasa de impuesto debe ser mayor o igual a 0.',
            'items.*.tax_rate.max' => 'La tasa de impuesto no puede superar el 100%.',
        ]);

        \Log::info('storeManualReceived - Request data', [
            'all_data' => $request->all(),
            'has_supplier_id' => $request->has('supplier_id'),
            'supplier_id' => $request->input('supplier_id'),
            'has_issuer_company_id' => $request->has('issuer_company_id'),
            'issuer_company_id' => $request->input('issuer_company_id')
        ]);

        try {
            DB::beginTransaction();

            // Calculate totals
            $subtotal = 0;
            $totalTaxes = 0;
            foreach ($validated['items'] as $item) {
                $itemBase = $item['quantity'] * $item['unit_price'];
                $itemDiscount = $itemBase * (($item['discount_percentage'] ?? 0) / 100);
                $itemSubtotal = $itemBase - $itemDiscount;
                $itemTax = $itemSubtotal * (($item['tax_rate'] ?? 0) / 100);
                $subtotal += $itemSubtotal;
                $totalTaxes += $itemTax;
            }
            
            // Calculate perceptions
            $totalPerceptions = 0;
            if (!empty($validated['perceptions'])) {
                foreach ($validated['perceptions'] as $perception) {
                    $baseAmount = $this->calculatePerceptionBase(
                        $perception['type'],
                        $perception['base_type'] ?? null,
                        $subtotal,
                        $totalTaxes
                    );
                    $totalPerceptions += $baseAmount * (($perception['rate'] ?? 0) / 100);
                }
            }
            
            $total = $subtotal + $totalTaxes + $totalPerceptions;

            // Handle different invoice number formats
            $salesPoint = $validated['sales_point'] ?? 1;
            $voucherNumber = null;
            $invoiceNumber = null;
            
            if ($validated['invoice_number'] ?? null) {
                $invoiceParts = explode('-', $validated['invoice_number']);
                $salesPoint = isset($invoiceParts[0]) ? (int)$invoiceParts[0] : $salesPoint;
                $voucherNumber = isset($invoiceParts[1]) ? (int)$invoiceParts[1] : 0;
                $invoiceNumber = $validated['invoice_number'];
            } elseif ($validated['voucher_number'] ?? null) {
                $voucherNumber = (int)$validated['voucher_number'];
                $invoiceNumber = sprintf('%04d-%08d', $salesPoint, $voucherNumber);
            } elseif ($validated['number'] ?? null) {
                if (strpos($validated['number'], '-') !== false) {
                    $invoiceParts = explode('-', $validated['number']);
                    $salesPoint = isset($invoiceParts[0]) ? (int)$invoiceParts[0] : $salesPoint;
                    $voucherNumber = isset($invoiceParts[1]) ? (int)$invoiceParts[1] : 0;
                } else {
                    $voucherNumber = (int)$validated['number'];
                }
                $invoiceNumber = sprintf('%04d-%08d', $salesPoint, $voucherNumber);
            }
            
            if (!$voucherNumber) {
                return response()->json([
                    'message' => 'Número de factura requerido',
                    'debug' => 'Campos recibidos: ' . implode(', ', array_keys($request->all()))
                ], 422);
            }

            // Get supplier/issuer data
            $supplierName = null;
            $supplierDocument = null;
            $supplierId = null;
            $issuerCompanyId = null;
            
            // Si es NC/ND con factura relacionada, heredar emisor
            if (!empty($validated['related_invoice_id'])) {
                $relatedInvoice = Invoice::with(['supplier', 'issuerCompany'])->find($validated['related_invoice_id']);
                if ($relatedInvoice) {
                    // PRIORIDAD 1: Si tiene supplier_id, usarlo
                    if ($relatedInvoice->supplier_id) {
                        $supplierId = $relatedInvoice->supplier_id;
                        $supplier = \App\Models\Supplier::withTrashed()->find($supplierId);
                        if ($supplier) {
                            $supplierName = $supplier->business_name ?? trim($supplier->first_name . ' ' . $supplier->last_name);
                            $supplierDocument = $supplier->document_number;
                        }
                    }
                    // PRIORIDAD 2: Si tiene issuer_name/issuer_document pero no supplier_id, usar esos datos
                    elseif ($relatedInvoice->issuer_name && $relatedInvoice->issuer_document) {
                        $supplierName = $relatedInvoice->issuer_name;
                        $supplierDocument = $relatedInvoice->issuer_document;
                        // Intentar encontrar o crear el proveedor
                        $supplier = \App\Models\Supplier::withTrashed()
                            ->where('company_id', $companyId)
                            ->where('document_number', $supplierDocument)
                            ->first();
                        if ($supplier) {
                            $supplierId = $supplier->id;
                        }
                    }
                    // PRIORIDAD 3: Si tiene issuerCompany, crear proveedor desde ahí
                    elseif ($relatedInvoice->issuerCompany) {
                        $relatedInvoice->issuerCompany->load(['primaryBankAccount', 'bankAccounts']);
                        $primaryBankAccount = $relatedInvoice->issuerCompany->primaryBankAccount ?? $relatedInvoice->issuerCompany->bankAccounts->first();
                        
                        $supplierName = $relatedInvoice->issuerCompany->name;
                        $supplierDocument = $relatedInvoice->issuerCompany->national_id;
                        $supplier = \App\Models\Supplier::firstOrCreate(
                            [
                                'company_id' => $companyId,
                                'document_number' => $supplierDocument,
                            ],
                            [
                                'document_type' => 'CUIT',
                                'business_name' => $supplierName,
                                'tax_condition' => $relatedInvoice->issuerCompany->tax_condition ?? 'registered_taxpayer',
                                // Copy bank account data
                                'bank_name' => $primaryBankAccount->bank_name ?? null,
                                'bank_cbu' => $primaryBankAccount->cbu ?? $relatedInvoice->issuerCompany->cbu ?? null,
                                'bank_account_type' => $primaryBankAccount->account_type ?? null,
                                'bank_alias' => $primaryBankAccount->alias ?? null,
                            ]
                        );
                        
                        // Update bank data if supplier already existed
                        if (!$supplier->wasRecentlyCreated && 
                            (empty($supplier->bank_cbu) || empty($supplier->bank_account_number))) {
                            $supplier->bank_name = $primaryBankAccount->bank_name ?? $supplier->bank_name;
                            $supplier->bank_cbu = $primaryBankAccount->cbu ?? $relatedInvoice->issuerCompany->cbu ?? $supplier->bank_cbu;
                            $supplier->bank_account_type = $primaryBankAccount->account_type ?? $supplier->bank_account_type;
                            $supplier->bank_alias = $primaryBankAccount->alias ?? $supplier->bank_alias;
                            $supplier->save();
                        }
                        
                        $supplierId = $supplier->id;
                    }
                    
                    Log::info('Inherited supplier from related invoice', [
                        'related_invoice_id' => $relatedInvoice->id,
                        'supplier_id' => $supplierId,
                        'supplier_name' => $supplierName,
                        'supplier_document' => $supplierDocument,
                    ]);
                }
            } elseif (!empty($validated['supplier_id'])) {
                // Using existing supplier
                $supplier = \App\Models\Supplier::findOrFail($validated['supplier_id']);
                $supplierId = $supplier->id;
                $supplierName = $supplier->business_name ?? trim($supplier->first_name . ' ' . $supplier->last_name);
                $supplierDocument = $supplier->document_number;
            } elseif (!empty($validated['issuer_company_id'])) {
                // Si seleccionó empresa conectada, crear proveedor automáticamente
                $issuerCompany = Company::with(['primaryBankAccount', 'bankAccounts', 'address'])->findOrFail($validated['issuer_company_id']);
                $supplierName = $issuerCompany->name;
                $supplierDocument = $issuerCompany->national_id;
                
                // Get bank account data
                $primaryBankAccount = $issuerCompany->primaryBankAccount ?? $issuerCompany->bankAccounts->first();
                
                Log::info('Creating/finding supplier from connected company', [
                    'company_name' => $supplierName,
                    'document' => $supplierDocument,
                    'has_bank_account' => $primaryBankAccount ? 'yes' : 'no',
                ]);
                
                $supplier = \App\Models\Supplier::firstOrCreate(
                    [
                        'company_id' => $companyId,
                        'document_number' => $supplierDocument,
                    ],
                    [
                        'document_type' => 'CUIT',
                        'business_name' => $supplierName,
                        'tax_condition' => $issuerCompany->tax_condition ?? 'registered_taxpayer',
                        'email' => $issuerCompany->email ?? null,
                        'phone' => $issuerCompany->phone ?? null,
                        'address' => $issuerCompany->address ? ($issuerCompany->address->street . ' ' . $issuerCompany->address->street_number) : null,
                        'city' => $issuerCompany->address->city ?? null,
                        'province' => $issuerCompany->address->province ?? null,
                        'postal_code' => $issuerCompany->address->postal_code ?? null,
                        // Copy bank account data
                        'bank_name' => $primaryBankAccount->bank_name ?? null,
                        'bank_cbu' => $primaryBankAccount->cbu ?? $issuerCompany->cbu ?? null,
                        'bank_account_type' => $primaryBankAccount->account_type ?? null,
                        'bank_alias' => $primaryBankAccount->alias ?? null,
                    ]
                );
                
                // Update bank data if supplier already existed but bank data is missing
                if (!$supplier->wasRecentlyCreated && 
                    (empty($supplier->bank_cbu) || empty($supplier->bank_account_number))) {
                    $supplier->bank_name = $primaryBankAccount->bank_name ?? $supplier->bank_name;
                    $supplier->bank_cbu = $primaryBankAccount->cbu ?? $issuerCompany->cbu ?? $supplier->bank_cbu;
                    $supplier->bank_account_type = $primaryBankAccount->account_type ?? $supplier->bank_account_type;
                    $supplier->bank_alias = $primaryBankAccount->alias ?? $supplier->bank_alias;
                    $supplier->save();
                    
                    Log::info('Updated supplier bank data from connected company', [
                        'supplier_id' => $supplier->id,
                    ]);
                }
                
                $supplierId = $supplier->id;
                
                Log::info('Supplier created/found', [
                    'supplier_id' => $supplierId,
                    'supplier_name' => $supplier->business_name,
                    'has_bank_data' => !empty($supplier->bank_cbu) || !empty($supplier->bank_account_number),
                ]);
            } else {
                // Manual supplier data
                $supplierName = $validated['supplier_name'] ?? null;
                $supplierDocument = $validated['supplier_document'] ?? null;
            }
            
            // Determine initial status based on approval configuration
            $requiredApprovals = (int)($company->required_approvals ?? 0);
            
            // Convert invoice type code to internal type
            $invoiceTypeInternal = \App\Services\VoucherTypeService::getTypeByCode($validated['invoice_type']) ?? $validated['invoice_type'];
            
            // Check for duplicate: same receiver + type + sales_point + number
            $existingInvoice = Invoice::withTrashed()
                ->where('receiver_company_id', $companyId)
                ->where('type', $invoiceTypeInternal)
                ->where('sales_point', $salesPoint)
                ->where('voucher_number', $voucherNumber)
                ->first();
                
            if ($existingInvoice) {
                DB::rollBack();
                
                $message = 'Ya existe una factura de este proveedor con el mismo tipo y número.';
                if ($existingInvoice->trashed()) {
                    $message .= ' La factura está eliminada.';
                }
                
                return response()->json([
                    'message' => $message,
                    'existing_invoice' => [
                        'id' => $existingInvoice->id,
                        'number' => $existingInvoice->number,
                        'status' => $existingInvoice->status,
                        'deleted_at' => $existingInvoice->deleted_at,
                    ]
                ], 422);
            }
            
            // Create manual received invoice (NO enviar a empresa conectada)
            try {
                $invoice = Invoice::create([
                'number' => $invoiceNumber,
                'type' => $invoiceTypeInternal,
                'sales_point' => $salesPoint,
                'voucher_number' => $voucherNumber,
                'concept' => $validated['concept'] ?? 'products',
                'issuer_company_id' => $companyId, // Placeholder para constraint
                'receiver_company_id' => $companyId, // Your company receives
                'supplier_id' => $supplierId,
                'issuer_name' => $supplierName,
                'issuer_document' => $supplierDocument,
                'related_invoice_id' => $validated['related_invoice_id'] ?? null,
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'subtotal' => $subtotal,
                'total_taxes' => $totalTaxes,
                'total_perceptions' => $totalPerceptions,
                'total' => $total,
                'currency' => $validated['currency'],
                'exchange_rate' => $validated['exchange_rate'] ?? 1,
                'notes' => $validated['notes'] ?? null,
                'status' => $requiredApprovals === 0 ? 'approved' : 'pending_approval',
                'afip_status' => 'approved',
                'approvals_required' => $requiredApprovals,
                'approvals_received' => 0,
                'approval_date' => $requiredApprovals === 0 ? now() : null,
                'afip_cae' => $validated['cae'] ?? null,
                'afip_cae_due_date' => $validated['cae_due_date'] ?? null,
                'manual_supplier' => true,
                'is_manual_load' => true,
                'created_by' => auth()->id(),
            ]);

            // Create items
            foreach ($validated['items'] as $index => $item) {
                $itemBase = $item['quantity'] * $item['unit_price'];
                $itemDiscount = $itemBase * (($item['discount_percentage'] ?? 0) / 100);
                $itemSubtotal = $itemBase - $itemDiscount;
                $itemTax = $itemSubtotal * (($item['tax_rate'] ?? 0) / 100);

                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_percentage' => $item['discount_percentage'] ?? 0,
                    'tax_rate' => $item['tax_rate'] ?? 0,
                    'tax_amount' => $itemTax,
                    'subtotal' => $itemSubtotal,
                    'order_index' => $index,
                ]);
            }

            // Create perceptions
            if (!empty($validated['perceptions'])) {
                foreach ($validated['perceptions'] as $perception) {
                    $baseAmount = $this->calculatePerceptionBase(
                        $perception['type'],
                        $perception['base_type'] ?? null,
                        $subtotal,
                        $totalTaxes
                    );
                    $amount = $baseAmount * (($perception['rate'] ?? 0) / 100);

                    $invoice->perceptions()->create([
                        'type' => $perception['type'],
                        'name' => $perception['name'],
                        'rate' => $perception['rate'] ?? 0,
                        'base_type' => $perception['base_type'] ?? $this->getDefaultBaseType($perception['type']),
                        'jurisdiction' => $perception['jurisdiction'] ?? null,
                        'base_amount' => $baseAmount,
                        'amount' => $amount,
                    ]);
                }
            }

            // Si es NC/ND con factura relacionada, validar que no esté pagada/cobrada
            if (isset($validated['related_invoice_id'])) {
                $relatedInvoice = Invoice::find($validated['related_invoice_id']);
                if ($relatedInvoice) {
                    $companyStatuses = $relatedInvoice->company_statuses ?? [];
                    foreach ($companyStatuses as $status) {
                        if (in_array($status, ['paid', 'collected'])) {
                            DB::rollBack();
                            return response()->json([
                                'message' => 'No se puede asociar NC/ND a una factura ya pagada/cobrada',
                                'error' => 'La factura relacionada ya fue marcada como pagada o cobrada por alguna empresa.'
                            ], 422);
                        }
                    }
                }
                
                $this->invoiceService->updateRelatedInvoiceBalance($validated['related_invoice_id']);
            }
            
            } catch (\Illuminate\Database\QueryException $e) {
                DB::rollBack();
                
                if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    return response()->json([
                        'message' => 'Ya existe una factura con el mismo tipo, punto de venta y número',
                        'error' => 'Factura duplicada. Verifique el tipo (' . $invoiceTypeInternal . '), punto de venta (' . $salesPoint . ') y número (' . $voucherNumber . ')'
                    ], 422);
                }
                
                throw $e;
            }
            
            DB::commit();

            // Determinar si se creó un proveedor automáticamente
            $autoCreatedSupplier = false;
            if (!empty($validated['issuer_company_id']) && $supplierId) {
                $autoCreatedSupplier = true;
            }

            $message = 'Factura recibida creada exitosamente';
            if ($autoCreatedSupplier) {
                $message .= '. Se creó automáticamente el proveedor en tu lista de proveedores externos.';
            }

            return response()->json([
                'message' => $message,
                'invoice' => $invoice->load(['items', 'perceptions', 'supplier']),
                'auto_created_supplier' => $autoCreatedSupplier,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Manual received invoice creation failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'message' => 'Error al crear la factura recibida',
                'error' => $e->getMessage(),
                'debug' => [
                    'line' => $e->getLine(),
                    'file' => basename($e->getFile()),
                ]
            ], 500);
        }
    }

    public function storeReceived(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'invoice_type' => 'required|in:A,B,C,M,NCA,NCB,NCC,NCM,NDA,NDB,NDC,NDM',
            'invoice_number' => 'required|string',
            'issue_date' => 'required|date',
            'due_date' => 'required|date',
            'currency' => 'required|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:200',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:-2|max:100',
        ]);

        try {
            DB::beginTransaction();

            // Verify supplier belongs to this company
            $supplier = \App\Models\Supplier::where('company_id', $companyId)
                ->findOrFail($validated['supplier_id']);

            // Calcular totales
            $subtotal = 0;
            $totalTaxes = 0;
            foreach ($validated['items'] as $item) {
                $itemSubtotal = $item['quantity'] * $item['unit_price'];
                $itemTax = $itemSubtotal * ($item['tax_rate'] / 100);
                $subtotal += $itemSubtotal;
                $totalTaxes += $itemTax;
            }
            $total = $subtotal + $totalTaxes;

            // Parsear el número de factura del proveedor (formato: 0001-00000123)
            $invoiceParts = explode('-', $validated['invoice_number']);
            $salesPoint = isset($invoiceParts[0]) ? (int)$invoiceParts[0] : 0;
            $voucherNumber = isset($invoiceParts[1]) ? (int)$invoiceParts[1] : 0;

            // Determinar estado inicial basado en configuración de aprobaciones
            $requiredApprovals = (int)($company->required_approvals ?? 0);
            
            // Crear factura recibida
            // NOTA: issuer_company_id se usa como placeholder (mismo que receiver) para cumplir constraint
            // El supplier_id identifica al verdadero emisor externo
            $invoice = Invoice::create([
                'number' => $validated['invoice_number'],
                'type' => $validated['invoice_type'],
                'sales_point' => $salesPoint,
                'voucher_number' => $voucherNumber,
                'concept' => 'products',
                'issuer_company_id' => $companyId, // Placeholder para constraint
                'receiver_company_id' => $companyId, // Tu empresa recibe
                'supplier_id' => $supplier->id, // Emisor real (externo)
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'subtotal' => $subtotal,
                'total_taxes' => $totalTaxes,
                'total_perceptions' => 0,
                'total' => $total,
                'currency' => $validated['currency'],
                'exchange_rate' => $validated['exchange_rate'] ?? 1,
                'notes' => $validated['notes'] ?? null,
                'status' => $requiredApprovals === 0 ? 'approved' : 'pending_approval',
                'afip_status' => 'approved',
                'approvals_required' => $requiredApprovals,
                'approvals_received' => 0,
                'approval_date' => $requiredApprovals === 0 ? now() : null,
                'created_by' => auth()->id(),
            ]);

            // Crear items
            foreach ($validated['items'] as $index => $item) {
                $itemSubtotal = $item['quantity'] * $item['unit_price'];
                $itemTax = $itemSubtotal * ($item['tax_rate'] / 100);

                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'],
                    'tax_amount' => $itemTax,
                    'subtotal' => $itemSubtotal,
                    'order_index' => $index,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Received invoice created successfully',
                'invoice' => $invoice->load(['supplier', 'items']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Received invoice creation failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create received invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function uploadAttachment(Request $request, $companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)
            ->orWhere('receiver_company_id', $companyId)
            ->findOrFail($id);

        $this->authorize('update', $invoice);

        $request->validate([
            'attachment' => 'required|file|mimes:pdf|max:10240',
        ]);

        $file = $request->file('attachment');
        $originalName = $file->getClientOriginalName();
        $path = $file->store('invoices/attachments', 'public');

        Log::info('Uploading attachment', [
            'invoice_id' => $id,
            'path' => $path,
            'original_name' => $originalName
        ]);

        $invoice->update([
            'attachment_path' => $path,
            'attachment_original_name' => $originalName,
        ]);

        $invoice->refresh();

        return response()->json([
            'message' => 'Attachment uploaded successfully',
            'invoice' => $invoice,
            'attachment' => [
                'path' => $path,
                'original_name' => $originalName,
                'url' => asset('storage/' . $path),
            ],
        ]);
    }

    public function downloadAttachment($companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)
            ->orWhere('receiver_company_id', $companyId)
            ->findOrFail($id);

        $this->authorize('view', $invoice);

        if (!$invoice->attachment_path) {
            return response()->json(['message' => 'No attachment found'], 404);
        }

        $filePath = storage_path('app/public/' . $invoice->attachment_path);

        if (!file_exists($filePath)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        return response()->download($filePath, $invoice->attachment_original_name);
    }

    public function deleteAttachment($companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)
            ->orWhere('receiver_company_id', $companyId)
            ->findOrFail($id);

        $this->authorize('update', $invoice);

        if ($invoice->attachment_path) {
            \Storage::disk('public')->delete($invoice->attachment_path);
        }

        $invoice->update([
            'attachment_path' => null,
            'attachment_original_name' => null,
        ]);

        return response()->json(['message' => 'Attachment deleted successfully']);
    }

    public function downloadPDF($companyId, $id)
    {
        try {
            Log::info('PDF download requested', ['company_id' => $companyId, 'invoice_id' => $id]);
            
            $invoice = Invoice::with([
                'client' => function($query) { $query->withTrashed(); },
                'supplier' => function($query) { $query->withTrashed(); },
                'items', 
                'issuerCompany', 
                'receiverCompany', 
                'perceptions'
            ])->findOrFail($id);

            // Verificar que la empresa tenga acceso a esta factura
            if ($invoice->issuer_company_id !== $companyId && $invoice->receiver_company_id !== $companyId) {
                return response()->json(['error' => 'No autorizado'], 403);
            }

            Log::info('Invoice loaded, generating PDF', [
                'is_manual_load' => $invoice->is_manual_load ?? false,
                'has_supplier' => $invoice->supplier_id ? true : false,
                'has_client' => $invoice->client_id ? true : false,
                'has_perceptions' => $invoice->perceptions ? $invoice->perceptions->count() : 0
            ]);
            
            // Generar PDF on-demand siempre (por ahora para debug)
            $pdfService = new \App\Services\InvoicePdfService();
            $pdfPath = $pdfService->generatePdf($invoice);
            $filePath = storage_path('app/' . $pdfPath);
            
            Log::info('PDF generated', ['path' => $pdfPath, 'exists' => file_exists($filePath)]);
            
            if (!file_exists($filePath)) {
                throw new \Exception('PDF file not found after generation');
            }
            
            // Update invoice with new path
            $invoice->update(['pdf_url' => $pdfPath]);
            
            return response()->download($filePath, "factura-{$invoice->number}.pdf");
        } catch (\Exception $e) {
            Log::error('Error downloading PDF', [
                'company_id' => $companyId,
                'invoice_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function downloadTXT($companyId, $id)
    {
        try {
            $invoice = Invoice::with([
                'client' => function($query) { $query->withTrashed(); },
                'items', 'issuerCompany', 'receiverCompany'
            ])->findOrFail($id);

            // Generar TXT on-demand siempre (por ahora para debug)
            $pdfService = new \App\Services\InvoicePdfService();
            $txtPath = $pdfService->generateTxt($invoice);
            $filePath = storage_path('app/' . $txtPath);
            
            // Update invoice with new path
            $invoice->update(['afip_txt_url' => $txtPath]);
            
            return response()->download($filePath, "factura-{$invoice->number}.txt");
        } catch (\Exception $e) {
            Log::error('Error downloading TXT', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateSyncedInvoice(UpdateSyncedInvoiceRequest $request, $companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)->findOrFail($id);
        $this->authorize('update', $invoice);

        if (!$invoice->synced_from_afip) {
            return response()->json([
                'message' => 'Solo se pueden editar facturas sincronizadas desde AFIP'
            ], 422);
        }

        $validated = $request->validated();

        // Solo actualizar campos descriptivos
        // Si el concepto es 'products', forzar fechas de servicio a null
        $serviceDateFrom = $validated['concept'] === 'products' ? null : (!empty($validated['service_date_from']) ? $validated['service_date_from'] : null);
        $serviceDateTo = $validated['concept'] === 'products' ? null : (!empty($validated['service_date_to']) ? $validated['service_date_to'] : null);
        
        $invoice->update([
            'concept' => $validated['concept'],
            'service_date_from' => $serviceDateFrom,
            'service_date_to' => $serviceDateTo,
        ]);

        // Actualizar descripciones de items
        foreach ($validated['items'] as $index => $itemData) {
            $item = $invoice->items()->skip($index)->first();
            if ($item) {
                $item->update(['description' => $itemData['description']]);
            }
        }

        return response()->json([
            'message' => 'Factura actualizada correctamente',
            'invoice' => $invoice->load('items'),
        ]);
    }

    public function downloadBulk(DownloadBulkRequest $request, $companyId)
    {
        $validated = $request->validated();

        try {
            $invoices = Invoice::with([
                'client' => function($query) { $query->withTrashed(); },
                'items', 'issuerCompany.address', 'receiverCompany', 'perceptions'
            ])
            ->whereIn('id', $validated['invoice_ids'])
            ->get();

            $pdfService = new \App\Services\InvoicePdfService();
            $zipFileName = 'facturas_' . date('Ymd_His') . '.zip';
            $zipPath = storage_path('app/temp/' . $zipFileName);
            
            if (!file_exists(storage_path('app/temp'))) {
                mkdir(storage_path('app/temp'), 0755, true);
            }

            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \Exception('No se pudo crear el archivo ZIP');
            }

            foreach ($invoices as $invoice) {
                if ($validated['format'] === 'pdf') {
                    $filePath = $pdfService->generatePdf($invoice);
                    $fullPath = storage_path('app/' . $filePath);
                    $zip->addFile($fullPath, "factura-{$invoice->number}.pdf");
                } else {
                    $filePath = $pdfService->generateTxt($invoice);
                    $fullPath = storage_path('app/' . $filePath);
                    $zip->addFile($fullPath, "factura-{$invoice->number}.txt");
                }
            }

            $zip->close();

            return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('Error downloading bulk files', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function generateSimplePDF($invoice)
    {
        // Generar PDF simple con texto plano (sin librería)
        $content = "FACTURA " . $invoice->type . "\n";
        $content .= "Número: " . $invoice->number . "\n";
        $content .= "Fecha: " . $invoice->issue_date->format('d/m/Y') . "\n";
        $content .= "Cliente: " . ($invoice->client->business_name ?? $invoice->client->first_name . ' ' . $invoice->client->last_name) . "\n";
        $content .= "CUIT/DNI: " . $invoice->client->document_number . "\n\n";
        $content .= "ITEMS:\n";
        foreach ($invoice->items as $item) {
            $content .= "- " . $item->description . " x" . $item->quantity . " = $" . number_format($item->subtotal, 2) . "\n";
        }
        $content .= "\nSubtotal: $" . number_format($invoice->subtotal, 2) . "\n";
        $content .= "IVA: $" . number_format($invoice->total_taxes, 2) . "\n";
        $content .= "TOTAL: $" . number_format($invoice->total, 2) . "\n";
        if ($invoice->afip_cae) {
            $content .= "\nCAE: " . $invoice->afip_cae . "\n";
            $content .= "Vto CAE: " . $invoice->afip_cae_due_date->format('d/m/Y') . "\n";
        }
        return $content;
    }

    private function generateTXT($invoice)
    {
        // Formato TXT para AFIP/ARCA
        $lines = [];
        $lines[] = $invoice->number;
        $lines[] = $invoice->type;
        $lines[] = $invoice->issue_date->format('Ymd');
        $lines[] = $invoice->client->document_type . '|' . $invoice->client->document_number;
        $lines[] = number_format($invoice->subtotal, 2, '.', '');
        $lines[] = number_format($invoice->total_taxes, 2, '.', '');
        $lines[] = number_format($invoice->total, 2, '.', '');
        if ($invoice->afip_cae) {
            $lines[] = $invoice->afip_cae;
        }
        return implode("\n", $lines);
    }

    /**
     * Get next available invoice number from AFIP
     */
    public function getNextNumber(GetNextNumberRequest $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        
        $this->authorize('create', [Invoice::class, $company]);

        $validated = $request->validated();

        try {
            $afipService = new AfipInvoiceService($company);
            // Convertir código a tipo interno si es necesario
            $invoiceType = \App\Services\VoucherTypeService::getTypeByCode($validated['invoice_type']) ?? $validated['invoice_type'];
            $invoiceTypeCode = (int) \App\Services\VoucherTypeService::getAfipCode($invoiceType);
            
            $lastNumber = $afipService->getLastAuthorizedInvoice(
                $validated['sales_point'],
                $invoiceTypeCode
            );

            $nextNumber = $lastNumber + 1;

            return response()->json([
                'last_number' => $lastNumber,
                'next_number' => $nextNumber,
                'formatted_number' => sprintf('%04d-%08d', $validated['sales_point'], $nextNumber),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'No se pudo consultar el último número en AFIP',
                'error' => $e->getMessage(),
                'next_number' => 1,
                'formatted_number' => sprintf('%04d-%08d', $validated['sales_point'], 1),
            ], 200);
        }
    }

    private function calculatePerceptionBase(string $type, ?string $baseType, float $subtotal, float $totalTaxes): float
    {
        // If base_type is explicitly provided, use it
        if ($baseType) {
            return match($baseType) {
                'vat' => $totalTaxes,
                'total' => $subtotal + $totalTaxes,
                'net' => $subtotal,
                default => $subtotal,
            };
        }

        // ALL perceptions and retentions apply on NET amount (without IVA) by default
        return $subtotal;
    }

    private function getDefaultBaseType(string $type): string
    {
        // All perceptions default to net (without IVA)
        return 'net';
    }

    // getAuthorizedSalesPoints, findConnectedCompanyByCuit, formatCuitWithHyphens moved to services
    
    /**
     * Get invoices that can be associated with NC/ND for /emit-invoice
     */
    public function getAssociableInvoicesForEmit(GetAssociableInvoicesRequest $request, $companyId)
    {
        $validated = $request->validated();
        
        $invoiceType = \App\Services\VoucherTypeService::getTypeByCode($validated['invoice_type']) ?? $validated['invoice_type'];
        $isNC = in_array($invoiceType, ['NCA', 'NCB', 'NCC', 'NCM', 'NCE']);
        $isND = in_array($invoiceType, ['NDA', 'NDB', 'NDC', 'NDM', 'NDE']);
        
        if (!$isNC && !$isND) {
            return response()->json(['invoices' => []]);
        }
        
        // Get compatible invoice types (e.g., NCA -> ['A'], NCB -> ['B'])
        $voucherTypes = \App\Services\VoucherTypeService::getVoucherTypes();
        $compatibleTypes = $voucherTypes[$invoiceType]['compatible_with'] ?? [];
        
        if (empty($compatibleTypes)) {
            return response()->json(['invoices' => []]);
        }
        
        // Facturas emitidas a través de AFIP (con CAE): incluye emitidas desde el sistema Y sincronizadas de AFIP
        // EXCLUYE facturas manuales sin CAE real (AFIP no permite asociarlas)
        // EXCLUYE facturas donde CUALQUIER empresa (emisor o receptor) ya la marcó como pagada/cobrada
        $invoices = Invoice::where('issuer_company_id', $companyId)
            ->where('afip_status', 'approved')
            ->whereNotNull('afip_cae')
            ->where(function($q) {
                $q->where('is_manual_load', false)
                  ->orWhere('synced_from_afip', true);
            })
            ->whereIn('type', $compatibleTypes)
            ->where('status', '!=', 'cancelled')
            ->where(function($q) use ($companyId) {
                // Excluir si la propia empresa ya la marcó como cobrada
                $q->whereRaw("(company_statuses IS NULL OR JSON_EXTRACT(company_statuses, '$.\"" . $companyId . "\"') IS NULL OR JSON_EXTRACT(company_statuses, '$.\"" . $companyId . "\"') NOT IN ('\"collected\"', '\"paid\"'))")
                  // Excluir si la otra empresa (receptor) ya la marcó como pagada
                  ->whereRaw("(company_statuses IS NULL OR NOT EXISTS (SELECT 1 FROM (SELECT JSON_EXTRACT(company_statuses, CONCAT('$.\"', k, '\"')) as status FROM JSON_TABLE(JSON_KEYS(company_statuses), '$[*]' COLUMNS(k VARCHAR(50) PATH '$')) jk WHERE k != '" . $companyId . "') sub WHERE sub.status IN ('\"paid\"', '\"collected\"')))")
                ;
            })
            ->with(['client', 'receiverCompany', 'collections'])
            ->orderBy('issue_date', 'desc')
            ->get()
            ->map(function($inv) use ($companyId) {
                // Calculate available_balance (balance_pending)
                $availableBalance = $this->calculateAvailableBalance($inv, $companyId);
                
                $origin = $inv->synced_from_afip ? 'Sincronizada AFIP' : 'Emitida desde sistema';
                $statusLabel = match($inv->status) {
                    'issued' => 'Emitida',
                    'approved' => 'Aprobada',
                    'paid' => 'Pagada',
                    'collected' => 'Cobrada',
                    'partially_cancelled' => 'Parcialmente anulada',
                    default => ucfirst($inv->status)
                };
                
                return [
                    'id' => $inv->id,
                    'number' => $inv->number,
                    'type' => $inv->type,
                    'sales_point' => $inv->sales_point,
                    'issue_date' => $inv->issue_date,
                    'total' => $inv->total,
                    'available_balance' => $availableBalance,
                    'balance_pending' => $availableBalance, // Alias for compatibility
                    'currency' => $inv->currency ?? 'ARS',
                    'exchange_rate' => $inv->exchange_rate ?? 1,
                    'concept' => $inv->concept ?? 'products',
                    'service_date_from' => $inv->service_date_from?->format('Y-m-d'),
                    'service_date_to' => $inv->service_date_to?->format('Y-m-d'),
                    'receiver_name' => $inv->receiver_name ?? $inv->receiverCompany?->name ?? $inv->client?->business_name,
                    'status' => $inv->status,
                    'status_label' => $statusLabel,
                    'origin' => $origin,
                    'is_manual_load' => $inv->is_manual_load ?? false,
                    'synced_from_afip' => $inv->synced_from_afip ?? false,
                ];
            });
        
        return response()->json(['invoices' => $invoices]);
    }
    
    /**
     * Get invoices that can be associated with NC/ND for /load-invoice received
     */
    public function getAssociableInvoicesForReceived(GetAssociableInvoicesRequest $request, $companyId)
    {
        $validated = $request->validated();
        
        $invoiceType = \App\Services\VoucherTypeService::getTypeByCode($validated['invoice_type']) ?? $validated['invoice_type'];
        $isNC = in_array($invoiceType, ['NCA', 'NCB', 'NCC', 'NCM', 'NCE']);
        $isND = in_array($invoiceType, ['NDA', 'NDB', 'NDC', 'NDM', 'NDE']);
        
        if (!$isNC && !$isND) {
            return response()->json(['invoices' => []]);
        }
        
        // Get compatible invoice types (e.g., NCA -> ['A'], NCB -> ['B'])
        $voucherTypes = \App\Services\VoucherTypeService::getVoucherTypes();
        $compatibleTypes = $voucherTypes[$invoiceType]['compatible_with'] ?? [];
        
        if (empty($compatibleTypes)) {
            return response()->json(['invoices' => []]);
        }
        
        // Facturas recibidas de ese mismo proveedor
        // EXCLUYE facturas donde CUALQUIER empresa (emisor o receptor) ya la marcó como pagada/cobrada
        $query = Invoice::where('receiver_company_id', $companyId)
            ->whereIn('type', $compatibleTypes) // Filter by compatible types
            ->where('status', '!=', 'cancelled')
            ->where(function($q) use ($companyId) {
                // Excluir si la propia empresa ya la marcó como pagada
                $q->whereRaw("(company_statuses IS NULL OR JSON_EXTRACT(company_statuses, '$.\"" . $companyId . "\"') IS NULL OR JSON_EXTRACT(company_statuses, '$.\"" . $companyId . "\"') NOT IN ('\"paid\"', '\"collected\"'))")
                  // Excluir si la otra empresa (emisor) ya la marcó como cobrada
                  ->whereRaw("(company_statuses IS NULL OR NOT EXISTS (SELECT 1 FROM (SELECT JSON_EXTRACT(company_statuses, CONCAT('$.\"', k, '\"')) as status FROM JSON_TABLE(JSON_KEYS(company_statuses), '$[*]' COLUMNS(k VARCHAR(50) PATH '$')) jk WHERE k != '" . $companyId . "') sub WHERE sub.status IN ('\"paid\"', '\"collected\"')))")
                ;
            });
        
        if (!empty($validated['supplier_id'])) {
            $query->where('supplier_id', $validated['supplier_id']);
        } elseif (!empty($validated['issuer_document'])) {
            $query->where('issuer_document', $validated['issuer_document']);
        }
        
        $invoices = $query->with(['supplier'])
            ->orderBy('issue_date', 'desc')
            ->get()
            ->map(function($inv) use ($companyId) {
                // Calculate available_balance (balance_pending)
                $availableBalance = $this->calculateAvailableBalance($inv, $companyId);
                
                $origin = $inv->is_manual_load ? 'Cargada manualmente' : 'Recibida automática';
                $statusLabel = match($inv->status) {
                    'pending_approval' => 'Pendiente aprobación',
                    'approved' => 'Aprobada',
                    'paid' => 'Pagada',
                    'collected' => 'Cobrada',
                    'partially_cancelled' => 'Parcialmente anulada',
                    default => ucfirst($inv->status)
                };
                
                return [
                    'id' => $inv->id,
                    'number' => $inv->number,
                    'type' => $inv->type,
                    'sales_point' => $inv->sales_point,
                    'issue_date' => $inv->issue_date,
                    'total' => $inv->total,
                    'available_balance' => $availableBalance,
                    'balance_pending' => $availableBalance, // Alias for compatibility
                    'currency' => $inv->currency ?? 'ARS',
                    'exchange_rate' => $inv->exchange_rate ?? 1,
                    'concept' => $inv->concept ?? 'products',
                    'service_date_from' => $inv->service_date_from?->format('Y-m-d'),
                    'service_date_to' => $inv->service_date_to?->format('Y-m-d'),
                    'issuer_name' => $inv->issuer_name ?? $inv->supplier?->business_name,
                    'status' => $inv->status,
                    'status_label' => $statusLabel,
                    'origin' => $origin,
                    'is_manual_load' => $inv->is_manual_load ?? false,
                    'synced_from_afip' => $inv->synced_from_afip ?? false,
                ];
            });
        
        return response()->json(['invoices' => $invoices]);
    }
    
    /**
     * Get invoices that can be associated with NC/ND for /load-invoice issued
     */
    public function getAssociableInvoicesForIssued(GetAssociableInvoicesRequest $request, $companyId)
    {
        $validated = $request->validated();
        
        $invoiceType = \App\Services\VoucherTypeService::getTypeByCode($validated['invoice_type']) ?? $validated['invoice_type'];
        $isNC = in_array($invoiceType, ['NCA', 'NCB', 'NCC', 'NCM', 'NCE']);
        $isND = in_array($invoiceType, ['NDA', 'NDB', 'NDC', 'NDM', 'NDE']);
        
        if (!$isNC && !$isND) {
            return response()->json(['invoices' => []]);
        }
        
        // Get compatible invoice types (e.g., NCA -> ['A'], NCB -> ['B'])
        $voucherTypes = \App\Services\VoucherTypeService::getVoucherTypes();
        $compatibleTypes = $voucherTypes[$invoiceType]['compatible_with'] ?? [];
        
        if (empty($compatibleTypes)) {
            return response()->json(['invoices' => []]);
        }
        
        // Facturas emitidas manualmente hacia ese mismo cliente
        // EXCLUYE facturas donde CUALQUIER empresa (emisor o receptor) ya la marcó como pagada/cobrada
        $query = Invoice::where('issuer_company_id', $companyId)
            ->where('is_manual_load', true)
            ->whereIn('type', $compatibleTypes) // Filter by compatible types
            ->where('status', '!=', 'cancelled')
            ->where(function($q) use ($companyId) {
                // Excluir si la propia empresa ya la marcó como cobrada
                $q->whereRaw("(company_statuses IS NULL OR JSON_EXTRACT(company_statuses, '$.\"" . $companyId . "\"') IS NULL OR JSON_EXTRACT(company_statuses, '$.\"" . $companyId . "\"') NOT IN ('\"collected\"', '\"paid\"'))")
                  // Excluir si la otra empresa (receptor) ya la marcó como pagada
                  ->whereRaw("(company_statuses IS NULL OR NOT EXISTS (SELECT 1 FROM (SELECT JSON_EXTRACT(company_statuses, CONCAT('$.\"', k, '\"')) as status FROM JSON_TABLE(JSON_KEYS(company_statuses), '$[*]' COLUMNS(k VARCHAR(50) PATH '$')) jk WHERE k != '" . $companyId . "') sub WHERE sub.status IN ('\"paid\"', '\"collected\"')))")
                ;
            });
        
        if (!empty($validated['client_id'])) {
            $query->where('client_id', $validated['client_id']);
        } elseif (!empty($validated['receiver_document'])) {
            $query->where('receiver_document', $validated['receiver_document']);
        }
        
        $invoices = $query->with(['client'])
            ->orderBy('issue_date', 'desc')
            ->get()
            ->map(function($inv) use ($companyId) {
                // Calculate available_balance (balance_pending)
                $availableBalance = $this->calculateAvailableBalance($inv, $companyId);
                
                $origin = 'Cargada manualmente';
                $statusLabel = match($inv->status) {
                    'issued' => 'Emitida',
                    'approved' => 'Aprobada',
                    'paid' => 'Pagada',
                    'collected' => 'Cobrada',
                    'partially_cancelled' => 'Parcialmente anulada',
                    default => ucfirst($inv->status)
                };
                
                return [
                    'id' => $inv->id,
                    'number' => $inv->number,
                    'type' => $inv->type,
                    'sales_point' => $inv->sales_point,
                    'issue_date' => $inv->issue_date,
                    'total' => $inv->total,
                    'available_balance' => $availableBalance,
                    'balance_pending' => $availableBalance, // Alias for compatibility
                    'currency' => $inv->currency ?? 'ARS',
                    'exchange_rate' => $inv->exchange_rate ?? '1',
                    'concept' => $inv->concept,
                    'service_date_from' => $inv->service_date_from,
                    'service_date_to' => $inv->service_date_to,
                    'receiver_name' => $inv->receiver_name ?? $inv->client?->business_name,
                    'status' => $inv->status,
                    'status_label' => $statusLabel,
                    'origin' => $origin,
                    'is_manual_load' => $inv->is_manual_load ?? false,
                    'synced_from_afip' => $inv->synced_from_afip ?? false,
                ];
            });
        
        return response()->json(['invoices' => $invoices]);
    }

    /**
     * Calculate available balance for an invoice (considering only NC/ND)
     * Los pagos/cobros NO deben afectar el balance - solo ND/NC
     */
    private function calculateAvailableBalance(Invoice $invoice, string $companyId): float
    {
        // Always recalculate to ensure accuracy (balance_pending might be stale)
        // Calculate total NC (credit notes) associated with this invoice
        $totalNC = Invoice::where('related_invoice_id', $invoice->id)
            ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
            ->where('status', '!=', 'cancelled')
            ->where('afip_status', 'approved')
            ->sum('total');
        
        // Calculate total ND (debit notes) associated with this invoice
        $totalND = Invoice::where('related_invoice_id', $invoice->id)
            ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
            ->where('status', '!=', 'cancelled')
            ->where('afip_status', 'approved')
            ->sum('total');
        
        // Balance = Total + ND - NC (SIN incluir pagos/cobros)
        $balance = ($invoice->total ?? 0) + $totalND - $totalNC;
        
        return round(max(0, $balance), 2); // Ensure non-negative
    }

    /**
     * Update related invoice balance when a NC/ND is created
     * Los pagos/cobros NO deben afectar el balance - solo ND/NC
     */
    private function updateRelatedInvoiceBalance(string $relatedInvoiceId): void
    {
        $relatedInvoice = Invoice::find($relatedInvoiceId);
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
        
        Log::info('Recalculated invoice balance (manual load)', [
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
            
            Log::info('Invoice automatically cancelled by manual NC/ND', [
                'invoice_id' => $relatedInvoice->id,
                'invoice_number' => $relatedInvoice->number,
            ]);
        } else if ($relatedInvoice->balance_pending < $relatedInvoice->total) {
            // Anulación parcial
            $relatedInvoice->status = 'partially_cancelled';
            
            Log::info('Invoice partially cancelled by manual NC/ND', [
                'invoice_id' => $relatedInvoice->id,
                'invoice_number' => $relatedInvoice->number,
                'new_balance' => $relatedInvoice->balance_pending,
            ]);
        }
        
        $relatedInvoice->save();
    }
}
