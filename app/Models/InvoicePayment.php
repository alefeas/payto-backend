<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoicePayment extends Model
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
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'registered_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];
}
