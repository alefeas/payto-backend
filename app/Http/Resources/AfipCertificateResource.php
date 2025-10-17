<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AfipCertificateResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'isActive' => $this->is_active,
            'validFrom' => $this->valid_from,
            'validUntil' => $this->valid_until,
            'isExpired' => $this->isExpired(),
            'isExpiringSoon' => $this->isExpiringSoon(),
            'environment' => $this->environment,
            'hasValidToken' => $this->hasValidToken(),
            'isSelfSigned' => $this->is_self_signed ?? false,
        ];
    }
}
