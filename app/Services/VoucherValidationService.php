<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\Validator;

class VoucherValidationService
{
    public function validateVoucher(array $data, string $type, ?string $companyId = null): array
    {
        $category = VoucherTypeService::getCategory($type);
        
        // Validaciones base
        $rules = $this->getBaseRules($type);
        
        // Validaciones específicas por categoría
        switch ($category) {
            case 'credit_note':
            case 'debit_note':
                // Si tiene related_invoice_id, es una nota asociada
                if (isset($data['related_invoice_id'])) {
                    $rules = array_merge($rules, $this->getAssociatedVoucherRules());
                } else {
                    // Si no tiene factura asociada, requiere cliente
                    $rules['client_id'] = 'required_without:client_data|exists:clients,id';
                }
                break;
            case 'fce_mipyme':
                $rules = array_merge($rules, $this->getFCEMipymeRules());
                break;
            case 'remito':
                $rules = array_merge($rules, $this->getRemitoRules());
                break;
            case 'used_goods_purchase':
                $rules = array_merge($rules, $this->getUsedGoodsPurchaseRules());
                break;
            default:
                // Para facturas normales, requiere cliente
                $rules['client_id'] = 'required_without:client_data|exists:clients,id';
                break;
        }
        
        $validator = Validator::make($data, $rules);
        
        if ($validator->fails()) {
            return ['valid' => false, 'errors' => $validator->errors()->toArray()];
        }
        
        // Validaciones de negocio
        $businessValidation = $this->validateBusinessRules($data, $type, $companyId);
        if (!$businessValidation['valid']) {
            return $businessValidation;
        }
        
        return ['valid' => true];
    }
    
    private function getBaseRules(string $type): array
    {
        $hasAmounts = VoucherTypeService::hasAmounts($type);
        
        $rules = [
            'sales_point' => 'required|integer|min:1|max:9999',
            'issue_date' => 'required|date',
        ];
        
        if ($hasAmounts) {
            $rules = array_merge($rules, [
                'items' => 'required|array|min:1',
                'items.*.description' => 'required|string',
                'items.*.quantity' => 'required|numeric|min:0.01',
                'items.*.unit_price' => 'required|numeric|min:0',
                'items.*.tax_rate' => 'required|numeric',
            ]);
        }
        
        return $rules;
    }
    
    private function getAssociatedVoucherRules(): array
    {
        return [
            'related_invoice_id' => 'required|exists:invoices,id',
        ];
    }
    
    private function getFCEMipymeRules(): array
    {
        return [
            'payment_due_date' => 'required|date|after:issue_date',
            'issuer_cbu' => 'required|string|size:22',
        ];
    }
    
    private function getRemitoRules(): array
    {
        return [
            'transport_data' => 'required|array',
            'transport_data.origin' => 'required|string',
            'transport_data.destination' => 'required|string',
            'transport_data.carrier_cuit' => 'nullable|string',
            'transport_data.vehicle_plate' => 'nullable|string',
        ];
    }
    
    private function getUsedGoodsPurchaseRules(): array
    {
        return [
            'seller_data' => 'required|array',
            'seller_data.document_type' => 'required|in:DNI,CUIL,CUIT',
            'seller_data.document_number' => 'required|string',
            'seller_data.name' => 'required|string',
            'seller_data.address' => 'required|string',
        ];
    }
    
    private function validateBusinessRules(array $data, string $type, ?string $companyId = null): array
    {
        $category = VoucherTypeService::getCategory($type);
        
        // Validar NC/ND
        if (in_array($category, ['credit_note', 'debit_note'])) {
            return $this->validateAssociatedVoucher($data, $type, $companyId);
        }
        
        // Validar FCE MiPyME
        if ($category === 'fce_mipyme') {
            return $this->validateFCEMipyme($data);
        }
        
        return ['valid' => true];
    }
    
