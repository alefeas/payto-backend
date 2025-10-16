<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $table = 'invoice_payments_tracking';
    
    protected $fillable = [
        'company_id',
        'invoice_id',
        'amount',
        'payment_date',
        'payment_method',
        'reference_number',
        'attachment_url',
        'notes',
        'status',
        'registered_by',
        'registered_at',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'registered_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function retentions(): HasMany
    {
        return $this->hasMany(PaymentRetention::class);
    }
}
