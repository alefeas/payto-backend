<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'is_mipyme')) {
                $table->boolean('is_mipyme')->default(false)->after('tax_condition');
            }
            if (!Schema::hasColumn('companies', 'cbu')) {
                $table->string('cbu', 22)->nullable()->after('tax_condition');
            }
        });

        // issuer_cbu ya existe en invoices desde migration anterior
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'is_mipyme')) {
                $table->dropColumn('is_mipyme');
            }
            if (Schema::hasColumn('companies', 'cbu')) {
                $table->dropColumn('cbu');
            }
        });
    }
};
