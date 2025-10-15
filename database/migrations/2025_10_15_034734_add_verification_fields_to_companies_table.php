<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('verification_status', ['unverified', 'verified'])->default('unverified');
            $table->string('afip_certificate_path')->nullable();
            $table->string('afip_key_path')->nullable();
            $table->timestamp('verified_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['verification_status', 'afip_certificate_path', 'afip_key_path', 'verified_at']);
        });
    }
};
