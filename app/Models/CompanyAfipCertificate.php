<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CompanyAfipCertificate extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'certificate_path',
        'private_key_path',
        'encrypted_password',
        'csr_path',
        'valid_from',
        'valid_until',
        'is_active',
        'environment',
        'last_token_generated_at',
        'current_token',
        'current_sign',
        'token_expires_at',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_active' => 'boolean',
        'last_token_generated_at' => 'datetime',
        'token_expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = [
        'encrypted_password',
        'current_token',
        'current_sign',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast();
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->valid_until && $this->valid_until->diffInDays(now()) <= $days;
    }

    public function hasValidToken(): bool
    {
        return $this->current_token && 
               $this->token_expires_at && 
               $this->token_expires_at->isFuture();
    }
}
