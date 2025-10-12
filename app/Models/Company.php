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
        'tax_id',
        'phone',
        'address',
        'is_active',
        'deletion_code',
        'tax_condition',
        'default_sales_point',
        'default_vat',
        'default_gross_income',
        'default_income_tax',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'default_vat' => 'decimal:2',
        'default_gross_income' => 'decimal:2',
        'default_income_tax' => 'decimal:2',
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
}
