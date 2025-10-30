<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_connections', function (Blueprint $table) {
            if (!Schema::hasColumn('company_connections', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_connections', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
