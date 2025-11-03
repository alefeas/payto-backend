<?php

namespace App\Services;

use App\DTOs\InvoiceItemDTO;
use App\DTOs\InvoicePerceptionDTO;

class InvoiceCalculationService
{
    /**
     * Calculate item totals (subtotal, tax, discount)
     */
    public function calculateItemTotals(InvoiceItemDTO $item): array
    {
        $discount = $item->discountPercentage;
        $taxRate = $item->taxRate;
        $itemBase = $item->quantity * $item->unitPrice;
        $itemDiscount = $itemBase * ($discount / 100);
        $itemSubtotal = $itemBase - $itemDiscount;
        
        // Exento (-1) y No Gravado (-2) tienen IVA = 0
        $itemTax = ($taxRate > 0) ? $itemSubtotal * ($taxRate / 100) : 0;
        
        return [
            'subtotal' => $itemSubtotal,
            'tax' => $itemTax,
            'discount' => $itemDiscount,
        ];
    }

    /**
     * Calculate totals for all items
     */
    public function calculateItemsTotals(array $items): array
    {
        $subtotal = 0;
        $totalTaxes = 0;

        foreach ($items as $item) {
            $totals = $this->calculateItemTotals($item);
            $subtotal += $totals['subtotal'];
            $totalTaxes += $totals['tax'];
        }

        return [
            'subtotal' => $subtotal,
            'total_taxes' => $totalTaxes,
        ];
    }

    /**
     * Calculate perception base amount
     */
    public function calculatePerceptionBase(string $type, ?string $baseType, float $subtotal, float $totalTaxes): float
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

    /**
     * Calculate total perceptions
     */
    public function calculatePerceptions(array $perceptions, float $subtotal, float $totalTaxes): float
    {
        $totalPerceptions = 0;
        
        if (empty($perceptions)) {
            return $totalPerceptions;
        }

        foreach ($perceptions as $perception) {
            $baseAmount = $this->calculatePerceptionBase(
                is_array($perception) ? $perception['type'] : $perception->type,
                is_array($perception) ? ($perception['base_type'] ?? null) : $perception->baseType,
                $subtotal,
                $totalTaxes
            );
            
            $rate = is_array($perception) ? ($perception['rate'] ?? 0) : ($perception->rate ?? 0);
            $totalPerceptions += $baseAmount * ($rate / 100);
        }

        return $totalPerceptions;
    }

    /**
     * Get default base type for perception
     */
    public function getDefaultBaseType(string $type): string
    {
        // All perceptions default to net (without IVA)
        return 'net';
    }

    /**
     * Calculate total invoice amount
     */
    public function calculateTotal(float $subtotal, float $totalTaxes, float $totalPerceptions): float
    {
        return $subtotal + $totalTaxes + $totalPerceptions;
    }

    /**
     * Validate total is not zero
     */
    public function validateTotal(float $total): bool
    {
        return $total > 0;
    }
}

