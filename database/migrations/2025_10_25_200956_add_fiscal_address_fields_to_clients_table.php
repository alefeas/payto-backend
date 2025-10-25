<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('fiscal_address')->nullable()->after('address');
            $table->string('postal_code')->nullable()->after('fiscal_address');
            $table->string('city')->nullable()->after('postal_code');
            $table->string('province')->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['fiscal_address', 'postal_code', 'city', 'province']);
        });
    }
};