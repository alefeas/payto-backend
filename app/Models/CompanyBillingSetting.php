<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyBillingSetting extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'default_vat',
        'vat_perception',
        'gross_income_perception',
        'social_security_perception',
        'vat_retention',
        'income_tax_retention',
        'gross_income_retention',
        'social_security_retention',
    ];

    protected $casts = [
        'default_vat' => 'decimal:2',
        'vat_perception' => 'decimal:2',
        'gross_income_perception' => 'decimal:2',
        'social_security_perception' => 'decimal:2',
        'vat_retention' => 'decimal:2',
        'income_tax_retention' => 'decimal:2',
        'gross_income_retention' => 'decimal:2',
        'social_security_retention' => 'decimal:2',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
