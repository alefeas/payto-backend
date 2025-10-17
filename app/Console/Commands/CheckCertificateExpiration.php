<?php

namespace App\Console\Commands;

use App\Models\CompanyAfipCertificate;
use App\Models\Notification;
use Illuminate\Console\Command;

class CheckCertificateExpiration extends Command
{
    protected $signature = 'afip:check-expiration';
    protected $description = 'Check AFIP certificates expiration and notify users';

    public function handle()
    {
        $this->info('Checking AFIP certificates expiration...');

        // Certificados que vencen en 30 días
        $expiringSoon = CompanyAfipCertificate::where('is_active', true)
            ->whereDate('valid_until', '<=', now()->addDays(30))
            ->whereDate('valid_until', '>', now())
            ->with('company.users')
            ->get();

        foreach ($expiringSoon as $cert) {
            $daysLeft = now()->diffInDays($cert->valid_until);
            
            foreach ($cert->company->users as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'company_id' => $cert->company_id,
                    'type' => 'certificate_expiring',
                    'title' => 'Certificado AFIP por vencer',
                    'message' => "El certificado AFIP de {$cert->company->name} vence en {$daysLeft} días. Renuévalo para seguir facturando.",
                    'data' => json_encode([
                        'certificate_id' => $cert->id,
                        'expires_at' => $cert->valid_until->format('Y-m-d'),
                        'days_left' => $daysLeft,
                    ]),
                ]);
            }
            
            $this->warn("Certificate for {$cert->company->name} expires in {$daysLeft} days");
        }

        // Certificados vencidos
        $expired = CompanyAfipCertificate::where('is_active', true)
            ->whereDate('valid_until', '<', now())
            ->with('company.users')
            ->get();

        foreach ($expired as $cert) {
            // Desactivar certificado vencido
            $cert->update(['is_active' => false]);
            
            foreach ($cert->company->users as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'company_id' => $cert->company_id,
                    'type' => 'certificate_expired',
                    'title' => 'Certificado AFIP vencido',
                    'message' => "El certificado AFIP de {$cert->company->name} ha vencido. No podrás facturar hasta renovarlo.",
                    'data' => json_encode([
                        'certificate_id' => $cert->id,
                        'expired_at' => $cert->valid_until->format('Y-m-d'),
                    ]),
                ]);
            }
            
            $this->error("Certificate for {$cert->company->name} has EXPIRED");
        }

        $this->info("Checked {$expiringSoon->count()} expiring and {$expired->count()} expired certificates");
        
        return 0;
    }
}
