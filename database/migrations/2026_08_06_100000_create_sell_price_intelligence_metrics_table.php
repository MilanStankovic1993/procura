<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sell_price_intelligence_metrics', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('operation', 48);
            $table->string('metrics_version', 100);
            $table->string('selector_version', 100);
            $table->string('algorithm_version', 100);
            $table->unsignedSmallInteger('scope_count');
            $table->unsignedInteger('candidate_count');
            $table->unsignedInteger('included_count');
            $table->unsignedInteger('excluded_count');
            $table->unsignedInteger('band_input_count');
            $table->unsignedInteger('outlier_count');
            $table->unsignedSmallInteger('selection_replay_count');
            $table->unsignedSmallInteger('price_band_replay_count');
            $table->unsignedBigInteger('scope_discovery_microseconds');
            $table->unsignedBigInteger('comparable_selection_microseconds');
            $table->unsignedBigInteger('selection_persistence_microseconds');
            $table->unsignedBigInteger('price_band_estimation_microseconds');
            $table->unsignedBigInteger('price_band_persistence_microseconds');
            $table->unsignedBigInteger('total_microseconds');
            $table->timestamp('recorded_at');

            $table->index(
                ['recorded_at', 'id'],
                'sell_price_metrics_retention_index',
            );
            $table->index(
                ['operation', 'metrics_version', 'recorded_at'],
                'sell_price_metrics_report_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sell_price_intelligence_metrics');
    }
};
