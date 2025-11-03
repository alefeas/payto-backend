<?php

namespace App\Services;

use App\Models\Company;

class CuitHelperService
{
    /**
     * Normalize CUIT: remove hyphens for comparison
     * AFIP returns: 20123456789
     * System saves: 20-12345678-9
     */
    public function normalizeCuit(string $cuit): string
    {
        return str_replace('-', '', $cuit);
    }

    /**
     * Format CUIT with hyphens (XX-XXXXXXXX-X)
     */
    public function formatCuitWithHyphens(string $cuit): string
    {
        // Remove existing hyphens
        $cleanCuit = str_replace('-', '', $cuit);
        
        // Add hyphens if CUIT has 11 digits
        if (strlen($cleanCuit) === 11 && ctype_digit($cleanCuit)) {
            return substr($cleanCuit, 0, 2) . '-' . substr($cleanCuit, 2, 8) . '-' . substr($cleanCuit, 10, 1);
        }
        
        // Return as-is if not a valid 11-digit CUIT
        return $cuit;
    }

    /**
     * Find connected company by CUIT (only companies connected to the current company)
     */
    public function findConnectedCompanyByCuit(string $companyId, string $normalizedCuit): ?Company
    {
        // Get all connected company IDs
        $connectedCompanyIds = \App\Models\CompanyConnection::where(function($query) use ($companyId) {
            $query->where('company_id', $companyId)
                  ->orWhere('connected_company_id', $companyId);
        })
        ->where('status', 'connected')
        ->get()
        ->map(function($connection) use ($companyId) {
            return $connection->company_id === $companyId 
                ? $connection->connected_company_id 
                : $connection->company_id;
        })
        ->unique()
        ->values();

        // Search only among connected companies
        return Company::whereIn('id', $connectedCompanyIds)
            ->whereRaw('REPLACE(national_id, "-", "") = ?', [$normalizedCuit])
            ->first();
    }
}

