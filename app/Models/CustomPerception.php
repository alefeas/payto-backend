<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomPerception extends Model
{
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'category',
        'default_rate',
        'base_type',
        'jurisdiction',
        'description',
    ];

    protected $casts = [
        'default_rate' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
