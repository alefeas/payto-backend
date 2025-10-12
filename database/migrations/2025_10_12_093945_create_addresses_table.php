<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('street', 200);
            $table->string('street_number', 20);
            $table->string('floor', 10)->nullable();
            $table->string('apartment', 10)->nullable();
            $table->string('postal_code', 10);
            $table->string('province', 100);
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->default('Argentina');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
