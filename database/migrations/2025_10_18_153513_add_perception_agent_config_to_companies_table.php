<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Perception agent configuration
            $table->boolean('is_perception_agent')->default(false)->after('required_approvals');
            $table->json('auto_perceptions')->nullable()->after('is_perception_agent');
            
            // Retention agent configuration
            $table->boolean('is_retention_agent')->default(false)->after('auto_perceptions');
            $table->json('auto_retentions')->nullable()->after('is_retention_agent');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['is_perception_agent', 'auto_perceptions', 'is_retention_agent', 'auto_retentions']);
        });
    }
};
