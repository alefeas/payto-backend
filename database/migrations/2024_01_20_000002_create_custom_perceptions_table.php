<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_perceptions', function (Blueprint $table) {
            $table->id();
            $table->string('company_id');
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->enum('category', ['perception', 'retention']);
            $table->decimal('default_rate', 5, 2)->nullable();
            $table->enum('base_type', ['net', 'vat'])->default('net');
            $table->string('jurisdiction', 100)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['company_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_perceptions');
    }
};
