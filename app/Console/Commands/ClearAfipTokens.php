<?php

namespace App\Console\Commands;

use App\Models\CompanyAfipCertificate;
use Illuminate\Console\Command;

class ClearAfipTokens extends Command
{
    protected $signature = 'afip:clear-tokens {--company-id=}';
    protected $description = 'Clear AFIP tokens for a company';

    public function handle()
    {
        $companyId = $this->option('company-id');
        
        if (!$companyId) {
            $this->error('Debes especificar --company-id');
            return 1;
        }
        
        $cert = CompanyAfipCertificate::where('company_id', $companyId)->first();
        
        if (!$cert) {
            $this->error('No se encontró certificado para esa empresa');
            return 1;
        }
        
        $cert->update([
            'current_token' => null,
            'current_sign' => null,
            'token_expires_at' => null,
        ]);
        
        $this->info('Tokens limpiados. Próxima factura generará nuevo token.');
        return 0;
    }
}
