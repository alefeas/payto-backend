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
        'issuer_company_id',
        'receiver_company_id',
        'client_id',
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
        'created_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'afip_cae_due_date' => 'date',
        'approval_date' => 'datetime',
        'rejected_at' => 'datetime',
        'afip_sent_at' => 'datetime',
        'declared_uncollectible_date' => 'date',
        'subtotal' => 'decimal:2',
        'total_taxes' => 'decimal:2',
        'total_perceptions' => 'decimal:2',
        'total' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'approvals_required' => 'integer',
        'approvals_received' => 'integer',
        'requires_correction' => 'boolean',
        'dispute_opened' => 'boolean',
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

    public function hasValidCae(): bool
    {
        return $this->afip_cae && $this->afip_cae_due_date && $this->afip_cae_due_date->isFuture();
    }

    public function isAuthorized(): bool
    {
        return $this->afip_status === 'approved' && $this->hasValidCae();
    }
}
