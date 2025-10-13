<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasUuids, SoftDeletes;

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
        'tax_condition',
        'is_company_connection',
        'connected_company_id',
    ];

    protected $casts = [
        'is_company_connection' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function connectedCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'connected_company_id');
    }

    public function getFullNameAttribute(): string
    {
        if ($this->business_name) {
            return $this->business_name;
        }
        
        return trim("{$this->first_name} {$this->last_name}");
    }
}
