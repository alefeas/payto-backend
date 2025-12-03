<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_afip_certificates', function (Blueprint $table) {
            if (!Schema::hasColumn('company_afip_certificates', 'certificate_content')) {
                $table->longText('certificate_content')->nullable()->after('certificate_path');
            }
            if (!Schema::hasColumn('company_afip_certificates', 'private_key_content')) {
                $table->longText('private_key_content')->nullable()->after('private_key_path');
            }
            if (!Schema::hasColumn('company_afip_certificates', 'certificate_is_encrypted')) {
                $table->boolean('certificate_is_encrypted')->default(false)->after('certificate_content');
            }
            if (!Schema::hasColumn('company_afip_certificates', 'key_is_encrypted')) {
                $table->boolean('key_is_encrypted')->default(false)->after('private_key_content');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_afip_certificates', function (Blueprint $table) {
            $table->dropColumn(['certificate_content', 'private_key_content', 'certificate_is_encrypted', 'key_is_encrypted']);
        });
    }
};