    private function validateAssociatedVoucher(array $data, string $type, ?string $companyId = null): array
    {
        $relatedInvoice = Invoice::find($data['related_invoice_id']);
        
        if (!$relatedInvoice) {
            return ['valid' => false, 'errors' => ['related_invoice_id' => ['Factura no encontrada']]];
        }
        
        // Validar que la factura tenga receptor
        if (!$relatedInvoice->client_id && !$relatedInvoice->receiver_company_id && !$relatedInvoice->receiver_name) {
            return [
                'valid' => false,
                'errors' => [
                    'related_invoice_id' => [
                        'La factura seleccionada no tiene cliente o receptor. No se puede emitir NC/ND sobre facturas sin receptor.'
                    ]
                ]
            ];
        }
        
        // Validar que sea una factura (no otra NC/ND)
        $relatedCategory = VoucherTypeService::getCategory($relatedInvoice->type);
        if (in_array($relatedCategory, ['credit_note', 'debit_note'])) {
            return [
                'valid' => false,
                'errors' => [
                    'related_invoice_id' => [
                        'No se puede asociar una NC/ND a otra NC/ND. Debe asociarse a la factura original.'
                    ]
                ]
            ];
        }
        
        // Validar que la factura tenga CAE válido (solo si no es ambiente de testing)
        $requireCae = config('afip.require_cae_for_notes', true);
        if ($requireCae && (!$relatedInvoice->afip_cae || $relatedInvoice->afip_status !== 'approved')) {
            return [
                'valid' => false,
                'errors' => [
                    'related_invoice_id' => [
                        'No se puede emitir NC/ND sobre una factura sin CAE válido. La factura debe estar autorizada por AFIP.'
                    ]
                ]
            ];
        }
        
        // Validar que la factura no esté anulada
        // Usar el cálculo correcto del saldo disponible
        $availableBalance = $companyId 
            ? $this->calculateAvailableBalance($relatedInvoice, $companyId)
            : ($relatedInvoice->balance_pending ?? $relatedInvoice->total);
            
        if ($relatedInvoice->status === 'cancelled' || $availableBalance <= 0) {
            return [
                'valid' => false, 
                'errors' => [
                    'related_invoice_id' => [
                        'No se puede asociar una factura anulada (saldo $0). La factura ya fue anulada completamente.'
                    ]
                ]
            ];
        }
        
        // Validar compatibilidad de tipos
        if (!VoucherTypeService::isCompatibleWith($type, $relatedInvoice->type)) {
            return ['valid' => false, 'errors' => ['type' => ['Tipo de comprobante incompatible con la factura original']]];
        }
        
        // Validar fecha: NC/ND debe ser igual o posterior a la factura
        $invoiceDate = \Carbon\Carbon::parse($relatedInvoice->issue_date);
        $voucherDate = \Carbon\Carbon::parse($data['issue_date']);
        
        if ($voucherDate->lt($invoiceDate)) {
            return [
                'valid' => false, 
                'errors' => [
                    'issue_date' => [
                        sprintf(
                            'La fecha de la nota (%s) no puede ser anterior a la fecha de la factura (%s)',
                            $voucherDate->format('d/m/Y'),
                            $invoiceDate->format('d/m/Y')
                        )
                    ]
                ]
            ];
        }
        
        // Validar monto disponible usando el cálculo correcto
        $category = VoucherTypeService::getCategory($type);
        $totalRequested = $this->calculateTotal($data);
        
        // Redondear ambos valores para evitar problemas de precisión de punto flotante
        $totalRequested = round($totalRequested, 2);
        $availableBalance = round($availableBalance, 2);
        
        if ($category === 'credit_note' && $totalRequested > $availableBalance) {
            return [
                'valid' => false, 
                'errors' => [
                    'total' => [
                        sprintf(
                            'El monto (ARS $%s) excede el saldo disponible de la factura (ARS $%s)',
                            number_format($totalRequested, 2, ',', '.'),
                            number_format($availableBalance, 2, ',', '.')
                        )
                    ]
                ]
            ];
        }
        
        return ['valid' => true];
    }
    
