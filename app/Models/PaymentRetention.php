<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRetention extends Model
{
    public $timestamps = false;
    
    protected $fillable = [
        'payment_id',
        'type',
        'name',
        'rate',
        'base_amount',
        'amount',
        'certificate_number',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
