<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Collection extends Model
{
    protected $table = 'invoice_collections';
    
    protected $fillable = [
        'company_id',
        'invoice_id',
        'amount',
        'collection_date',
        'collection_method',
        'reference_number',
        'attachment_url',
        'notes',
        'status',
        'registered_by',
        'registered_at',
        'confirmed_by',
        'confirmed_at',
        'from_network',
    ];

    protected $casts = [
        'collection_date' => 'date',
        'registered_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'amount' => 'decimal:2',
        'from_network' => 'boolean',
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
}
