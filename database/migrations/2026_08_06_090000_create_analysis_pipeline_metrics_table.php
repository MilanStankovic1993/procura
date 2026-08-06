<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_pipeline_metrics', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ai_analysis_id')
                ->unique()
                ->constrained('ai_analyses')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('pipeline_version', 100);
            $table->string('provider_scope', 32);
            $table->string('attempt_status', 32);
            $table->string('failed_stage', 32)->nullable();
            $table->unsignedBigInteger('provider_analysis_microseconds')->nullable();
            $table->unsignedBigInteger('product_matching_microseconds')->nullable();
            $table->unsignedBigInteger('comparable_selection_microseconds')->nullable();
            $table->unsignedBigInteger('price_estimation_microseconds')->nullable();
            $table->unsignedBigInteger('risk_assessment_microseconds')->nullable();
            $table->unsignedBigInteger('finalization_microseconds')->nullable();
            $table->unsignedBigInteger('total_microseconds');
            $table->timestamp('recorded_at');

            $table->index(
                ['recorded_at', 'id'],
                'analysis_pipeline_metrics_retention_index',
            );
            $table->index(
                ['pipeline_version', 'provider_scope', 'recorded_at'],
                'analysis_pipeline_metrics_report_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_pipeline_metrics');
    }
};
