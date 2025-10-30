<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('company_connections')) {
            Schema::create('company_connections', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('company_id')->constrained()->onDelete('cascade');
                $table->foreignUuid('connected_company_id')->constrained('companies')->onDelete('cascade');
                $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
                $table->text('message')->nullable();
                $table->foreignUuid('requested_by')->constrained('users')->onDelete('cascade');
                $table->timestamp('connected_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_connections');
    }
};
