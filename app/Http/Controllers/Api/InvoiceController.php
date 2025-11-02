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
        $query = Invoice::where(function($q) use ($companyId) {
                $q->where('issuer_company_id', $companyId)
                  ->orWhere('receiver_company_id', $companyId);
            })
            ->with([
                'client' => function($query) { $query->withTrashed(); },
                'supplier' => function($query) { $query->withTrashed(); },
                'items', 'issuerCompany', 'receiverCompany', 'approvals.user', 'relatedInvoice', 'payments', 'collections'
            ]);

        if ($status && $status !== 'all') {
            if ($status === 'overdue') {
                // Vencidas: fecha vencida Y sin pagos/cobranzas Y no rechazadas
                $query->whereDate('due_date', '<', now())
                      ->whereNotIn('status', ['cancelled'])
                      ->whereRaw("(company_statuses IS NULL OR JSON_SEARCH(company_statuses, 'one', 'rejected') IS NULL)")
                      ->whereDoesntHave('collections', function($q) use ($companyId) {
                          $q->where('company_id', $companyId)
                            ->where('status', 'confirmed');
                      })
                      ->whereNotExists(function($q) use ($companyId) {
                          $q->select(DB::raw(1))
                            ->from('invoice_payments_tracking')
                            ->whereColumn('invoice_payments_tracking.invoice_id', 'invoices.id')
                            ->where('invoice_payments_tracking.company_id', $companyId)
                            ->whereIn('invoice_payments_tracking.status', ['confirmed', 'in_process']);
                      });
            } elseif ($status === 'collected') {
                // Facturas emitidas con collections confirmadas que cubren el total (excluir anuladas)
                $query->where('issuer_company_id', $companyId)
                      ->where('status', '!=', 'cancelled')
                      ->whereHas('collections', function($q) use ($companyId) {
                          $q->where('company_id', $companyId)
                            ->where('status', 'confirmed');
                      });
            } elseif ($status === 'paid') {
                // Facturas recibidas con payments confirmados que cubren el total (excluir anuladas)
                $query->where('receiver_company_id', $companyId)
                      ->where('status', '!=', 'cancelled')
                      ->whereExists(function($q) use ($companyId) {
                          $q->select(DB::raw(1))
                            ->from('invoice_payments_tracking')
                            ->whereColumn('invoice_payments_tracking.invoice_id', 'invoices.id')
                            ->where('invoice_payments_tracking.company_id', $companyId)
                            ->whereIn('invoice_payments_tracking.status', ['confirmed', 'in_process']);
                      });
            } elseif ($status === 'approved') {
                // Aprobadas: tienen 'approved' en company_statuses Y no están pagadas/cobradas
                $query->whereRaw("JSON_SEARCH(company_statuses, 'one', 'approved') IS NOT NULL")
                      ->whereRaw("JSON_SEARCH(company_statuses, 'one', 'paid') IS NULL")
                      ->whereDoesntHave('collections', function($q) use ($companyId) {
                          $q->where('company_id', $companyId)
                            ->where('status', 'confirmed');
                      })
                      ->whereNotExists(function($q) use ($companyId) {
                          $q->select(DB::raw(1))
                            ->from('invoice_payments_tracking')
                            ->whereColumn('invoice_payments_tracking.invoice_id', 'invoices.id')
                            ->where('invoice_payments_tracking.company_id', $companyId)
                            ->whereIn('invoice_payments_tracking.status', ['confirmed', 'in_process']);
                      });
            } elseif ($status === 'rejected') {
                // Buscar en company_statuses por cualquier clave que tenga 'rejected'
                $query->whereRaw("JSON_SEARCH(company_statuses, 'one', 'rejected') IS NOT NULL");
            } elseif ($status === 'pending_approval') {
                $query->where('status', 'pending_approval')
                      ->where(function($q) use ($companyId) {
                          $q->whereNull('company_statuses')
                            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(company_statuses, '$.\"" . $companyId . "\"')) = 'pending_approval'");
                      });
            } elseif ($status === 'issued') {
                // Facturas emitidas sin pagos/cobranzas
                $query->where('status', 'issued')
                      ->whereDoesntHave('collections', function($q) use ($companyId) {
                          $q->where('company_id', $companyId)
                            ->where('status', 'confirmed');
                      })
                      ->whereNotExists(function($q) use ($companyId) {
                          $q->select(DB::raw(1))
                            ->from('invoice_payments_tracking')
                            ->whereColumn('invoice_payments_tracking.invoice_id', 'invoices.id')
                            ->where('invoice_payments_tracking.company_id', $companyId)
                            ->whereIn('invoice_payments_tracking.status', ['confirmed', 'in_process']);
                      });
            } elseif ($status === 'partially_cancelled') {
                // Parcialmente anuladas: tienen NC/ND pero NO están totalmente cobradas/pagadas
                // Usar balance_pending para determinar si aún hay saldo pendiente
                $query->where('status', 'partially_cancelled')
                      ->where(function($q) {
                          $q->whereNull('balance_pending')
                            ->orWhere('balance_pending', '>', 0);
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
            
            // Priorizar cancelled si la factura está anulada
            if ($invoice->status === 'cancelled') {
                $invoice->display_status = 'cancelled';
            } elseif (isset($companyStatuses[$companyIdInt])) {
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
            
            // Recalcular balance_pending considerando NC/ND
            $totalNC = Invoice::where('related_invoice_id', $invoice->id)
                ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
                ->where('status', '!=', 'cancelled')
                ->sum('total');
            
            $totalND = Invoice::where('related_invoice_id', $invoice->id)
                ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
                ->where('status', '!=', 'cancelled')
                ->sum('total');
            
            $total = $invoice->total ?? 0;
            $adjustedTotal = $total + $totalND - $totalNC;
            $invoice->paid_amount = $paidAmount;
            $invoice->pending_amount = $adjustedTotal - $paidAmount;
            $invoice->balance_pending = $invoice->pending_amount;
            
            // Facturas anuladas no tienen payment_status
            if ($invoice->status === 'cancelled') {
                $invoice->payment_status = 'cancelled';
                Log::info('Setting payment_status to cancelled', [
                    'invoice_number' => $invoice->number,
                    'status' => $invoice->status,
                    'display_status' => $invoice->display_status ?? 'not set yet',
                ]);
            } elseif ($paidAmount >= $adjustedTotal) {
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
                'due_date.date' => 'La fecha de vencimiento debe ser una fecha válida.',
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
            'due_date' => 'nullable|date|after_or_equal:issue_date|before:2030-01-01',
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
        
        $invoice = Invoice::where(function($q) use ($companyId) {
                $q->where('issuer_company_id', $companyId)
                  ->orWhere('receiver_company_id', $companyId);
            })
            ->with([
                'client' => function($query) { $query->withTrashed(); },
                'supplier' => function($query) { $query->withTrashed(); },
                'items', 'receiverCompany', 'issuerCompany', 'perceptions', 'collections'
            ])
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
        
        // Agregar retenciones desde collections confirmadas
        $confirmedCollections = $invoice->collections->where('status', 'confirmed');
        if ($confirmedCollections->isNotEmpty()) {
            $invoice->withholding_iibb = $confirmedCollections->sum('withholding_iibb');
            $invoice->withholding_iibb_notes = $confirmedCollections->whereNotNull('withholding_iibb_notes')->pluck('withholding_iibb_notes')->filter()->implode(', ');
            $invoice->withholding_iva = $confirmedCollections->sum('withholding_iva');
            $invoice->withholding_iva_notes = $confirmedCollections->whereNotNull('withholding_iva_notes')->pluck('withholding_iva_notes')->filter()->implode(', ');
            $invoice->withholding_ganancias = $confirmedCollections->sum('withholding_ganancias');
            $invoice->withholding_ganancias_notes = $confirmedCollections->whereNotNull('withholding_ganancias_notes')->pluck('withholding_ganancias_notes')->filter()->implode(', ');
            $invoice->withholding_suss = $confirmedCollections->sum('withholding_suss');
            $invoice->withholding_suss_notes = $confirmedCollections->whereNotNull('withholding_suss_notes')->pluck('withholding_suss_notes')->filter()->implode(', ');
            $invoice->withholding_other = $confirmedCollections->sum('withholding_other');
            $invoice->withholding_other_notes = $confirmedCollections->whereNotNull('withholding_other_notes')->pluck('withholding_other_notes')->filter()->implode(', ');
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
            'invoice_type' => 'required|in:A,B,C,M,NCA,NCB,NCC,NCM,NDA,NDB,NDC,NDM',
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
                    // Buscar receptor por CUIT - PRIORIZAR empresas conectadas
                    $receiverCompanyId = null;
                    $clientId = null;
                    $receiverName = null;
                    $receiverDocument = null;
                    
                    if (isset($afipData['doc_number']) && $afipData['doc_number'] != '0') {
                        // AFIP devuelve CUIT sin guiones (20123456789)
                        $normalizedCuit = $this->normalizeCuit($afipData['doc_number']);
                        $receiverDocument = $this->formatCuitWithHyphens($normalizedCuit);
                        
                        Log::info('Processing CUIT from AFIP', [
                            'afip_raw' => $afipData['doc_number'],
                            'normalized' => $normalizedCuit,
                            'formatted' => $receiverDocument,
                        ]);
                        
                        // 1. Buscar empresa conectada PRIMERO
                        $receiverCompany = $this->findConnectedCompanyByCuit($company->id, $normalizedCuit);
                        
                        if ($receiverCompany) {
                            // Empresa conectada encontrada - usar receiver_company_id
                            $receiverCompanyId = $receiverCompany->id;
                            $receiverName = $receiverCompany->name;
                            // NO usar client_id cuando hay empresa conectada
                        } else {
                            // 2. No hay empresa conectada - buscar cliente externo (incluir archivados)
                            $client = \App\Models\Client::withTrashed()
                                ->where('company_id', $company->id)
                                ->whereRaw('REPLACE(document_number, "-", "") = ?', [$normalizedCuit])
                                ->first();
                            
                            if (!$client) {
                                $client = \App\Models\Client::create([
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
                    }
                    
                    // AFIP no devuelve el concepto - usar default 'products'
                    $concept = 'products';
                    
                    $invoice = Invoice::create([
                        'number' => $formattedNumber,
                        'type' => $invoiceType,
                        'sales_point' => $validated['sales_point'],
                        'voucher_number' => $validated['invoice_number'],
                        'concept' => $concept,
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
                        'needs_review' => isset($autoCreatedClient),
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
                
                return response()->json([
                    'success' => true,
                    'imported_count' => 1,
                    'auto_created_clients' => isset($autoCreatedClient) ? 1 : 0,
                    'invoices' => [[
                        'sales_point' => $validated['sales_point'],
                        'type' => $validated['invoice_type'],
                        'number' => $validated['invoice_number'],
                        'formatted_number' => $formattedNumber,
                        'data' => $afipData,
                        'saved' => !$exists,
                        'auto_created_client' => isset($autoCreatedClient),
                    ]],
                    'message' => isset($autoCreatedClient) 
                        ? '⚠️ Factura sincronizada. Se creó automáticamente un cliente archivado con CUIT ' . $receiverDocument . '. Debes completar sus datos en la sección de Clientes Archivados antes de poder emitirle facturas.' 
                        : 'Factura sincronizada correctamente.',
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
                'message' => 'No se pudo sincronizar la factura desde AFIP. Verificá el punto de venta, tipo y número de comprobante',
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

                                $issueDate = \Carbon\Carbon::parse($afipData['issue_date']);

                                // Skip if invoice is outside date range
                                if ($issueDate->lt($dateFrom) || $issueDate->gt($dateTo)) continue;

                                $formattedNumber = sprintf('%04d-%08d', $salesPoint, $num);

                                $key = "{$invoiceType}-{$salesPoint}-{$num}";
                                $exists = isset($existingInvoices[$key]);

                                if (!$exists) {
                                    $receiverCompanyId = null;
                                    $clientId = null;
                                    $receiverName = null;
                                    $receiverDocument = null;
                                    
                                    if (isset($afipData['doc_number']) && $afipData['doc_number'] !== '0') {
                                        // AFIP devuelve CUIT sin guiones (20123456789)
                                        $normalizedCuit = $this->normalizeCuit($afipData['doc_number']);
                                        $receiverDocument = $this->formatCuitWithHyphens($normalizedCuit);
                                        
                                        Log::info('Processing CUIT from AFIP (bulk)', [
                                            'afip_raw' => $afipData['doc_number'],
                                            'normalized' => $normalizedCuit,
                                            'formatted' => $receiverDocument,
                                        ]);
                                        
                                        // 1. PRIORIZAR empresa conectada
                                        $receiverCompany = $this->findConnectedCompanyByCuit($company->id, $normalizedCuit);
                                        
                                        if ($receiverCompany) {
                                            $receiverCompanyId = $receiverCompany->id;
                                            $receiverName = $receiverCompany->name;
                                        } else {
                                            // 2. Buscar cliente externo (incluir archivados)
                                            $client = \App\Models\Client::withTrashed()
                                                ->where('company_id', $company->id)
                                                ->whereRaw('REPLACE(document_number, "-", "") = ?', [$normalizedCuit])
                                                ->first();
                                            
                                            if (!$client) {
                                                $client = \App\Models\Client::create([
                                                    'company_id' => $company->id,
                                                    'document_number' => $receiverDocument,
                                                    'business_name' => 'Cliente AFIP - ' . $receiverDocument,
                                                    'document_type' => 'CUIT',
                                                    'tax_condition' => 'monotax',
                                                    'address' => null,
                                                    'incomplete_data' => true,
                                                ]);
                                                $client->delete(); // Archivar inmediatamente
                                                $autoCreatedClientsCount++;
                                            }
                                            
                                            $clientId = $client->id;
                                            $receiverName = $client->business_name;
                                        }
                                    }

                                    // Verificar si se creó cliente automáticamente
                                    $needsReview = false;
                                    if ($clientId) {
                                        $clientCreated = \App\Models\Client::withTrashed()
                                            ->where('id', $clientId)
                                            ->whereNotNull('deleted_at')
                                            ->exists();
                                        if ($clientCreated) {
                                            $needsReview = true;
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
            
            return response()->json([
                'success' => true,
                'imported_count' => count($imported),
                'auto_created_clients' => $autoCreatedClientsCount,
                'summary' => $summary,
                'invoices' => $imported,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'message' => $message,
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

    public function storeManualIssued(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        $this->authorize('create', [Invoice::class, $company]);
        
        $validated = $request->validate([
            'client_id' => 'required_without_all:receiver_company_id,client_name,related_invoice_id|exists:clients,id',
            'receiver_company_id' => 'required_without_all:client_id,client_name,related_invoice_id|exists:companies,id',
            'client_name' => 'required_without_all:client_id,receiver_company_id,related_invoice_id|string|max:200',
            'client_document' => 'required_with:client_name|string|max:20',
            'related_invoice_id' => 'nullable|exists:invoices,id',
            'invoice_type' => 'required|string',
            'invoice_number' => 'nullable|string|max:50',
            'number' => 'nullable|string|max:50',
            'voucher_number' => 'nullable|max:50',
            'sales_point' => 'nullable|integer|min:1|max:9999',
            'concept' => 'nullable|in:products,services,products_services',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date|before:2031-01-01',
            'currency' => 'required|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:200',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount_percentage' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_rate' => 'nullable|numeric|min:-2|max:100',
            'cae' => 'nullable|string|size:14|regex:/^[0-9]{14}$/',
            'cae_due_date' => 'nullable|date|required_with:cae',
            'service_date_from' => 'nullable|date|required_if:concept,services,products_services',
            'service_date_to' => 'nullable|date|after_or_equal:service_date_from|required_if:concept,services,products_services',
        ], [
            'client_name.required_without_all' => 'El nombre del cliente es obligatorio cuando no se selecciona un cliente o empresa existente.',
            'client_document.required_without_all' => 'El documento del cliente es obligatorio cuando no se selecciona un cliente o empresa existente.',
            'invoice_type.required' => 'El tipo de factura es obligatorio.',
            'issue_date.required' => 'La fecha de emisión es obligatoria.',
            'issue_date.date' => 'La fecha de emisión debe tener un formato válido (AAAA-MM-DD). Verifique que el día, mes y año sean correctos.',
            'due_date.required' => 'La fecha de vencimiento es obligatoria.',
            'due_date.date' => 'La fecha de vencimiento debe tener un formato válido (AAAA-MM-DD). Verifique que el día, mes y año sean correctos.',
            'due_date.after_or_equal' => 'La fecha de vencimiento debe ser igual o posterior a la fecha de emisión.',
            'due_date.before' => 'La fecha de vencimiento no puede ser posterior al año 2030. Seleccione una fecha hasta el 31/12/2030.',
            'cae.size' => 'El CAE debe tener exactamente 14 dígitos.',
            'cae.regex' => 'El CAE debe contener solo números (14 dígitos).',
            'cae_due_date.date' => 'La fecha de vencimiento del CAE debe tener un formato válido (AAAA-MM-DD).',
            'cae_due_date.required_with' => 'La fecha de vencimiento del CAE es obligatoria cuando se ingresa el número de CAE.',
            'service_date_from.required_if' => 'La fecha de inicio del servicio es obligatoria para servicios.',
            'service_date_to.required_if' => 'La fecha de fin del servicio es obligatoria para servicios.',
            'service_date_to.after_or_equal' => 'La fecha de fin del servicio debe ser igual o posterior a la fecha de inicio.',
            'currency.required' => 'La moneda es obligatoria.',
            'items.required' => 'Debe agregar al menos un ítem.',
            'items.*.description.required' => 'La descripción del ítem es obligatoria.',
            'items.*.quantity.required' => 'La cantidad es obligatoria.',
            'items.*.unit_price.required' => 'El precio unitario es obligatorio.',
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

            // Si es NC/ND, actualizar factura relacionada
            if (isset($validated['related_invoice_id'])) {
                $this->updateRelatedInvoiceBalance($validated['related_invoice_id']);
            }
            
            DB::commit();

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

    public function storeManualReceived(Request $request, $companyId)
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
                            ]
                        );
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
                $issuerCompany = Company::findOrFail($validated['issuer_company_id']);
                $supplierName = $issuerCompany->name;
                $supplierDocument = $issuerCompany->national_id;
                
                Log::info('Creating/finding supplier from connected company', [
                    'company_name' => $supplierName,
                    'document' => $supplierDocument,
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
                    ]
                );
                $supplierId = $supplier->id;
                
                Log::info('Supplier created/found', [
                    'supplier_id' => $supplierId,
                    'supplier_name' => $supplier->business_name,
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

            // Si es NC/ND, actualizar factura relacionada
            if (isset($validated['related_invoice_id'])) {
                $this->updateRelatedInvoiceBalance($validated['related_invoice_id']);
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

    public function updateSyncedInvoice(Request $request, $companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)->findOrFail($id);
        $this->authorize('update', $invoice);

        if (!$invoice->synced_from_afip) {
            return response()->json([
                'message' => 'Solo se pueden editar facturas sincronizadas desde AFIP'
            ], 422);
        }

        $validated = $request->validate([
            'concept' => 'required|in:products,services,products_services',
            'service_date_from' => 'nullable|date',
            'service_date_to' => 'nullable|date|after_or_equal:service_date_from',
            'items' => 'required|array',
            'items.*.description' => 'required|string|max:200',
        ], [
            'service_date_to.after_or_equal' => 'La fecha de fin del servicio debe ser igual o posterior a la fecha de inicio',
        ]);

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

    public function downloadBulk(Request $request, $companyId)
    {
        $validated = $request->validate([
            'invoice_ids' => 'required|array|min:1|max:50',
            'invoice_ids.*' => 'required|exists:invoices,id',
            'format' => 'required|in:pdf,txt',
        ]);

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

    /**
     * Find connected company by CUIT (only companies connected to the current company)
     */
    private function findConnectedCompanyByCuit(string $companyId, string $normalizedCuit): ?Company
    {
        // Get all connected company IDs
        $connectedCompanyIds = \App\Models\CompanyConnection::where(function($query) use ($companyId) {
            $query->where('company_id', $companyId)
                  ->orWhere('connected_company_id', $companyId);
        })
        ->where('status', 'connected')
        ->get()
        ->map(function($connection) use ($companyId) {
            return $connection->company_id === $companyId 
                ? $connection->connected_company_id 
                : $connection->company_id;
        })
        ->unique()
        ->values();

        // Search only among connected companies
        return Company::whereIn('id', $connectedCompanyIds)
            ->whereRaw('REPLACE(national_id, "-", "") = ?', [$normalizedCuit])
            ->first();
    }

    /**
     * Format CUIT with hyphens (XX-XXXXXXXX-X)
     */
    private function formatCuitWithHyphens(string $cuit): string
    {
        // Remove existing hyphens
        $cleanCuit = str_replace('-', '', $cuit);
        
        // Add hyphens if CUIT has 11 digits
        if (strlen($cleanCuit) === 11 && ctype_digit($cleanCuit)) {
            return substr($cleanCuit, 0, 2) . '-' . substr($cleanCuit, 2, 8) . '-' . substr($cleanCuit, 10, 1);
        }
        
        // Return as-is if not a valid 11-digit CUIT
        return $cuit;
    }
    
    /**
     * Get invoices that can be associated with NC/ND for /emit-invoice
     */
    public function getAssociableInvoicesForEmit(Request $request, $companyId)
    {
        $validated = $request->validate([
            'invoice_type' => 'required|string',
        ]);
        
        $invoiceType = \App\Services\VoucherTypeService::getTypeByCode($validated['invoice_type']) ?? $validated['invoice_type'];
        $isNC = in_array($invoiceType, ['NCA', 'NCB', 'NCC', 'NCM', 'NCE']);
        $isND = in_array($invoiceType, ['NDA', 'NDB', 'NDC', 'NDM', 'NDE']);
        
        if (!$isNC && !$isND) {
            return response()->json(['invoices' => []]);
        }
        
        // Facturas emitidas a través de AFIP (con CAE): incluye emitidas desde el sistema Y sincronizadas de AFIP
        $invoices = Invoice::where('issuer_company_id', $companyId)
            ->where('afip_status', 'approved')
            ->whereNotNull('afip_cae')
            ->whereIn('type', ['A', 'B', 'C', 'M', 'E'])
            ->where('status', '!=', 'cancelled')
            ->with(['client', 'receiverCompany'])
            ->orderBy('issue_date', 'desc')
            ->get()
            ->map(function($inv) {
                $origin = $inv->synced_from_afip ? 'Sincronizada AFIP' : 'Emitida desde sistema';
                $statusLabel = match($inv->status) {
                    'issued' => 'Emitida',
                    'approved' => 'Aprobada',
                    'paid' => 'Pagada',
                    'partially_cancelled' => 'Parcialmente anulada',
                    default => ucfirst($inv->status)
                };
                
                return [
                    'id' => $inv->id,
                    'number' => $inv->number,
                    'type' => $inv->type,
                    'issue_date' => $inv->issue_date,
                    'total' => $inv->total,
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
    public function getAssociableInvoicesForReceived(Request $request, $companyId)
    {
        $validated = $request->validate([
            'invoice_type' => 'required|string',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'issuer_document' => 'nullable|string',
        ]);
        
        $invoiceType = \App\Services\VoucherTypeService::getTypeByCode($validated['invoice_type']) ?? $validated['invoice_type'];
        $isNC = in_array($invoiceType, ['NCA', 'NCB', 'NCC', 'NCM', 'NCE']);
        $isND = in_array($invoiceType, ['NDA', 'NDB', 'NDC', 'NDM', 'NDE']);
        
        if (!$isNC && !$isND) {
            return response()->json(['invoices' => []]);
        }
        
        // Facturas recibidas de ese mismo proveedor
        $query = Invoice::where('receiver_company_id', $companyId)
            ->whereIn('type', ['A', 'B', 'C', 'M', 'E'])
            ->where('status', '!=', 'cancelled');
        
        if (!empty($validated['supplier_id'])) {
            $query->where('supplier_id', $validated['supplier_id']);
        } elseif (!empty($validated['issuer_document'])) {
            $query->where('issuer_document', $validated['issuer_document']);
        }
        
        $invoices = $query->with(['supplier'])
            ->orderBy('issue_date', 'desc')
            ->get()
            ->map(function($inv) {
                $origin = $inv->is_manual_load ? 'Cargada manualmente' : 'Recibida automática';
                $statusLabel = match($inv->status) {
                    'pending_approval' => 'Pendiente aprobación',
                    'approved' => 'Aprobada',
                    'paid' => 'Pagada',
                    'partially_cancelled' => 'Parcialmente anulada',
                    default => ucfirst($inv->status)
                };
                
                return [
                    'id' => $inv->id,
                    'number' => $inv->number,
                    'type' => $inv->type,
                    'issue_date' => $inv->issue_date,
                    'total' => $inv->total,
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
    public function getAssociableInvoicesForIssued(Request $request, $companyId)
    {
        $validated = $request->validate([
            'invoice_type' => 'required|string',
            'client_id' => 'nullable|exists:clients,id',
            'receiver_document' => 'nullable|string',
        ]);
        
        $invoiceType = \App\Services\VoucherTypeService::getTypeByCode($validated['invoice_type']) ?? $validated['invoice_type'];
        $isNC = in_array($invoiceType, ['NCA', 'NCB', 'NCC', 'NCM', 'NCE']);
        $isND = in_array($invoiceType, ['NDA', 'NDB', 'NDC', 'NDM', 'NDE']);
        
        if (!$isNC && !$isND) {
            return response()->json(['invoices' => []]);
        }
        
        // Facturas emitidas manualmente hacia ese mismo cliente
        $query = Invoice::where('issuer_company_id', $companyId)
            ->where('is_manual_load', true)
            ->whereIn('type', ['A', 'B', 'C', 'M', 'E'])
            ->where('status', '!=', 'cancelled');
        
        if (!empty($validated['client_id'])) {
            $query->where('client_id', $validated['client_id']);
        } elseif (!empty($validated['receiver_document'])) {
            $query->where('receiver_document', $validated['receiver_document']);
        }
        
        $invoices = $query->with(['client'])
            ->orderBy('issue_date', 'desc')
            ->get()
            ->map(function($inv) {
                $origin = 'Cargada manualmente';
                $statusLabel = match($inv->status) {
                    'issued' => 'Emitida',
                    'approved' => 'Aprobada',
                    'paid' => 'Pagada',
                    'partially_cancelled' => 'Parcialmente anulada',
                    default => ucfirst($inv->status)
                };
                
                return [
                    'id' => $inv->id,
                    'number' => $inv->number,
                    'type' => $inv->type,
                    'issue_date' => $inv->issue_date,
                    'total' => $inv->total,
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
     * Update related invoice balance when a NC/ND is created
     */
    private function updateRelatedInvoiceBalance(string $relatedInvoiceId): void
    {
        $relatedInvoice = Invoice::find($relatedInvoiceId);
        if (!$relatedInvoice) {
            return;
        }
        
        // RECALCULAR SALDO COMPLETO: Total + ND - NC - Collections/Payments
        $totalNC = Invoice::where('related_invoice_id', $relatedInvoice->id)
            ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
            ->where('status', '!=', 'cancelled')
            ->sum('total');
        
        $totalND = Invoice::where('related_invoice_id', $relatedInvoice->id)
            ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
            ->where('status', '!=', 'cancelled')
            ->sum('total');
        
        // Cobros/Pagos confirmados
        $totalCollections = $relatedInvoice->collections()
            ->where('status', 'confirmed')
            ->sum('amount');
        
        $totalPayments = \DB::table('invoice_payments_tracking')
            ->where('invoice_id', $relatedInvoice->id)
            ->whereIn('status', ['confirmed', 'in_process'])
            ->sum('amount');
        
        // Saldo = Total + ND - NC - Cobros - Pagos
        $relatedInvoice->balance_pending = $relatedInvoice->total + $totalND - $totalNC - $totalCollections - $totalPayments;
        
        // Redondear para evitar problemas de precisión
        $relatedInvoice->balance_pending = round($relatedInvoice->balance_pending, 2);
        
        Log::info('Recalculated invoice balance (manual load)', [
            'invoice_id' => $relatedInvoice->id,
            'invoice_number' => $relatedInvoice->number,
            'total' => $relatedInvoice->total,
            'total_nc' => $totalNC,
            'total_nd' => $totalND,
            'total_collections' => $totalCollections,
            'total_payments' => $totalPayments,
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
