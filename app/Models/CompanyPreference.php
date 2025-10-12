<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyPreference extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'currency',
        'payment_terms',
        'email_notifications',
        'payment_reminders',
        'invoice_approvals',
        'require_two_factor',
        'session_timeout',
        'auto_generate_invites',
    ];

    protected $casts = [
        'email_notifications' => 'boolean',
        'payment_reminders' => 'boolean',
        'invoice_approvals' => 'boolean',
        'require_two_factor' => 'boolean',
        'auto_generate_invites' => 'boolean',
        'payment_terms' => 'integer',
        'session_timeout' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
