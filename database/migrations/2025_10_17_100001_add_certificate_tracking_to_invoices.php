<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('afip_certificate_id')->nullable()->after('afip_cae_due_date');
            $table->foreign('afip_certificate_id')->references('id')->on('company_afip_certificates')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['afip_certificate_id']);
            $table->dropColumn('afip_certificate_id');
        });
    }
};
