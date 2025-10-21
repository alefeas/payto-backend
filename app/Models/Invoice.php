<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'number',
        'type',
        'sales_point',
        'voucher_number',
        'afip_voucher_type',
        'concept',
        'service_date_from',
        'service_date_to',
        'issuer_company_id',
        'receiver_company_id',
        'client_id',
        'supplier_id',
        'related_invoice_id',
        'balance_pending',
        'payment_due_date',
        'issuer_cbu',
        'acceptance_status',
        'acceptance_date',
        'transport_data',
        'operation_type',
        'issue_date',
        'due_date',
        'currency',
        'exchange_rate',
        'subtotal',
        'total_taxes',
        'total_perceptions',
        'total',
        'status',
        'approvals_required',
        'approvals_received',
        'approval_date',
        'rejection_reason',
        'rejected_at',
        'rejected_by',
        'requires_correction',
        'correction_notes',
        'dispute_opened',
        'dispute_reason',
        'pdf_url',
        'afip_txt_url',
        'notes',
        'collection_notes',
        'declared_uncollectible_date',
        'afip_cae',
        'afip_cae_due_date',
        'afip_status',
        'afip_error_message',
        'afip_sent_at',
        'attachment_path',
        'attachment_original_name',
        'created_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'service_date_from' => 'date',
        'service_date_to' => 'date',
        'payment_due_date' => 'date',
        'afip_cae_due_date' => 'date',
        'approval_date' => 'datetime',
        'acceptance_date' => 'datetime',
        'rejected_at' => 'datetime',
        'afip_sent_at' => 'datetime',
        'declared_uncollectible_date' => 'date',
        'subtotal' => 'decimal:2',
        'total_taxes' => 'decimal:2',
        'total_perceptions' => 'decimal:2',
        'total' => 'decimal:2',
        'balance_pending' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'approvals_required' => 'integer',
        'approvals_received' => 'integer',
        'requires_correction' => 'boolean',
        'dispute_opened' => 'boolean',
        'transport_data' => 'array',
    ];

    public function issuerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'issuer_company_id');
    }

    public function receiverCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'receiver_company_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(InvoiceTax::class);
    }

    public function perceptions(): HasMany
    {
        return $this->hasMany(InvoicePerception::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(InvoiceApproval::class);
    }

    public function relatedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'related_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(Invoice::class, 'related_invoice_id')
            ->whereIn('type', ['NCA', 'NCB', 'NCC', 'NCM', 'NCE']);
    }

    public function debitNotes(): HasMany
    {
        return $this->hasMany(Invoice::class, 'related_invoice_id')
            ->whereIn('type', ['NDA', 'NDB', 'NDC', 'NDM', 'NDE']);
    }

    public function getAvailableBalanceAttribute(): float
    {
        return $this->balance_pending ?? $this->total;
    }

    public function isFullyCancelled(): bool
    {
        return $this->balance_pending !== null && $this->balance_pending <= 0;
    }

    public function isPartiallyCancelled(): bool
    {
        return $this->balance_pending !== null && 
               $this->balance_pending > 0 && 
               $this->balance_pending < $this->total;
    }

    public function getTotalPaidAttribute(): float
    {
        return $this->payments()->sum('amount');
    }

    public function getRemainingAmountAttribute(): float
    {
        return $this->total - $this->getTotalPaidAttribute();
    }

    public function isPaid(): bool
    {
        return $this->getRemainingAmountAttribute() <= 0;
    }

    public function isPartiallyPaid(): bool
    {
        $totalPaid = $this->getTotalPaidAttribute();
        return $totalPaid > 0 && $totalPaid < $this->total;
    }

    public function hasValidCae(): bool
    {
        return $this->afip_cae && $this->afip_cae_due_date && $this->afip_cae_due_date->isFuture();
    }

    public function isAuthorized(): bool
    {
        return $this->afip_status === 'approved' && $this->hasValidCae();
    }
}
