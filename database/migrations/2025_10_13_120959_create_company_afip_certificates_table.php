<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_afip_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->onDelete('cascade');
            $table->string('certificate_path')->nullable();
            $table->string('private_key_path')->nullable();
            $table->text('encrypted_password')->nullable();
            $table->string('csr_path')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(false);
            $table->enum('environment', ['testing', 'production'])->default('testing');
            $table->timestamp('last_token_generated_at')->nullable();
            $table->text('current_token')->nullable();
            $table->text('current_sign')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamps();
            
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_afip_certificates');
    }
};
