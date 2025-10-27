<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
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
        'fiscal_address',
        'postal_code',
        'city',
        'province',
        'tax_condition',
        'incomplete_data',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'incomplete_data' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function requiresFiscalAddress(): bool
    {
        return $this->tax_condition !== 'final_consumer' && 
               in_array($this->document_type, ['CUIT', 'CUIL']);
    }

    public function getFullNameAttribute(): string
    {
        if ($this->business_name) {
            return $this->business_name;
        }
        
        return trim($this->first_name . ' ' . $this->last_name);
    }
}
