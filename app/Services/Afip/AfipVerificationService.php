<?php

namespace App\Services\Afip;

use App\Models\Company;

class AfipVerificationService
{
    public function getContribuyenteData(string $cuit, Company $company): ?array
    {
        // Placeholder - implementar consulta real a AFIP ws_sr_padron_a5
        return null;
    }
}
