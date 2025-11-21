<?php

namespace App\Console\Commands;

use App\Models\CompanyAfipCertificate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateCertificatesToDatabase extends Command
{
    protected $signature = 'certificates:migrate-to-db';
    protected $description = 'Migrate AFIP certificates from filesystem to database';

    public function handle()
    {
        $certificates = CompanyAfipCertificate::whereNotNull('certificate_path')->get();

        if ($certificates->isEmpty()) {
            $this->info('No certificates to migrate.');
            return;
        }

        foreach ($certificates as $cert) {
            try {
                $certPath = Storage::path($cert->certificate_path);
                $keyPath = Storage::path($cert->private_key_path);

                if (file_exists($certPath) && file_exists($keyPath)) {
                    $certContent = file_get_contents($certPath);
                    $keyContent = file_get_contents($keyPath);

                    $cert->update([
                        'certificate_content' => $certContent,
                        'private_key_content' => $keyContent,
                        'certificate_is_encrypted' => false,
                        'key_is_encrypted' => false,
                    ]);

                    $this->info("Migrated certificate for company: {$cert->company_id}");
                } else {
                    $this->warn("Certificate files not found for company: {$cert->company_id}");
                }
            } catch (\Exception $e) {
                $this->error("Error migrating certificate for company {$cert->company_id}: {$e->getMessage()}");
            }
        }

        $this->info('Certificate migration completed.');
    }
}
