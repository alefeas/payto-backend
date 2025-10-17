<?php

namespace App\Console\Commands;

use App\Models\CompanyAfipCertificate;
use App\Services\Afip\AfipWebServiceClient;
use Illuminate\Console\Command;

class ForceAfipToken extends Command
{
    protected $signature = 'afip:force-token {--company-id=}';
    protected $description = 'Force generate new AFIP token (waits for current to expire)';

    public function handle()
    {
        $companyId = $this->option('company-id');
        
        if (!$companyId) {
            $this->error('Debes especificar --company-id');
            return 1;
        }
        
        $cert = CompanyAfipCertificate::where('company_id', $companyId)->first();
        
        if (!$cert) {
            $this->error('No se encontró certificado');
            return 1;
        }
        
        $this->info('Limpiando tokens locales...');
        $cert->update([
            'current_token' => null,
            'current_sign' => null,
            'token_expires_at' => null,
        ]);
        
        $this->info('Intentando obtener token de AFIP...');
        
        try {
            $client = new AfipWebServiceClient($cert);
            $credentials = $client->getAuthCredentials();
            
            $this->info('✓ Token obtenido exitosamente');
            $this->info('Token: ' . substr($credentials['token'], 0, 50) . '...');
            $this->info('Expira: ' . $cert->fresh()->token_expires_at);
            
            return 0;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            
            if (str_contains($e->getMessage(), 'ya posee un TA valido')) {
                $this->warn('');
                $this->warn('AFIP tiene un token activo. Opciones:');
                $this->warn('1. Espera 12 horas desde la última generación');
                $this->warn('2. Genera un nuevo certificado en AFIP');
                $this->warn('3. Usa el comando: php artisan afip:wait-and-retry --company-id=' . $companyId);
            }
            
            return 1;
        }
    }
}
