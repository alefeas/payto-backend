<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'street',
        'street_number',
        'floor',
        'apartment',
        'postal_code',
        'province',
        'city',
        'country',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
