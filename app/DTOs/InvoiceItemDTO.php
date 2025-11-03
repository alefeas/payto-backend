<?php

namespace App\DTOs;

readonly class InvoiceItemDTO
{
    public function __construct(
        public string $description,
        public float $quantity,
        public float $unitPrice,
        public float $discountPercentage,
        public float $taxRate,
        public float $taxAmount,
        public float $subtotal,
        public int $orderIndex,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            description: $data['description'],
            quantity: (float) $data['quantity'],
            unitPrice: (float) $data['unit_price'],
            discountPercentage: (float) ($data['discount_percentage'] ?? 0),
            taxRate: (float) ($data['tax_rate'] ?? 0),
            taxAmount: (float) ($data['tax_amount'] ?? 0),
            subtotal: (float) ($data['subtotal'] ?? 0),
            orderIndex: (int) ($data['order_index'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'discount_percentage' => $this->discountPercentage,
            'tax_rate' => $this->taxRate,
            'tax_amount' => $this->taxAmount,
            'subtotal' => $this->subtotal,
            'order_index' => $this->orderIndex,
        ];
    }
}

