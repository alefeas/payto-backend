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
        
        // Buscar facturas compatibles con saldo disponible (excluir anuladas)
        $invoices = Invoice::where('issuer_company_id', $companyId)
            ->whereIn('type', $compatibleWith)
            ->whereIn('status', ['issued', 'approved', 'partially_cancelled']) // Excluir 'cancelled'
            ->where(function($query) {
                $query->whereNull('balance_pending')
                      ->orWhere('balance_pending', '>', 0); // Solo con saldo disponible
            })
            ->with(['client', 'receiverCompany'])
            ->orderBy('issue_date', 'desc')
            ->get()
            ->map(function($invoice) {
                $clientName = 'Sin cliente';
                if ($invoice->client) {
                    $clientName = $invoice->client->business_name ?? "{$invoice->client->first_name} {$invoice->client->last_name}";
                } elseif ($invoice->receiverCompany) {
                    $clientName = $invoice->receiverCompany->name;
                }
                
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->number,
                    'invoice_type' => $invoice->type,
                    'issue_date' => $invoice->issue_date->format('Y-m-d'),
                    'client_name' => $clientName,
                    'total_amount' => $invoice->total,
                    'available_balance' => $invoice->balance_pending ?? $invoice->total,
                ];
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
        
        // Validar
        $validation = $this->validationService->validateVoucher($request->all(), $voucherType);
        if (!$validation['valid']) {
            return response()->json([
                'message' => 'Validation failed',
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
                    $voucher->load('perceptions');
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
        // Obtener último número
        $lastInvoice = Invoice::where('issuer_company_id', $company->id)
            ->where('type', $type)
            ->where('sales_point', $data['sales_point'])
            ->orderBy('voucher_number', 'desc')
            ->first();
        
        $voucherNumber = ($lastInvoice ? $lastInvoice->voucher_number : 0) + 1;
        
        // Calcular totales
        $subtotal = 0;
        $totalTaxes = 0;
        foreach ($data['items'] as $item) {
            $itemSubtotal = $item['quantity'] * $item['unit_price'];
            $itemTax = $itemSubtotal * ($item['tax_rate'] / 100);
            $subtotal += $itemSubtotal;
            $totalTaxes += $itemTax;
        }
        
        // Calcular percepciones
        $totalPerceptions = 0;
        if (isset($data['perceptions'])) {
            foreach ($data['perceptions'] as $perception) {
                $baseAmount = $perception['type'] === 'vat_perception' 
                    ? $totalTaxes 
                    : ($subtotal + $totalTaxes);
                $totalPerceptions += $baseAmount * ($perception['rate'] / 100);
            }
        }
        
        $total = $subtotal + $totalTaxes + $totalPerceptions;
        
        // Si tiene factura relacionada, obtener client_id y moneda de ella
        $clientId = $data['client_id'] ?? null;
        $receiverCompanyId = null;
        $currency = $data['currency'] ?? 'ARS';
        $exchangeRate = $data['exchange_rate'] ?? 1;
        
        if (isset($data['related_invoice_id'])) {
            $relatedInvoice = Invoice::find($data['related_invoice_id']);
            if ($relatedInvoice) {
                $clientId = $relatedInvoice->client_id;
                $receiverCompanyId = $relatedInvoice->receiver_company_id;
                $currency = $relatedInvoice->currency; // Tomar moneda de la factura
                $exchangeRate = $relatedInvoice->exchange_rate; // Tomar tipo de cambio
            }
        }
        
        // Crear comprobante
        $voucher = Invoice::create([
            'number' => sprintf('%04d-%08d', $data['sales_point'], $voucherNumber),
            'type' => $type,
            'sales_point' => $data['sales_point'],
            'voucher_number' => $voucherNumber,
            'concept' => 'products',
            'issuer_company_id' => $company->id,
            'client_id' => $clientId,
            'receiver_company_id' => $receiverCompanyId,
            'related_invoice_id' => $data['related_invoice_id'] ?? null,
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? now()->addDays(30),
            'subtotal' => $subtotal,
            'total_taxes' => $totalTaxes,
            'total_perceptions' => $totalPerceptions,
            'total' => $total,
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'notes' => $data['notes'] ?? null,
            'status' => 'pending_approval',
            'afip_status' => 'pending',
            'approvals_required' => 0,
            'approvals_received' => 0,
            'created_by' => auth()->id(),
            'balance_pending' => $total, // Inicializar saldo
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
        
        // Calcular saldo actual si no existe
        if ($relatedInvoice->balance_pending === null) {
            $relatedInvoice->balance_pending = $relatedInvoice->total;
        }
        
        // Actualizar saldo según tipo de nota
        if ($category === 'credit_note') {
            $relatedInvoice->balance_pending -= $voucher->total;
        } else if ($category === 'debit_note') {
            $relatedInvoice->balance_pending += $voucher->total;
        }
        
        // Redondear para evitar problemas de precisión
        $relatedInvoice->balance_pending = round($relatedInvoice->balance_pending, 2);
        
        // Actualizar estado según el saldo
        if ($relatedInvoice->balance_pending <= 0.01) { // Tolerancia de 1 centavo
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
            // Anulación parcial
            $relatedInvoice->status = 'partially_cancelled';
            
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
