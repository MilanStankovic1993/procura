<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table): void {
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
            $table->foreignUlid('comparable_set_id')
                ->constrained('comparable_sets')
                ->cascadeOnDelete();
            $table->foreignUlid('price_estimate_id')
                ->constrained('price_estimates')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('run_number');
            $table->string('status', 24);
            $table->string('evaluator_version', 100);
            $table->char('input_hash', 64);
            $table->char('assessment_key', 64)->unique();
            $table->timestamp('calculation_at');
            $table->unsignedTinyInteger('score');
            $table->string('level', 24);
            $table->unsignedSmallInteger('confidence_basis_points');
            $table->string('confidence_level', 24);
            $table->unsignedSmallInteger('signal_count');
            $table->unsignedSmallInteger('unknown_count');
            $table->json('reason_codes');
            $table->json('confidence_components');
            $table->json('verification_actions');
            $table->json('input_snapshot');
            $table->timestamps();

            $table->unique(['analysis_id', 'run_number']);
            $table->index(
                ['organization_id', 'status', 'created_at'],
                'risk_assessments_tenant_status_index',
            );
            $table->index(['price_estimate_id', 'created_at']);
            $table->index('input_hash');
        });

        Schema::create('risk_signals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('risk_assessment_id')
                ->constrained('risk_assessments')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('code', 100);
            $table->string('category', 32);
            $table->string('severity', 24);
            $table->boolean('is_unknown');
            $table->unsignedTinyInteger('weight_points');
            $table->unsignedTinyInteger('score_contribution');
            $table->json('evidence_snapshot');
            $table->string('source', 100);
            $table->unsignedSmallInteger('confidence_basis_points');
            $table->string('verification_action', 500)->nullable();
            $table->timestamps();

            $table->unique(
                ['risk_assessment_id', 'position'],
                'risk_signals_position_unique',
            );
            $table->unique(
                ['risk_assessment_id', 'code'],
                'risk_signals_code_unique',
            );
            $table->index(
                ['risk_assessment_id', 'category', 'severity'],
                'risk_signals_category_severity_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_signals');
        Schema::dropIfExists('risk_assessments');
    }
};
