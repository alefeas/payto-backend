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
        Schema::create('company_connections', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('company_id', 36);
            $table->char('connected_company_id', 36)->index('company_connections_connected_company_id_foreign');
            $table->enum('status', ['pending', 'connected', 'blocked'])->default('pending');
            $table->text('message')->nullable();
            $table->char('requested_by', 36)->index('company_connections_requested_by_foreign');
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'connected_company_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_connections');
    }
};
