<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'business_name',
        'national_id',
        'phone',
        'is_active',
        'deletion_code',
        'invite_code',
        'unique_id',
        'default_role',
        'tax_condition',
        'is_mipyme',
        'cbu',
        'default_sales_point',
        'last_invoice_number',
        'verification_status',
        'afip_certificate_path',
        'afip_key_path',
        'verified_at',
        'required_approvals',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_mipyme' => 'boolean',
        'last_invoice_number' => 'integer',
        'default_sales_point' => 'integer',
        'required_approvals' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function members()
    {
        return $this->hasMany(CompanyMember::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'company_members')
            ->withPivot('role', 'is_active')
            ->withTimestamps();
    }

    public function bankAccounts()
    {
        return $this->hasMany(BankAccount::class);
    }

    public function primaryBankAccount()
    {
        return $this->hasOne(BankAccount::class)->where('is_primary', true);
    }

    public function address()
    {
        return $this->hasOne(Address::class);
    }

    public function billingSettings()
    {
        return $this->hasOne(CompanyBillingSetting::class);
    }

    public function preferences()
    {
        return $this->hasOne(CompanyPreference::class);
    }

    public function afipCertificate()
    {
        return $this->hasOne(CompanyAfipCertificate::class);
    }

    public function afipCertificates()
    {
        return $this->hasMany(CompanyAfipCertificate::class);
    }

    public function issuedInvoices()
    {
        return $this->hasMany(Invoice::class, 'issuer_company_id');
    }

    public function receivedInvoices()
    {
        return $this->hasMany(Invoice::class, 'receiver_company_id');
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function isAfipVerified(): bool
    {
        return $this->verification_status === 'verified';
    }
}
