<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CompanySalesPoint extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'point_number',
        'name',
        'is_active',
        'last_voucher_number',
    ];

    protected $casts = [
        'point_number' => 'integer',
        'is_active' => 'boolean',
        'last_voucher_number' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function getNextVoucherNumber(): int
    {
        $this->increment('last_voucher_number');
        return $this->last_voucher_number;
    }
}
