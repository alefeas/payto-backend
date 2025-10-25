<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Afip\AfipInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class InvoiceController extends Controller
{
    use AuthorizesRequests;
    
    /**
     * Normalizar CUIT: remover guiones para comparación
     * AFIP devuelve: 20123456789
     * Sistema guarda: 20-12345678-9
     */
    private function normalizeCuit(string $cuit): string
    {
        return str_replace('-', '', $cuit);
    }
    public function index(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        
        $this->authorize('viewAny', [Invoice::class, $company]);

        $status = $request->query('status');
        $search = $request->query('search');
        $type = $request->query('type');
        $client = $request->query('client');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        
        // Solo facturas emitidas por esta empresa O recibidas por esta empresa
        $query = Invoice::where('issuer_company_id', $companyId)
            ->orWhere('receiver_company_id', $companyId)
            ->with(['client', 'supplier', 'items', 'issuerCompany', 'receiverCompany', 'approvals.user', 'relatedInvoice', 'payments', 'collections']);

        if ($status && $status !== 'all') {
            if ($status === 'overdue') {
                $query->whereDate('due_date', '<', now())
                      ->whereNotIn('status', ['paid', 'collected', 'cancelled']);
            } elseif ($status === 'collected') {
                $query->where('issuer_company_id', $companyId)
                      ->where('status', 'collected');
            } elseif ($status === 'paid') {
                $query->where('receiver_company_id', $companyId)
                      ->where('status', 'paid');
            } elseif (in_array($status, ['pending_approval', 'approved', 'rejected'])) {
                // Para estados de aprobación, filtrar por company_statuses JSON
                $query->where(function($q) use ($status, $companyId) {
                    $q->whereRaw("JSON_EXTRACT(company_statuses, '$.\"{$companyId}\"') = ?", [$status])
                      ->orWhere(function($q2) use ($status, $companyId) {
                          // Fallback: si no existe en JSON, usar lógica anterior
                          $q2->whereRaw("JSON_EXTRACT(company_statuses, '$.\"{$companyId}\"') IS NULL")
                             ->where('receiver_company_id', $companyId)
                             ->where('status', $status);
                      });
                });
            } else {
                $query->where('status', $status);
            }
        }
        
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('number', 'like', '%' . $search . '%')
                  ->orWhere('receiver_name', 'like', '%' . $search . '%')
                  ->orWhereHas('client', function($q) use ($search) {
                      $q->where('business_name', 'like', '%' . $search . '%')
                        ->orWhere('first_name', 'like', '%' . $search . '%')
                        ->orWhere('last_name', 'like', '%' . $search . '%');
                  });
            });
        }
        
        if ($type && $type !== 'all') {
            $query->where('type', $type);
        }
        
        if ($client && $client !== 'all') {
            $query->where(function($q) use ($client) {
                $q->where('receiver_name', $client)
                  ->orWhereHas('client', function($q) use ($client) {
                      $q->where('business_name', $client)
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) = ?", [$client]);
                  });
            });
        }
        
        if ($dateFrom) {
            $query->whereDate('issue_date', '>=', $dateFrom);
        }
        
        if ($dateTo) {
            $query->whereDate('issue_date', '<=', $dateTo);
        }

        $invoices = $query->orderBy('created_at', 'desc')
            ->paginate(20);
        
        // Override approvals_required with current company setting and format approvals
        $invoices->getCollection()->transform(function ($invoice) use ($company, $companyId) {

            $invoice->direction = $invoice->issuer_company_id === $companyId ? 'issued' : 'received';
            
            // Usar estados por empresa desde JSON
            $companyStatuses = $invoice->company_statuses ?: [];
            $companyIdInt = (int)$companyId;
            
            if (isset($companyStatuses[$companyIdInt])) {
                $invoice->display_status = $companyStatuses[$companyIdInt];
            } else {
                // Fallback al status global
                if ($invoice->direction === 'issued') {
                    $invoice->display_status = $invoice->status;
                } else {
                    $invoice->display_status = $invoice->status === 'issued' 
                        ? ($company->required_approvals > 0 ? 'pending_approval' : 'approved')
                        : $invoice->status;
                }
            }
            
            // Calcular payment_status y pending_amount según dirección
            $paidAmount = 0;
            if ($invoice->direction === 'issued') {
                // Para facturas emitidas, usar collections
                $paidAmount = $invoice->collections->where('company_id', $companyId)->where('status', 'confirmed')->sum('amount');
            } else {
                // Para facturas recibidas, usar payments
                $paidAmount = \DB::table('invoice_payments_tracking')
                    ->where('invoice_id', $invoice->id)
                    ->where('company_id', $companyId)
                    ->whereIn('status', ['confirmed', 'in_process'])
                    ->sum('amount');
            }
            
            $total = $invoice->total ?? 0;
            $invoice->paid_amount = $paidAmount;
            $invoice->pending_amount = $total - $paidAmount;
            
            if ($paidAmount >= $total) {
                $invoice->payment_status = 'paid';
            } elseif ($paidAmount > 0) {
                $invoice->payment_status = 'partial';
            } else {
                $invoice->payment_status = 'pending';
            }
            
            // Solo override approvals_required si la empresa es la receptora
            if ($invoice->receiver_company_id === $companyId) {
                $invoice->approvals_required = $company->required_approvals;
            }
            
            // Solo agregar receiver_name y receiver_document si no están ya guardados
            if (!$invoice->receiver_name || !$invoice->receiver_document) {
                if ($invoice->receiverCompany) {
                    $invoice->receiver_name = $invoice->receiverCompany->name;
                    $invoice->receiver_document = $invoice->receiverCompany->national_id;
                } elseif ($invoice->client) {
                    $invoice->receiver_name = $invoice->client->business_name 
                        ?? trim($invoice->client->first_name . ' ' . $invoice->client->last_name)
                        ?: null;
                    $invoice->receiver_document = $invoice->client->document_number;
                }
            }
            
            // Format approvals for frontend
            if ($invoice->approvals && $invoice->approvals->count() > 0) {
                $invoice->approvals = $invoice->approvals->map(function ($approval) {
                    $fullName = trim($approval->user->first_name . ' ' . $approval->user->last_name);
                    return [
                        'id' => $approval->id,
                        'user' => [
                            'id' => $approval->user->id,
                            'name' => $fullName ?: $approval->user->email,
                            'first_name' => $approval->user->first_name,
                            'last_name' => $approval->user->last_name,
                            'email' => $approval->user->email,
                        ],
                        'notes' => $approval->notes,
                        'approved_at' => $approval->approved_at->toIso8601String(),
                    ];
                })->values();
            } else {
                $invoice->approvals = [];
            }
            
            return $invoice;
        });

        return response()->json($invoices);
    }

    public function store(Request $request, $companyId)
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

        // Obtener mensajes de validación desde configuración
        $validationMessages = array_merge(
            config('afip_rules.validation_messages', []),
            [
                'items.*.quantity.max' => 'La cantidad no puede superar las 999,999 unidades. Si necesitás facturar más, dividí en múltiples ítems.',
                'items.*.unit_price.max' => 'El precio unitario no puede superar $999,999,999. Si necesitás facturar montos mayores, dividí en múltiples ítems.',
            ]
        );

        $validated = $request->validate([
            'client_id' => 'required_without_all:client_data,receiver_company_id|exists:clients,id',
            'receiver_company_id' => 'nullable|exists:companies,id',
            'client_data' => 'required_without_all:client_id,receiver_company_id|array',
            'client_data.document_type' => 'required_with:client_data|in:CUIT,CUIL,DNI,Pasaporte,CDI',
            'client_data.document_number' => 'required_with:client_data|string',
            'client_data.business_name' => 'nullable|string',
            'client_data.first_name' => 'nullable|string',
            'client_data.last_name' => 'nullable|string',
            'client_data.email' => 'nullable|email',
            'client_data.tax_condition' => 'required_with:client_data|in:registered_taxpayer,monotax,exempt,final_consumer',
            'save_client' => 'boolean',
            'invoice_type' => 'required|string',
            'sales_point' => 'required|integer|min:1|max:9999',
            'concept' => 'required|in:products,services,products_services',
            'service_date_from' => 'nullable|date',
            'service_date_to' => 'nullable|date|after_or_equal:service_date_from',
            'issue_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:issue_date',
            'currency' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01|max:999999',
            'items.*.unit_price' => 'required|numeric|min:0|max:999999999',
            'items.*.discount_percentage' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'perceptions' => 'nullable|array',
            'perceptions.*.type' => 'required|string|max:100',
            'perceptions.*.name' => 'required|string|max:100',
            'perceptions.*.rate' => 'nullable|numeric|min:0|max:100',
            'perceptions.*.amount' => 'nullable|numeric|min:0',
            'perceptions.*.jurisdiction' => 'nullable|string|max:100',
            'perceptions.*.base_type' => 'nullable|in:net,total,vat',
        ], $validationMessages);

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
                $itemTax = $itemSubtotal * ($taxRate / 100);
                
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
                $receiverCompany = Company::findOrFail($receiverCompanyId);
                $receiverName = $receiverCompany->name;
                $receiverDocument = $receiverCompany->national_id;
            } elseif ($clientId) {
                $client = \App\Models\Client::findOrFail($clientId);
                $receiverName = $client->business_name 
                    ?? trim($client->first_name . ' ' . $client->last_name)
                    ?: null;
                $receiverDocument = $client->document_number;
            }
            
            // Crear factura para el EMISOR solamente
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
                $itemTax = $itemSubtotal * ($taxRate / 100);

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
        
        $invoice = Invoice::where(function($q) use ($companyId) {
                $q->where('issuer_company_id', $companyId)
                  ->orWhere('receiver_company_id', $companyId);
            })
            ->with(['client', 'items', 'receiverCompany', 'issuerCompany'])
            ->findOrFail($id);

        $this->authorize('view', $invoice);
        
        $invoice->direction = $invoice->issuer_company_id === (int)$companyId ? 'issued' : 'received';
        if ($invoice->direction === 'received' && $invoice->status === 'issued') {
            $invoice->display_status = $company->required_approvals > 0 ? 'pending_approval' : 'approved';
        } else {
            $invoice->display_status = $invoice->status;
        }
        
        if (!$invoice->receiver_name || !$invoice->receiver_document) {
            if ($invoice->receiverCompany) {
                $invoice->receiver_name = $invoice->receiverCompany->name;
                $invoice->receiver_document = $invoice->receiverCompany->national_id;
            } elseif ($invoice->client) {
                $invoice->receiver_name = $invoice->client->business_name 
                    ?? trim($invoice->client->first_name . ' ' . $invoice->client->last_name)
                    ?: null;
                $invoice->receiver_document = $invoice->client->document_number;
            }
        }

        return response()->json($invoice);
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

    public function validateWithAfip(Request $request, $companyId)
    {
        $company = Company::with('afipCertificate')->findOrFail($companyId);
        
        $this->authorize('create', [Invoice::class, $company]);

        $validated = $request->validate([
            'issuer_cuit' => 'required|string',
            'invoice_type' => 'required|in:A,B,C,E',
            'invoice_number' => 'required|string|regex:/^\d{4}-\d{8}$/',
        ]);

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

    public function syncFromAfip(Request $request, $companyId)
    {
        $company = Company::with('afipCertificate')->findOrFail($companyId);
        
        $this->authorize('create', [Invoice::class, $company]);

        $validated = $request->validate([
            'mode' => 'required|in:single,date_range',
            'sales_point' => 'required_if:mode,single|integer|min:1|max:9999',
            'invoice_type' => 'required_if:mode,single|string',
            'invoice_number' => 'required_if:mode,single|integer|min:1',
            'date_from' => 'required_if:mode,date_range|date',
            'date_to' => 'required_if:mode,date_range|date|after_or_equal:date_from',
        ]);

        if (!$company->afipCertificate || !$company->afipCertificate->is_active) {
            return response()->json([
                'success' => false,
                'error' => 'No active AFIP certificate found for this company',
            ], 403);
        }

        try {
            $afipService = new AfipInvoiceService($company);
            
            if ($validated['mode'] === 'single') {
                return $this->syncSingleInvoice($company, $afipService, $validated);
            } else {
                return $this->syncByDateRange($company, $afipService, $validated);
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

    private function syncSingleInvoice($company, $afipService, $validated)
    {
        $invoiceType = \App\Services\VoucherTypeService::getTypeByCode($validated['invoice_type']) ?? $validated['invoice_type'];
        $invoiceTypeCode = (int) \App\Services\VoucherTypeService::getAfipCode($invoiceType);
        
        try {
            $afipData = $afipService->consultInvoice(
                $company->national_id,
                $invoiceTypeCode,
                $validated['sales_point'],
                $validated['invoice_number']
            );

            if ($afipData['found']) {
                DB::beginTransaction();
                
                $formattedNumber = sprintf('%04d-%08d', $validated['sales_point'], $validated['invoice_number']);
                
                // Verificar si ya existe (por company + type + sales_point + voucher_number)
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
                    // Buscar receptor por CUIT - PRIORIZAR empresas conectadas
                    $receiverCompanyId = null;
                    $clientId = null;
                    $receiverName = null;
                    $receiverDocument = null;
                    
                    if (isset($afipData['doc_number']) && $afipData['doc_number'] != '0') {
                        $receiverDocument = $afipData['doc_number'];
                        
                        // 1. Buscar empresa conectada PRIMERO (normalizar CUIT sin guiones)
                        $normalizedCuit = $this->normalizeCuit($afipData['doc_number']);
                        $receiverCompany = Company::whereRaw('REPLACE(national_id, "-", "") = ?', [$normalizedCuit])->first();
                        
                        if ($receiverCompany) {
                            // Empresa conectada encontrada - usar receiver_company_id
                            $receiverCompanyId = $receiverCompany->id;
                            $receiverName = $receiverCompany->name;
                            // NO usar client_id cuando hay empresa conectada
                        } else {
                            // 2. No hay empresa conectada - buscar cliente externo (normalizar CUIT)
                            $client = \App\Models\Client::where('company_id', $company->id)
                                ->whereRaw('REPLACE(document_number, "-", "") = ?', [$normalizedCuit])
                                ->first();
                            
                            if (!$client) {
                                $client = \App\Models\Client::create([
                                    'company_id' => $company->id,
                                    'document_type' => 'CUIT',
                                    'document_number' => $afipData['doc_number'],
                                    'business_name' => 'Cliente AFIP - ' . $afipData['doc_number'],
                                    'tax_condition' => 'monotax',
                                    'address' => null, // Domicilio fiscal no disponible desde AFIP
                                    'created_by' => auth()->id(),
                                ]);
                            }
                            
                            $clientId = $client->id;
                            $receiverName = $client->business_name ?? trim($client->first_name . ' ' . $client->last_name);
                        }
                    }
                    
                    $invoice = Invoice::create([
                        'number' => $formattedNumber,
                        'type' => $invoiceType,
                        'sales_point' => $validated['sales_point'],
                        'voucher_number' => $validated['invoice_number'],
                        'concept' => 'products',
                        'issuer_company_id' => $company->id,
                        'receiver_company_id' => $receiverCompanyId,
                        'client_id' => $clientId,
                        'issue_date' => $afipData['issue_date'],
                        'due_date' => \Carbon\Carbon::parse($afipData['issue_date'])->addDays(30)->format('Y-m-d'),
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
                        'receiver_name' => $receiverName,
                        'receiver_document' => $receiverDocument,
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
                
                return response()->json([
                    'success' => true,
                    'imported_count' => 1,
                    'invoices' => [[
                        'sales_point' => $validated['sales_point'],
                        'type' => $validated['invoice_type'],
                        'number' => $validated['invoice_number'],
                        'formatted_number' => $formattedNumber,
                        'data' => $afipData,
                        'saved' => !$exists,
                    ]],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Factura no encontrada en AFIP',
                ], 404);
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
            
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar factura',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function syncByDateRange($company, $afipService, $validated)
    {
        try {
            set_time_limit(600);
            ini_set('max_execution_time', 600);

            $dateFrom = \Carbon\Carbon::parse($validated['date_from'])->startOfDay();
            $dateTo = \Carbon\Carbon::parse($validated['date_to'])->endOfDay();

            if ($dateFrom->diffInDays($dateTo) > 90) {
                return response()->json([
                    'success' => false,
                    'message' => 'El rango de fechas no puede superar los 90 días',
                ], 422);
            }

            $voucherTypes = ['A', 'B', 'C', 'M', 'NCA', 'NCB', 'NCC', 'NCM', 'NDA', 'NDB', 'NDC', 'NDM'];
            $salesPoints = $this->getAuthorizedSalesPoints($company);

            if (empty($salesPoints)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontraron puntos de venta autorizados',
                ], 422);
            }

            $imported = [];
            $summary = [];

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

                        $consecutiveOld = 0;
                        $maxConsecutiveOld = 20; // Stop after 20 consecutive invoices before date range

                        for ($num = $lastAfipNumber; $num > 0; $num--) {
                            if ($consecutiveOld >= $maxConsecutiveOld) break;
                            try {
                                $afipData = $afipService->consultInvoice($company->national_id, $invoiceTypeCode, $salesPoint, $num);
                                
                                if (!$afipData['found']) continue;

                                $issueDate = \Carbon\Carbon::parse($afipData['issue_date']);

                                // If invoice is older than range, increment counter
                                if ($issueDate->lt($dateFrom)) {
                                    $consecutiveOld++;
                                    continue;
                                }

                                // Reset counter if we find invoice in range
                                $consecutiveOld = 0;

                                // Skip if invoice is after date range
                                if ($issueDate->gt($dateTo)) continue;

                                $formattedNumber = sprintf('%04d-%08d', $salesPoint, $num);

                                $key = "{$invoiceType}-{$salesPoint}-{$num}";
                                $exists = isset($existingInvoices[$key]);

                                if (!$exists) {
                                    $receiverCompanyId = null;
                                    $clientId = null;
                                    $receiverName = null;
                                    $receiverDocument = null;
                                    
                                    if (isset($afipData['doc_number']) && $afipData['doc_number'] !== '0') {
                                        $receiverDocument = $afipData['doc_number'];
                                        $normalizedCuit = $this->normalizeCuit($afipData['doc_number']);
                                        
                                        // 1. PRIORIZAR empresa conectada
                                        $receiverCompany = Company::whereRaw('REPLACE(national_id, "-", "") = ?', [$normalizedCuit])->first();
                                        
                                        if ($receiverCompany) {
                                            $receiverCompanyId = $receiverCompany->id;
                                            $receiverName = $receiverCompany->name;
                                        } else {
                                            // 2. Buscar cliente externo
                                            $client = \App\Models\Client::where('company_id', $company->id)
                                                ->whereRaw('REPLACE(document_number, "-", "") = ?', [$normalizedCuit])
                                                ->first();
                                            
                                            if (!$client) {
                                                $client = \App\Models\Client::create([
                                                    'company_id' => $company->id,
                                                    'document_number' => $afipData['doc_number'],
                                                    'business_name' => 'Cliente AFIP - ' . $afipData['doc_number'],
                                                    'document_type' => 'CUIT',
                                                    'tax_condition' => 'monotax',
                                                    'address' => null, // Domicilio fiscal no disponible desde AFIP
                                                    'created_by' => auth()->id(),
                                                ]);
                                            }
                                            
                                            $clientId = $client->id;
                                            $receiverName = $client->business_name;
                                        }
                                    }

                                    $invoice = Invoice::create([
                                        'number' => $formattedNumber,
                                        'type' => $invoiceType,
                                        'sales_point' => $salesPoint,
                                        'voucher_number' => $num,
                                        'concept' => 'products',
                                        'issuer_company_id' => $company->id,
                                        'receiver_company_id' => $receiverCompanyId,
                                        'client_id' => $clientId,
                                        'issue_date' => $afipData['issue_date'],
                                        'due_date' => \Carbon\Carbon::parse($afipData['issue_date'])->addDays(30),
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
                                        'receiver_name' => $receiverName,
                                        'receiver_document' => $receiverDocument,
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

            return response()->json([
                'success' => true,
                'imported_count' => count($imported),
                'summary' => $summary,
                'invoices' => $imported,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
            ]);
        } catch (\Exception $e) {
            \Log::error('Sync by date range failed completely', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al sincronizar: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function getAfipInvoiceTypeCode(string $type): int
    {
        $types = ['A' => 1, 'B' => 6, 'C' => 11, 'E' => 19];
        return $types[$type] ?? 6;
    }

    public function storeReceived(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'invoice_type' => 'required|in:A,B,C,E',
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
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
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

        $invoice->update([
            'attachment_path' => $path,
            'attachment_original_name' => $originalName,
        ]);

        return response()->json([
            'message' => 'Attachment uploaded successfully',
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
            $invoice = Invoice::with(['client', 'items', 'issuerCompany.address', 'receiverCompany', 'perceptions'])->findOrFail($id);

            // Generar PDF on-demand siempre (por ahora para debug)
            $pdfService = new \App\Services\InvoicePdfService();
            $pdfPath = $pdfService->generatePdf($invoice);
            $filePath = storage_path('app/' . $pdfPath);
            
            // Update invoice with new path
            $invoice->update(['pdf_url' => $pdfPath]);
            
            return response()->download($filePath, "factura-{$invoice->number}.pdf");
        } catch (\Exception $e) {
            Log::error('Error downloading PDF', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function downloadTXT($companyId, $id)
    {
        try {
            $invoice = Invoice::with(['client', 'items', 'issuerCompany', 'receiverCompany'])->findOrFail($id);

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

    public function downloadBulk(Request $request, $companyId)
    {
        $validated = $request->validate([
            'invoice_ids' => 'required|array|min:1|max:50',
            'invoice_ids.*' => 'required|exists:invoices,id',
            'format' => 'required|in:pdf,txt',
        ]);

        try {
            $invoices = Invoice::with(['client', 'items', 'issuerCompany.address', 'receiverCompany', 'perceptions'])
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
    public function getNextNumber(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        
        $this->authorize('create', [Invoice::class, $company]);

        $validated = $request->validate([
            'sales_point' => 'required|integer|min:1|max:9999',
            'invoice_type' => 'required|string',
        ]);

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

    private function getAuthorizedSalesPoints($company): array
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
