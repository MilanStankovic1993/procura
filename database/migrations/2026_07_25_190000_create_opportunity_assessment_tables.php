<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_inputs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('analysis_id')
                ->constrained('analyses')
                ->cascadeOnDelete();
            $table->foreignUlid('comparable_set_id')
                ->constrained('comparable_sets')
                ->cascadeOnDelete();
            $table->foreignUlid('price_estimate_id')
                ->constrained('price_estimates')
                ->cascadeOnDelete();
            $table->foreignUlid('risk_assessment_id')
                ->constrained('risk_assessments')
                ->cascadeOnDelete();
            $table->foreignUlid('cost_input_id')
                ->constrained('cost_inputs')
                ->cascadeOnDelete();
            $table->foreignUlid('profit_estimate_id')
                ->constrained('profit_estimates')
                ->cascadeOnDelete();
            $table->foreignId('submitted_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('run_number');
            $table->string('input_version', 100);
            $table->char('input_hash', 64);
            $table->char('input_key', 64)->unique();
            $table->char('source_country_code', 2);
            $table->char('target_country_code', 2);
            $table->unsignedSmallInteger('known_count');
            $table->unsignedSmallInteger('unknown_count');
            $table->json('input_snapshot');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->foreign('source_country_code')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->foreign('target_country_code')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->unique(['analysis_id', 'run_number']);
            $table->index(
                ['organization_id', 'analysis_id', 'created_at'],
                'opportunity_inputs_tenant_analysis_index',
            );
            $table->index(
                [
                    'price_estimate_id',
                    'risk_assessment_id',
                    'cost_input_id',
                    'profit_estimate_id',
                ],
                'opportunity_inputs_evidence_chain_index',
            );
            $table->index('input_hash');
        });

        Schema::create('opportunity_input_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('opportunity_input_id')
                ->constrained('opportunity_inputs')
                ->cascadeOnDelete();
            $table->string('component', 20);
            $table->unsignedSmallInteger('position');
            $table->string('code', 64);
            $table->string('value_type', 20);
            $table->json('value_payload')->nullable();
            $table->boolean('is_known');
            $table->boolean('is_required');
            $table->string('source', 80);
            $table->json('evidence_snapshot');
            $table->timestamps();

            $table->unique(['opportunity_input_id', 'code']);
            $table->unique(
                ['opportunity_input_id', 'component', 'position'],
                'opportunity_input_items_position_unique',
            );
            $table->index(
                ['opportunity_input_id', 'component', 'is_required', 'is_known'],
                'opportunity_input_items_requirement_index',
            );
        });

        Schema::create('opportunity_assessments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('analysis_id')
                ->constrained('analyses')
                ->cascadeOnDelete();
            $table->foreignUlid('comparable_set_id')
                ->constrained('comparable_sets')
                ->cascadeOnDelete();
            $table->foreignUlid('price_estimate_id')
                ->constrained('price_estimates')
                ->cascadeOnDelete();
            $table->foreignUlid('risk_assessment_id')
                ->constrained('risk_assessments')
                ->cascadeOnDelete();
            $table->foreignUlid('cost_input_id')
                ->constrained('cost_inputs')
                ->cascadeOnDelete();
            $table->foreignUlid('profit_estimate_id')
                ->constrained('profit_estimates')
                ->cascadeOnDelete();
            $table->foreignUlid('opportunity_input_id')
                ->constrained('opportunity_inputs')
                ->cascadeOnDelete();
            $table->string('component', 20);
            $table->unsignedSmallInteger('run_number');
            $table->string('status', 32);
            $table->string('evaluator_version', 100);
            $table->char('input_hash', 64);
            $table->char('assessment_key', 64)->unique();
            $table->timestamp('calculated_at');
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedSmallInteger('confidence_basis_points');
            $table->string('confidence_level', 24);
            $table->unsignedSmallInteger('unknown_count');
            $table->json('reason_codes');
            $table->json('confidence_components');
            $table->json('input_snapshot');
            $table->timestamps();

            $table->unique(
                ['analysis_id', 'component', 'run_number'],
                'opportunity_assessments_component_run_unique',
            );
            $table->index(
                ['organization_id', 'component', 'status', 'created_at'],
                'opportunity_assessments_tenant_status_index',
            );
            $table->index(
                ['opportunity_input_id', 'component'],
                'opportunity_assessments_input_component_index',
            );
            $table->index('input_hash');
        });

        Schema::create('opportunity_assessment_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('opportunity_assessment_id')
                ->constrained('opportunity_assessments')
                ->cascadeOnDelete();
            $table->foreignUlid('opportunity_input_item_id')
                ->nullable()
                ->constrained('opportunity_input_items')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('code', 64);
            $table->unsignedSmallInteger('maximum_points');
            $table->unsignedSmallInteger('score_contribution')->nullable();
            $table->boolean('is_known');
            $table->json('source_snapshot');
            $table->timestamps();

            $table->unique(
                ['opportunity_assessment_id', 'position'],
                'opportunity_assessment_items_position_unique',
            );
            $table->unique(
                ['opportunity_assessment_id', 'code'],
                'opportunity_assessment_items_code_unique',
            );
            $table->index(
                ['opportunity_assessment_id', 'is_known', 'position'],
                'opportunity_assessment_items_known_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_assessment_items');
        Schema::dropIfExists('opportunity_assessments');
        Schema::dropIfExists('opportunity_input_items');
        Schema::dropIfExists('opportunity_inputs');
    }
};
