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
            ->with([
                'client' => function($query) { $query->withTrashed(); },
                'supplier' => function($query) { $query->withTrashed(); },
                'items', 'issuerCompany', 'receiverCompany', 'approvals.user', 'relatedInvoice', 'payments', 'collections'
            ]);

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
                    $q->whereRaw("JSON_EXTRACT(company_statuses, '$.\"$companyId\"') = ?", [$status])
                      ->orWhere(function($q2) use ($status, $companyId) {
                          // Fallback: si no existe en JSON, usar lógica anterior
                          $q2->whereRaw("JSON_EXTRACT(company_statuses, '$.\"$companyId\"') IS NULL")
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
                // Set payment status based on invoice direction (operation type)
                if ($invoice->direction === 'issued') {
                    $invoice->payment_status = 'collected'; // Cobrada
                } else {
                    $invoice->payment_status = 'paid'; // Pagada
                }
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

    // Resto de métodos aquí...
    // Por brevedad, solo incluyo el método que está causando problemas
    
    public function storeManualReceived(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        
        $this->authorize('create', [Invoice::class, $company]);

        $validated = $request->validate([
            'supplier_id' => 'required_without:supplier_data|exists:suppliers,id',
            'supplier_data' => 'required_without:supplier_id|array',
            'supplier_data.document_type' => 'required_with:supplier_data|in:CUIT,CUIL,DNI',
            'supplier_data.document_number' => 'required_with:supplier_data|string',
            'supplier_data.business_name' => 'required_with:supplier_data|string',
            'supplier_data.email' => 'nullable|email',
            'supplier_data.phone' => 'nullable|string',
            'supplier_data.tax_condition' => 'required_with:supplier_data|in:registered_taxpayer,monotax,exempt',
            'save_supplier' => 'boolean',
            'invoice_type' => 'required|string',
            'sales_point' => 'required|integer|min:1|max:9999',
            'voucher_number' => 'required|integer|min:1',
            'concept' => 'required|in:products,services,products_services',
            'service_date_from' => 'nullable|date',
            'service_date_to' => 'nullable|date|after_or_equal:service_date_from',
            'issue_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:issue_date',
            'currency' => 'required|string|size:3',
            'exchange_rate' => 'required_unless:currency,ARS|numeric|min:0',
            'cae' => 'nullable|string|size:14',
            'cae_expiration' => 'nullable|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount_percentage' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'perceptions' => 'nullable|array',
            'perceptions.*.type' => 'required|string|max:100',
            'perceptions.*.name' => 'required|string|max:100',
            'perceptions.*.rate' => 'nullable|numeric|min:0|max:100',
            'perceptions.*.amount' => 'nullable|numeric|min:0',
            'perceptions.*.jurisdiction' => 'nullable|string|max:100',
            'perceptions.*.base_type' => 'nullable|in:net,total,vat',
        ]);

        try {
            DB::beginTransaction();

            // Create supplier if supplier_data is provided
            if (!isset($validated['supplier_id']) && isset($validated['supplier_data'])) {
                $supplier = \App\Models\Supplier::create([
                    'company_id' => $companyId,
                    ...$validated['supplier_data']
                ]);
                $validated['supplier_id'] = $supplier->id;
            }

            // Check if invoice number already exists FOR THIS SUPPLIER (by CUIT)
            $supplier = \App\Models\Supplier::findOrFail($validated['supplier_id']);
            $supplierCuit = str_replace('-', '', $supplier->document_number);
            
            $invoiceType = \App\Services\VoucherTypeService::getTypeByCode($validated['invoice_type']) ?? $validated['invoice_type'];
            
            // Buscar facturas del mismo proveedor (por CUIT) con mismo tipo, pto venta y número
            $exists = Invoice::where('receiver_company_id', $companyId)
                ->where('type', $invoiceType)
                ->where('sales_point', $validated['sales_point'])
                ->where('voucher_number', $validated['voucher_number'])
                ->whereHas('supplier', function($query) use ($supplierCuit) {
                    $query->whereRaw('REPLACE(document_number, "-", "") = ?', [$supplierCuit]);
                })
                ->exists();

            if ($exists) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Comprobante duplicado',
                    'error' => 'Ya registraste este comprobante de este proveedor (CUIT: ' . $supplier->document_number . '). Verificá el tipo, punto de venta y número.',
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
                    $totalPerceptions += $baseAmount * ($perception['rate'] / 100);
                }
            }

            $total = $subtotal + $totalTaxes + $totalPerceptions;

            $supplier = \App\Models\Supplier::findOrFail($validated['supplier_id']);
            $issuerName = $supplier->business_name;
            $issuerDocument = $supplier->document_number;

            $formattedNumber = sprintf('%04d-%08d', $validated['sales_point'], $validated['voucher_number']);

            // Determine initial status based on approval settings
            $requiredApprovals = (int)($company->required_approvals ?? 0);

            // Create received invoice
            $invoice = Invoice::create([
                'number' => $formattedNumber,
                'type' => $invoiceType,
                'sales_point' => $validated['sales_point'],
                'voucher_number' => $validated['voucher_number'],
                'concept' => $validated['concept'],
                'service_date_from' => $validated['service_date_from'] ?? null,
                'service_date_to' => $validated['service_date_to'] ?? null,
                'issuer_company_id' => $companyId, // Placeholder for constraint
                'receiver_company_id' => $companyId,
                'supplier_id' => $validated['supplier_id'],
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'] ?? now()->addDays(30),
                'subtotal' => $subtotal,
                'total_taxes' => $totalTaxes,
                'total_perceptions' => $totalPerceptions,
                'total' => $total,
                'currency' => $validated['currency'] ?? 'ARS',
                'exchange_rate' => $validated['exchange_rate'] ?? 1,
                'notes' => $validated['notes'] ?? null,
                'status' => $requiredApprovals === 0 ? 'approved' : 'pending_approval',
                'afip_status' => 'approved',
                'afip_cae' => $validated['cae'] ?? null,
                'afip_cae_due_date' => $validated['cae_expiration'] ?? null,
                'approvals_required' => $requiredApprovals,
                'approvals_received' => 0,
                'approval_date' => $requiredApprovals === 0 ? now() : null,
                'synced_from_afip' => false,
                'is_manual_load' => true,
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
            if (!empty($validated['perceptions'])) {
                foreach ($validated['perceptions'] as $perception) {
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

            DB::commit();

            return response()->json([
                'message' => 'Factura recibida registrada exitosamente',
                'invoice' => $invoice->load(['supplier', 'items', 'perceptions']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Manual received invoice creation failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error al registrar la factura recibida',
                'error' => $e->getMessage(),
            ], 500);
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
}