    private function validateFCEMipyme(array $data): array
    {
        // Aquí validarías que la empresa esté inscripta en el régimen MiPyME
        // Por ahora retornamos válido
        return ['valid' => true];
    }
    
    private function calculateTotal(array $data): float
    {
        $subtotal = 0;
        $totalTaxes = 0;
        
        foreach ($data['items'] ?? [] as $item) {
            $itemBase = $item['quantity'] * $item['unit_price'];
            
            // Considerar descuentos si existen (igual que en el frontend y otros lugares)
            $discountPercentage = isset($item['discount_percentage']) ? (float)$item['discount_percentage'] : 0;
            $discount = $itemBase * ($discountPercentage / 100);
            $itemSubtotal = $itemBase - $discount;
            
            // Exento (-1) y No Gravado (-2) tienen IVA = 0
            $taxRate = isset($item['tax_rate']) ? (float)$item['tax_rate'] : 0;
            $itemTax = ($taxRate > 0) ? $itemSubtotal * ($taxRate / 100) : 0;
            
            $subtotal += $itemSubtotal;
            $totalTaxes += $itemTax;
        }
        
        // Considerar percepciones si existen
        $totalPerceptions = 0;
        foreach ($data['perceptions'] ?? [] as $perception) {
            // Determinar base según base_type (similar al frontend)
            $perceptionBase = match($perception['base_type'] ?? 'net') {
                'vat' => $totalTaxes,
                'total' => $subtotal + $totalTaxes,
                'net' => $subtotal,
                default => $subtotal,
            };
            
            $rate = isset($perception['rate']) ? (float)$perception['rate'] : (isset($perception['percentage']) ? (float)$perception['percentage'] : 0);
            $totalPerceptions += $perceptionBase * ($rate / 100);
        }
        
        return $subtotal + $totalTaxes + $totalPerceptions;
    }
    
    /**
     * Calculate available balance for an invoice (considering NC/ND and collections/payments)
     * Uses the same logic as InvoiceController::calculateAvailableBalance
     */
    private function calculateAvailableBalance(Invoice $invoice, string $companyId): float
    {
        // Always recalculate to ensure accuracy (balance_pending might be stale)
        // Calculate total NC (credit notes) associated with this invoice
        // Solo contar NC/ND que tengan CAE (fueron autorizadas por AFIP)
        $totalNC = Invoice::where('related_invoice_id', $invoice->id)
            ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE'])
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('afip_cae')
            ->sum('total');
        
        // Calculate total ND (debit notes) associated with this invoice
        $totalND = Invoice::where('related_invoice_id', $invoice->id)
            ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE'])
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('afip_cae')
            ->sum('total');
        
        // Determine if invoice is issued (by this company) or received
        $isIssued = (string)$invoice->issuer_company_id === (string)$companyId;
        
        // Calculate confirmed collections (for issued invoices)
        $totalCollections = 0;
        if ($isIssued) {
            if ($invoice->collections && $invoice->collections->isNotEmpty()) {
                $totalCollections = $invoice->collections
                    ->where('company_id', $companyId)
                    ->where('status', 'confirmed')
                    ->sum('amount');
            } else {
                // Fallback to query if relation not loaded
                $totalCollections = \App\Models\Collection::where('invoice_id', $invoice->id)
                    ->where('company_id', $companyId)
                    ->where('status', 'confirmed')
                    ->sum('amount');
            }
        }
        
        // Calculate confirmed payments (for received invoices)
        $totalPayments = 0;
        if (!$isIssued) {
            $totalPayments = \Illuminate\Support\Facades\DB::table('invoice_payments_tracking')
                ->where('invoice_id', $invoice->id)
                ->where('company_id', $companyId)
                ->whereIn('status', ['confirmed', 'in_process'])
                ->sum('amount');
        }
        
        // Balance = Total + ND - NC - Collections - Payments
        $balance = ($invoice->total ?? 0) + $totalND - $totalNC - $totalCollections - $totalPayments;
        
        return round(max(0, $balance), 2); // Ensure non-negative
    }
}
