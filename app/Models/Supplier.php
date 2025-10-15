<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Supplier extends Model
{
    protected $fillable = [
        'company_id',
        'document_type',
        'document_number',
        'business_name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'tax_condition'
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
