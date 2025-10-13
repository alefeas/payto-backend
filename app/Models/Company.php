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
        'default_sales_point',
        'last_invoice_number',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_invoice_number' => 'integer',
        'default_sales_point' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
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
}
