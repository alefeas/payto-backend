<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'service_date_from')) {
                $table->date('service_date_from')->nullable()->after('concept');
            }
            if (!Schema::hasColumn('invoices', 'service_date_to')) {
                $table->date('service_date_to')->nullable()->after('service_date_from');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'service_date_from')) {
                $table->dropColumn('service_date_from');
            }
            if (Schema::hasColumn('invoices', 'service_date_to')) {
                $table->dropColumn('service_date_to');
            }
        });
    }
};
