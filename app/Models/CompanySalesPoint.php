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
    ];

    protected $casts = [
        'point_number' => 'integer',
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
