<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_inputs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('analysis_id')
                ->constrained('analyses')
                ->cascadeOnDelete();
            $table->foreignUlid('price_estimate_id')
                ->constrained('price_estimates')
                ->cascadeOnDelete();
            $table->foreignUlid('risk_assessment_id')
                ->constrained('risk_assessments')
                ->cascadeOnDelete();
            $table->foreignId('submitted_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('run_number');
            $table->string('input_version', 100);
            $table->char('input_hash', 64);
            $table->char('input_key', 64)->unique();
            $table->char('currency_code', 3);
            $table->char('source_country_code', 2);
            $table->char('target_country_code', 2);
            $table->boolean('regional_compatibility_confirmed')->nullable();
            $table->unsignedSmallInteger('known_count');
            $table->unsignedSmallInteger('unknown_count');
            $table->json('input_snapshot');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
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
                'cost_inputs_tenant_analysis_index',
            );
            $table->index(
                ['price_estimate_id', 'risk_assessment_id'],
                'cost_inputs_evidence_chain_index',
            );
            $table->index('input_hash');
        });

        Schema::create('cost_input_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('cost_input_id')
                ->constrained('cost_inputs')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('category', 32);
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->boolean('is_known');
            $table->string('source', 80);
            $table->timestamps();

            $table->unique(['cost_input_id', 'position']);
            $table->unique(['cost_input_id', 'category']);
            $table->index(['cost_input_id', 'is_known', 'position']);
        });

        Schema::create('profit_estimates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('analysis_id')
                ->constrained('analyses')
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
            $table->unsignedSmallInteger('run_number');
            $table->string('status', 32);
            $table->string('calculation_version', 100);
            $table->char('input_hash', 64);
            $table->char('estimate_key', 64)->unique();
            $table->timestamp('calculation_at');
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('expected_sale_price_minor');
            $table->unsignedBigInteger('purchase_price_minor')->nullable();
            $table->bigInteger('gross_margin_minor')->nullable();
            $table->unsignedBigInteger('known_costs_minor');
            $table->unsignedBigInteger('additional_costs_minor')->nullable();
            $table->unsignedBigInteger('total_cost_minor')->nullable();
            $table->bigInteger('expected_net_profit_minor')->nullable();
            $table->integer('profit_margin_basis_points')->nullable();
            $table->integer('return_on_invested_capital_basis_points')->nullable();
            $table->unsignedSmallInteger('confidence_basis_points');
            $table->string('confidence_level', 24);
            $table->unsignedSmallInteger('unknown_count');
            $table->json('reason_codes');
            $table->json('confidence_components');
            $table->json('input_snapshot');
            $table->timestamps();

            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->unique(['analysis_id', 'run_number']);
            $table->index(
                ['organization_id', 'status', 'created_at'],
                'profit_estimates_tenant_status_index',
            );
            $table->index(
                ['price_estimate_id', 'risk_assessment_id', 'cost_input_id'],
                'profit_estimates_evidence_chain_index',
            );
            $table->index('input_hash');
        });

        Schema::create('profit_estimate_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('profit_estimate_id')
                ->constrained('profit_estimates')
                ->cascadeOnDelete();
            $table->foreignUlid('cost_input_item_id')
                ->nullable()
                ->constrained('cost_input_items')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('category', 32);
            $table->string('kind', 16);
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->boolean('is_known');
            $table->json('source_snapshot');
            $table->timestamps();

            $table->unique(['profit_estimate_id', 'position']);
            $table->unique(['profit_estimate_id', 'category']);
            $table->index(
                ['profit_estimate_id', 'kind', 'position'],
                'profit_estimate_items_kind_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profit_estimate_items');
        Schema::dropIfExists('profit_estimates');
        Schema::dropIfExists('cost_input_items');
        Schema::dropIfExists('cost_inputs');
    }
};
