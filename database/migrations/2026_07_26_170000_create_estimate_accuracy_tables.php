<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outcome_estimate_attributions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('owned_product_id');
            $table->ulid('realized_profit_id');
            $table->ulid('analysis_id');
            $table->ulid('profit_estimate_id');
            $table->ulid('previous_attribution_id')->nullable();
            $table->foreignId('actor_user_id');
            $table->unsignedInteger('sequence');
            $table->string('reason_code', 64);
            $table->string('evidence_kind', 32);
            $table->string('evidence_reference', 255)->nullable();
            $table->string('correction_reason', 64)->nullable();
            $table->string('note', 1000)->nullable();
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->char('input_hash', 64);
            $table->json('attribution_snapshot');
            $table->timestamp('attributed_at');
            $table->timestamps();

            $table->foreign('organization_id', 'outcome_attributions_org_fk')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('owned_product_id', 'outcome_attributions_product_fk')
                ->references('id')->on('owned_products')->cascadeOnDelete();
            $table->foreign('realized_profit_id', 'outcome_attributions_profit_fk')
                ->references('id')->on('realized_profits')->cascadeOnDelete();
            $table->foreign('analysis_id', 'outcome_attributions_analysis_fk')
                ->references('id')->on('analyses')->cascadeOnDelete();
            $table->foreign('profit_estimate_id', 'outcome_attributions_estimate_fk')
                ->references('id')->on('profit_estimates')->cascadeOnDelete();
            $table->foreign(
                'previous_attribution_id',
                'outcome_attributions_previous_fk',
            )->references('id')->on('outcome_estimate_attributions')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'outcome_attributions_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();

            $table->unique(
                ['owned_product_id', 'sequence'],
                'outcome_attributions_product_sequence_uq',
            );
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'outcome_attributions_org_idempotency_uq',
            );
            $table->index(
                ['organization_id', 'owned_product_id', 'created_at'],
                'outcome_attributions_org_product_idx',
            );
            $table->index(
                ['realized_profit_id', 'analysis_id', 'profit_estimate_id'],
                'outcome_attributions_evidence_idx',
            );
            $table->index('input_hash', 'outcome_attributions_input_hash_idx');
        });

        Schema::create('estimate_accuracy_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('owned_product_id');
            $table->ulid('outcome_estimate_attribution_id');
            $table->ulid('realized_profit_id');
            $table->ulid('analysis_id');
            $table->ulid('profit_estimate_id');
            $table->ulid('previous_report_id')->nullable();
            $table->unsignedInteger('sequence');
            $table->string('status', 24);
            $table->string('calculation_version', 100);
            $table->char('calculation_key', 64);
            $table->char('input_hash', 64);
            $table->char('source_currency_code', 3);
            $table->char('reporting_currency_code', 3);
            $table->ulid('exchange_rate_id')->nullable();
            $table->string('rate_direction', 24);
            $table->decimal('rate_value', 30, 18)->nullable();
            $table->timestamp('rate_effective_at')->nullable();
            $table->string('rate_provider', 80)->nullable();
            $table->string('rate_provider_reference', 160)->nullable();
            $table->timestamp('conversion_calculated_at');

            foreach ([
                'purchase_price',
                'additional_costs',
                'sale_price',
                'net_profit',
            ] as $metric) {
                $table->bigInteger("source_expected_{$metric}_minor");
                $table->bigInteger("expected_{$metric}_minor")->nullable();
                $table->bigInteger("actual_{$metric}_minor");
                $table->bigInteger("{$metric}_signed_error_minor")->nullable();
                $table->unsignedBigInteger("{$metric}_absolute_error_minor")->nullable();
                $table->bigInteger("{$metric}_signed_error_basis_points")->nullable();
                $table->unsignedBigInteger(
                    "{$metric}_absolute_percentage_error_basis_points",
                )->nullable();
            }

            $table->unsignedBigInteger('expected_sale_duration_seconds')->nullable();
            $table->unsignedBigInteger('actual_sale_duration_seconds');
            $table->bigInteger('sale_duration_signed_error_seconds')->nullable();
            $table->unsignedBigInteger('sale_duration_absolute_error_seconds')->nullable();
            $table->json('reason_codes');
            $table->json('unavailable_metrics');
            $table->json('input_snapshot');
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->foreign('organization_id', 'accuracy_reports_org_fk')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('owned_product_id', 'accuracy_reports_product_fk')
                ->references('id')->on('owned_products')->cascadeOnDelete();
            $table->foreign(
                'outcome_estimate_attribution_id',
                'accuracy_reports_attribution_fk',
            )->references('id')->on('outcome_estimate_attributions')->cascadeOnDelete();
            $table->foreign('realized_profit_id', 'accuracy_reports_profit_fk')
                ->references('id')->on('realized_profits')->cascadeOnDelete();
            $table->foreign('analysis_id', 'accuracy_reports_analysis_fk')
                ->references('id')->on('analyses')->cascadeOnDelete();
            $table->foreign('profit_estimate_id', 'accuracy_reports_estimate_fk')
                ->references('id')->on('profit_estimates')->cascadeOnDelete();
            $table->foreign('previous_report_id', 'accuracy_reports_previous_fk')
                ->references('id')->on('estimate_accuracy_reports')->cascadeOnDelete();
            $table->foreign('source_currency_code', 'accuracy_reports_source_currency_fk')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign(
                'reporting_currency_code',
                'accuracy_reports_reporting_currency_fk',
            )->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('exchange_rate_id', 'accuracy_reports_rate_fk')
                ->references('id')->on('exchange_rates')->restrictOnDelete();

            $table->unique(
                'outcome_estimate_attribution_id',
                'accuracy_reports_attribution_uq',
            );
            $table->unique('calculation_key', 'accuracy_reports_calculation_key_uq');
            $table->unique(
                ['owned_product_id', 'sequence'],
                'accuracy_reports_product_sequence_uq',
            );
            $table->index(
                ['organization_id', 'owned_product_id', 'status', 'created_at'],
                'accuracy_reports_org_product_status_idx',
            );
            $table->index(
                ['analysis_id', 'profit_estimate_id', 'realized_profit_id'],
                'accuracy_reports_evidence_idx',
            );
            $table->index('input_hash', 'accuracy_reports_input_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_accuracy_reports');
        Schema::dropIfExists('outcome_estimate_attributions');
    }
};
