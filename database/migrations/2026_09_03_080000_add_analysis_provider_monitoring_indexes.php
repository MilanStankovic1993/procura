<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_provider_usages', function (Blueprint $table): void {
            $table->index(
                ['status', 'started_at'],
                'ai_usage_status_started_index',
            );
            $table->index(
                ['status', 'completed_at', 'failure_code'],
                'ai_usage_status_completed_failure_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('analysis_provider_usages', function (Blueprint $table): void {
            $table->dropIndex('ai_usage_status_started_index');
            $table->dropIndex('ai_usage_status_completed_failure_index');
        });
    }
};
