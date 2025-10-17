<?php

namespace App\Console\Commands;

use App\Models\CompanyAfipCertificate;
use Illuminate\Console\Command;

class ClearAfipTokens extends Command
{
    protected $signature = 'afip:clear-tokens {--company-id= : Clear tokens for specific company}';
    protected $description = 'Clear expired AFIP tokens to allow new token generation';

    public function handle()
    {
        $companyId = $this->option('company-id');
        
        $query = CompanyAfipCertificate::query();
        
        if ($companyId) {
            $query->where('company_id', $companyId);
        }
        
        $updated = $query->update([
            'current_token' => null,
            'current_sign' => null,
            'token_expires_at' => null,
        ]);
        
        $this->info("Cleared tokens for {$updated} certificate(s)");
        
        return 0;
    }
}
