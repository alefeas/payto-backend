<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        $certificates = DB::table('company_afip_certificates')
            ->whereNotNull('certificate_path')
            ->whereNull('certificate_content')
            ->get();

        foreach ($certificates as $cert) {
            try {
                $certPath = Storage::path($cert->certificate_path);
                $keyPath = Storage::path($cert->private_key_path);

                $certContent = null;
                $keyContent = null;

                if (file_exists($certPath)) {
                    $certContent = file_get_contents($certPath);
                }

                if (file_exists($keyPath)) {
                    $keyContent = file_get_contents($keyPath);
                }

                if ($certContent || $keyContent) {
                    DB::table('company_afip_certificates')
                        ->where('id', $cert->id)
                        ->update([
                            'certificate_content' => $certContent,
                            'private_key_content' => $keyContent,
                            'certificate_is_encrypted' => false,
                            'key_is_encrypted' => false,
                        ]);
                }
            } catch (\Exception $e) {
                \Log::warning("Failed to populate certificate content for {$cert->id}: {$e->getMessage()}");
            }
        }
    }

    public function down(): void
    {
        DB::table('company_afip_certificates')
            ->update([
                'certificate_content' => null,
                'private_key_content' => null,
            ]);
    }
};
