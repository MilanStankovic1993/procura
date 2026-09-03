<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_scores', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('analysis_id')
                ->constrained('analyses')
                ->cascadeOnDelete();
            $table->foreignUlid('product_match_id')
                ->constrained('product_matches')
                ->cascadeOnDelete();
            $table->foreignUlid('price_estimate_id')
                ->constrained('price_estimates')
                ->cascadeOnDelete();
            $table->foreignUlid('risk_assessment_id')
                ->constrained('risk_assessments')
                ->cascadeOnDelete();
            $table->foreignUlid('profit_estimate_id')
                ->constrained('profit_estimates')
                ->cascadeOnDelete();
            $table->foreignUlid('opportunity_input_id')
                ->constrained('opportunity_inputs')
                ->cascadeOnDelete();
            $table->foreignUlid('logistics_assessment_id')
                ->constrained('opportunity_assessments')
                ->cascadeOnDelete();
            $table->foreignUlid('demand_assessment_id')
                ->constrained('opportunity_assessments')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('run_number');
            $table->string('status', 32);
            $table->string('calculation_version', 100);
            $table->char('input_hash', 64);
            $table->char('score_key', 64)->unique();
            $table->timestamp('calculated_at');
            $table->unsignedTinyInteger('uncapped_score')->nullable();
            $table->unsignedSmallInteger(
                'uncapped_score_basis_points',
            )->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedSmallInteger('score_basis_points')->nullable();
            $table->string('recommendation', 40);
            $table->unsignedSmallInteger('confidence_basis_points');
            $table->string('confidence_level', 24);
            $table->unsignedTinyInteger('unknown_count');
            $table->unsignedTinyInteger('applicable_cap')->nullable();
            $table->json('cap_decisions');
            $table->json('reason_codes');
            $table->json('confidence_components');
            $table->json('factors_increasing');
            $table->json('factors_reducing');
            $table->json('assumptions');
            $table->json('verification_actions');
            $table->json('input_snapshot');
            $table->timestamps();

            $table->unique(
                ['analysis_id', 'run_number'],
                'deal_scores_analysis_run_unique',
            );
            $table->index(
                ['organization_id', 'analysis_id', 'created_at'],
                'deal_scores_tenant_analysis_index',
            );
            $table->index(
                [
                    'profit_estimate_id',
                    'logistics_assessment_id',
                    'demand_assessment_id',
                ],
                'deal_scores_evidence_chain_index',
            );
            $table->index('input_hash');
        });

        Schema::create('deal_score_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('deal_score_id')
                ->constrained('deal_scores')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('component', 40);
            $table->unsignedSmallInteger('weight_basis_points');
            $table->bigInteger('raw_value')->nullable();
            $table->string('raw_value_unit', 32);
            $table->unsignedSmallInteger(
                'normalized_score_basis_points',
            )->nullable();
            $table->unsignedSmallInteger(
                'weighted_contribution_basis_points',
            )->nullable();
            $table->unsignedSmallInteger('confidence_basis_points');
            $table->string('impact', 24);
            $table->json('source_snapshot');
            $table->timestamps();

            $table->unique(
                ['deal_score_id', 'position'],
                'deal_score_items_position_unique',
            );
            $table->unique(
                ['deal_score_id', 'component'],
                'deal_score_items_component_unique',
            );
            $table->index(
                ['deal_score_id', 'impact', 'position'],
                'deal_score_items_impact_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_score_items');
        Schema::dropIfExists('deal_scores');
    }
};
