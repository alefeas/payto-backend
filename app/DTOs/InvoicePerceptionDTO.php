<?php

namespace App\DTOs;

readonly class InvoicePerceptionDTO
{
    public function __construct(
        public string $type,
        public string $name,
        public float $rate,
        public ?string $baseType,
        public ?string $jurisdiction,
        public float $baseAmount,
        public float $amount,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'],
            name: $data['name'],
            rate: (float) ($data['rate'] ?? 0),
            baseType: $data['base_type'] ?? null,
            jurisdiction: $data['jurisdiction'] ?? null,
            baseAmount: (float) ($data['base_amount'] ?? 0),
            amount: (float) ($data['amount'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'rate' => $this->rate,
            'base_type' => $this->baseType,
            'jurisdiction' => $this->jurisdiction,
            'base_amount' => $this->baseAmount,
            'amount' => $this->amount,
        ];
    }
}

