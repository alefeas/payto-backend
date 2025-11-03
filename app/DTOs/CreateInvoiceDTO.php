<?php

namespace App\DTOs;

readonly class CreateInvoiceDTO
{
    /**
     * @param InvoiceItemDTO[] $items
     * @param InvoicePerceptionDTO[] $perceptions
     */
    public function __construct(
        public string $companyId,
        public ?string $clientId,
        public ?string $receiverCompanyId,
        public ?array $clientData,
        public bool $saveClient,
        public string $invoiceType,
        public int $salesPoint,
        public string $concept,
        public ?string $serviceDateFrom,
        public ?string $serviceDateTo,
        public string $issueDate,
        public ?string $dueDate,
        public string $currency,
        public ?float $exchangeRate,
        public ?string $notes,
        public array $items,
        public array $perceptions,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            companyId: $data['company_id'],
            clientId: $data['client_id'] ?? null,
            receiverCompanyId: $data['receiver_company_id'] ?? null,
            clientData: $data['client_data'] ?? null,
            saveClient: $data['save_client'] ?? false,
            invoiceType: $data['invoice_type'],
            salesPoint: (int) $data['sales_point'],
            concept: $data['concept'],
            serviceDateFrom: $data['service_date_from'] ?? null,
            serviceDateTo: $data['service_date_to'] ?? null,
            issueDate: $data['issue_date'],
            dueDate: $data['due_date'] ?? null,
            currency: $data['currency'] ?? 'ARS',
            exchangeRate: isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
            notes: $data['notes'] ?? null,
            items: array_map(fn($item) => InvoiceItemDTO::fromArray($item), $data['items'] ?? []),
            perceptions: array_map(fn($perception) => InvoicePerceptionDTO::fromArray($perception), $data['perceptions'] ?? []),
        );
    }
}

