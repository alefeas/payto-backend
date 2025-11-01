<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PendingRegistration extends Model
{
    use HasUuids;

    public $timestamps = false;
    protected $fillable = ['email', 'code', 'user_data', 'expires_at', 'verified_at', 'attempts', 'created_at'];
    protected $casts = [
        'user_data' => 'array',
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }

    public function isVerified(): bool
    {
        return !is_null($this->verified_at);
    }

    public function incrementAttempts(): void
    {
        $this->increment('attempts');
    }
}
