<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoicePerception extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'type',
        'name',
        'rate',
        'base_amount',
        'amount',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
