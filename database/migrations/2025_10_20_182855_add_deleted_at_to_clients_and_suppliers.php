<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check if deleted_at exists in clients before adding
        if (!Schema::hasColumn('clients', 'deleted_at')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        // Check if deleted_at exists in suppliers before adding
        if (!Schema::hasColumn('suppliers', 'deleted_at')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
