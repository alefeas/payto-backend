<?php

namespace App\Console\Commands;

use App\Models\CompanyAfipCertificate;
use Illuminate\Console\Command;

class ForceResetAfipToken extends Command
{
    protected $signature = 'afip:force-reset-token {--company-id=}';
    protected $description = 'Force reset AFIP token status (use only after 12 hours)';

    public function handle()
    {
        $companyId = $this->option('company-id');
        
        if (!$companyId) {
            $this->error('Debes especificar --company-id');
            return 1;
        }

        $certs = CompanyAfipCertificate::where('company_id', $companyId)->get();
        
        if ($certs->isEmpty()) {
            $this->error('No se encontraron certificados para esa empresa');
            return 1;
        }

        foreach ($certs as $cert) {
            $cert->update([
                'current_token' => null,
                'current_sign' => null,
                'token_expires_at' => null,
            ]);
        }

        $this->info("✓ Tokens reseteados para {$certs->count()} certificado(s)");
        $this->warn('Ahora puedes intentar emitir facturas nuevamente');
        
        return 0;
    }
}
