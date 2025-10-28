<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoicePerception extends Model
{
    use HasFactory, HasUuids;
    
    public $timestamps = true;

    protected $fillable = [
        'invoice_id',
        'type',
        'name',
        'rate',
        'base_type',
        'jurisdiction',
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
