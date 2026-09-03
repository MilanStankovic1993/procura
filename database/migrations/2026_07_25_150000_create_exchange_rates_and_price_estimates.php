<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('base_currency_code', 3);
            $table->char('quote_currency_code', 3);
            $table->decimal('rate', 30, 18);
            $table->string('provider', 80);
            $table->string('provider_reference', 160);
            $table->char('rate_key', 64)->unique();
            $table->char('evidence_hash', 64);
            $table->json('raw_evidence');
            $table->timestamp('effective_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->foreign('base_currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->foreign('quote_currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->index(
                ['base_currency_code', 'quote_currency_code', 'effective_at', 'id'],
                'exchange_rates_pair_time_index',
            );
            $table->index(
                ['provider', 'provider_reference'],
                'exchange_rates_provider_reference_index',
            );
            $table->index('evidence_hash');
        });

        Schema::create('price_estimates', function (Blueprint $table): void {
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
            $table->unsignedSmallInteger('run_number');
            $table->string('status', 32);
            $table->string('algorithm_version', 100);
            $table->string('rate_resolver_version', 100);
            $table->char('input_hash', 64);
            $table->char('estimate_key', 64)->unique();
            $table->timestamp('calculation_at');
            $table->char('target_country_code', 2);
            $table->char('target_currency_code', 3);
            $table->unsignedSmallInteger('input_count');
            $table->unsignedSmallInteger('included_count');
            $table->unsignedSmallInteger('outlier_count');
            $table->unsignedSmallInteger('unresolved_count');
            $table->unsignedBigInteger('estimate_low_minor')->nullable();
            $table->unsignedBigInteger('estimate_minor')->nullable();
            $table->unsignedBigInteger('estimate_high_minor')->nullable();
            $table->unsignedBigInteger('median_minor')->nullable();
            $table->unsignedBigInteger('weighted_median_minor')->nullable();
            $table->unsignedBigInteger('q1_minor')->nullable();
            $table->unsignedBigInteger('q3_minor')->nullable();
            $table->unsignedBigInteger('mad_minor')->nullable();
            $table->unsignedInteger('dispersion_basis_points')->nullable();
            $table->unsignedSmallInteger('confidence_basis_points')->nullable();
            $table->string('confidence_level', 24)->nullable();
            $table->json('reason_codes');
            $table->json('confidence_components');
            $table->json('input_snapshot');
            $table->timestamps();

            $table->foreign('target_country_code')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->foreign('target_currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->unique(['analysis_id', 'run_number']);
            $table->index(
                ['organization_id', 'status', 'created_at'],
                'price_estimates_tenant_status_index',
            );
            $table->index(['comparable_set_id', 'created_at']);
            $table->index('input_hash');
        });

        Schema::create('price_estimate_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('price_estimate_id')
                ->constrained('price_estimates')
                ->cascadeOnDelete();
            $table->foreignUlid('comparable_set_item_id')
                ->constrained('comparable_set_items')
                ->cascadeOnDelete();
            $table->foreignUlid('comparable_record_id')
                ->constrained('comparable_records')
                ->cascadeOnDelete();
            $table->foreignUlid('exchange_rate_id')
                ->nullable()
                ->constrained('exchange_rates')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('decision', 24);
            $table->unsignedBigInteger('original_amount_minor');
            $table->char('original_currency_code', 3);
            $table->unsignedBigInteger('target_amount_minor')->nullable();
            $table->char('target_currency_code', 3);
            $table->unsignedSmallInteger('weight_basis_points');
            $table->string('rate_direction', 24);
            $table->decimal('rate_value', 30, 18)->nullable();
            $table->timestamp('rate_effective_at')->nullable();
            $table->string('rate_provider', 80)->nullable();
            $table->string('rate_provider_reference', 160)->nullable();
            $table->json('reason_codes');
            $table->json('evidence_snapshot');
            $table->timestamps();

            $table->foreign('original_currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->foreign('target_currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->unique(
                ['price_estimate_id', 'comparable_set_item_id'],
                'price_estimate_items_set_item_unique',
            );
            $table->unique(
                ['price_estimate_id', 'position'],
                'price_estimate_items_position_unique',
            );
            $table->index(
                ['price_estimate_id', 'decision', 'position'],
                'price_estimate_items_decision_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_estimate_items');
        Schema::dropIfExists('price_estimates');
        Schema::dropIfExists('exchange_rates');
    }
};
