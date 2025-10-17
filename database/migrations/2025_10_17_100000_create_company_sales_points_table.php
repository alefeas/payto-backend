<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_sales_points', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->unsignedSmallInteger('point_number');
            $table->string('name', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('last_voucher_number')->default(0);
            $table->timestamps();
            
            $table->unique(['company_id', 'point_number']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_sales_points');
    }
};
