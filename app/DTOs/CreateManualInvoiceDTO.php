<?php

namespace App\DTOs;

readonly class CreateManualInvoiceDTO
{
    /**
     * @param InvoiceItemDTO[] $items
     * @param InvoicePerceptionDTO[] $perceptions
     */
    public function __construct(
        public string $companyId,
        public ?string $clientId,
        public ?string $receiverCompanyId,
        public ?string $clientName,
        public ?string $clientDocument,
        public ?string $supplierId,
        public ?string $issuerCompanyId,
        public ?string $supplierName,
        public ?string $supplierDocument,
        public ?string $relatedInvoiceId,
        public string $invoiceType,
        public string $invoiceNumber,
        public string $number,
        public ?string $voucherNumber,
        public ?int $salesPoint,
        public ?string $concept,
        public string $issueDate,
        public string $dueDate,
        public string $currency,
        public ?float $exchangeRate,
        public ?string $notes,
        public array $items,
        public array $perceptions,
        public ?string $cae,
        public ?string $caeDueDate,
        public ?string $serviceDateFrom,
        public ?string $serviceDateTo,
    ) {}

    public static function fromArray(array $data, string $companyId): self
    {
        return new self(
            companyId: $companyId,
            clientId: $data['client_id'] ?? null,
            receiverCompanyId: $data['receiver_company_id'] ?? null,
            clientName: $data['client_name'] ?? null,
            clientDocument: $data['client_document'] ?? null,
            supplierId: $data['supplier_id'] ?? null,
            issuerCompanyId: $data['issuer_company_id'] ?? null,
            supplierName: $data['supplier_name'] ?? null,
            supplierDocument: $data['supplier_document'] ?? null,
            relatedInvoiceId: $data['related_invoice_id'] ?? null,
            invoiceType: $data['invoice_type'],
            invoiceNumber: $data['invoice_number'] ?? '',
            number: $data['number'] ?? '',
            voucherNumber: $data['voucher_number'] ?? null,
            salesPoint: isset($data['sales_point']) ? (int) $data['sales_point'] : null,
            concept: $data['concept'] ?? null,
            issueDate: $data['issue_date'],
            dueDate: $data['due_date'],
            currency: $data['currency'],
            exchangeRate: isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
            notes: $data['notes'] ?? null,
            items: array_map(fn($item) => InvoiceItemDTO::fromArray($item), $data['items'] ?? []),
            perceptions: array_map(fn($perception) => InvoicePerceptionDTO::fromArray($perception), $data['perceptions'] ?? []),
            cae: $data['cae'] ?? null,
            caeDueDate: $data['cae_due_date'] ?? null,
            serviceDateFrom: $data['service_date_from'] ?? null,
            serviceDateTo: $data['service_date_to'] ?? null,
        );
    }
}

