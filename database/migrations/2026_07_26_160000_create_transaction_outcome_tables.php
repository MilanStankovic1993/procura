<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actual_purchases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('owned_product_id');
            $table->ulid('previous_purchase_id')->nullable();
            $table->foreignId('actor_user_id');
            $table->unsignedInteger('sequence');
            $table->unsignedBigInteger('source_amount_minor');
            $table->char('source_currency_code', 3);
            $table->unsignedBigInteger('reporting_amount_minor');
            $table->char('reporting_currency_code', 3);
            $table->ulid('exchange_rate_id')->nullable();
            $table->string('rate_direction', 24);
            $table->decimal('rate_value', 30, 18);
            $table->timestamp('rate_effective_at')->nullable();
            $table->string('rate_provider', 80)->nullable();
            $table->string('rate_provider_reference', 160)->nullable();
            $table->timestamp('conversion_calculated_at');
            $table->string('evidence_kind', 32);
            $table->string('evidence_reference', 255)->nullable();
            $table->string('correction_reason', 64)->nullable();
            $table->string('note', 1000)->nullable();
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->char('input_hash', 64);
            $table->json('evidence_snapshot');
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->foreign('organization_id', 'actual_purchases_org_fk')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('owned_product_id', 'actual_purchases_product_fk')
                ->references('id')->on('owned_products')->cascadeOnDelete();
            $table->foreign('previous_purchase_id', 'actual_purchases_previous_fk')
                ->references('id')->on('actual_purchases')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'actual_purchases_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('source_currency_code', 'actual_purchases_source_currency_fk')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('reporting_currency_code', 'actual_purchases_reporting_currency_fk')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('exchange_rate_id', 'actual_purchases_rate_fk')
                ->references('id')->on('exchange_rates')->restrictOnDelete();

            $table->unique(
                ['owned_product_id', 'sequence'],
                'actual_purchases_product_sequence_uq',
            );
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'actual_purchases_org_idempotency_uq',
            );
            $table->index(
                ['organization_id', 'owned_product_id', 'created_at'],
                'actual_purchases_org_product_idx',
            );
            $table->index('input_hash', 'actual_purchases_input_hash_idx');
        });

        Schema::create('actual_cost_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('owned_product_id');
            $table->ulid('previous_snapshot_id')->nullable();
            $table->foreignId('actor_user_id');
            $table->unsignedInteger('sequence');
            $table->char('reporting_currency_code', 3);
            $table->unsignedSmallInteger('known_count');
            $table->unsignedSmallInteger('unknown_count');
            $table->unsignedBigInteger('known_reporting_total_minor');
            $table->string('correction_reason', 64)->nullable();
            $table->string('note', 1000)->nullable();
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->char('input_hash', 64);
            $table->json('input_snapshot');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->foreign('organization_id', 'actual_cost_snapshots_org_fk')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('owned_product_id', 'actual_cost_snapshots_product_fk')
                ->references('id')->on('owned_products')->cascadeOnDelete();
            $table->foreign('previous_snapshot_id', 'actual_cost_snapshots_previous_fk')
                ->references('id')->on('actual_cost_snapshots')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'actual_cost_snapshots_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign(
                'reporting_currency_code',
                'actual_cost_snapshots_currency_fk',
            )->references('code')->on('currencies')->restrictOnDelete();

            $table->unique(
                ['owned_product_id', 'sequence'],
                'actual_cost_snapshots_product_sequence_uq',
            );
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'actual_cost_snapshots_org_idempotency_uq',
            );
            $table->index(
                ['organization_id', 'owned_product_id', 'created_at'],
                'actual_cost_snapshots_org_product_idx',
            );
            $table->index('input_hash', 'actual_cost_snapshots_input_hash_idx');
        });

        Schema::create('actual_cost_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('actual_cost_snapshot_id');
            $table->unsignedSmallInteger('position');
            $table->string('category', 32);
            $table->boolean('is_known');
            $table->unsignedBigInteger('source_amount_minor')->nullable();
            $table->char('source_currency_code', 3)->nullable();
            $table->unsignedBigInteger('reporting_amount_minor')->nullable();
            $table->char('reporting_currency_code', 3);
            $table->ulid('exchange_rate_id')->nullable();
            $table->string('rate_direction', 24)->nullable();
            $table->decimal('rate_value', 30, 18)->nullable();
            $table->timestamp('rate_effective_at')->nullable();
            $table->string('rate_provider', 80)->nullable();
            $table->string('rate_provider_reference', 160)->nullable();
            $table->timestamp('conversion_calculated_at')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->string('evidence_kind', 32)->nullable();
            $table->string('evidence_reference', 255)->nullable();
            $table->string('note', 1000)->nullable();
            $table->char('evidence_hash', 64);
            $table->json('evidence_snapshot');
            $table->timestamps();

            $table->foreign(
                'actual_cost_snapshot_id',
                'actual_cost_items_snapshot_fk',
            )->references('id')->on('actual_cost_snapshots')->cascadeOnDelete();
            $table->foreign(
                'source_currency_code',
                'actual_cost_items_source_currency_fk',
            )->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign(
                'reporting_currency_code',
                'actual_cost_items_reporting_currency_fk',
            )->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('exchange_rate_id', 'actual_cost_items_rate_fk')
                ->references('id')->on('exchange_rates')->restrictOnDelete();

            $table->unique(
                ['actual_cost_snapshot_id', 'category'],
                'actual_cost_items_snapshot_category_uq',
            );
            $table->unique(
                ['actual_cost_snapshot_id', 'position'],
                'actual_cost_items_snapshot_position_uq',
            );
            $table->index('evidence_hash', 'actual_cost_items_evidence_hash_idx');
        });

        Schema::create('actual_sales', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('owned_product_id');
            $table->ulid('sale_portfolio_entry_id');
            $table->ulid('sale_portfolio_event_id');
            $table->ulid('previous_sale_id')->nullable();
            $table->foreignId('actor_user_id');
            $table->unsignedInteger('sequence');
            $table->string('outcome_type', 24);
            $table->unsignedBigInteger('source_amount_minor')->nullable();
            $table->char('source_currency_code', 3)->nullable();
            $table->unsignedBigInteger('reporting_amount_minor')->nullable();
            $table->char('reporting_currency_code', 3)->nullable();
            $table->ulid('exchange_rate_id')->nullable();
            $table->string('rate_direction', 24)->nullable();
            $table->decimal('rate_value', 30, 18)->nullable();
            $table->timestamp('rate_effective_at')->nullable();
            $table->string('rate_provider', 80)->nullable();
            $table->string('rate_provider_reference', 160)->nullable();
            $table->timestamp('conversion_calculated_at')->nullable();
            $table->timestamp('listed_at');
            $table->unsignedBigInteger('sale_duration_seconds');
            $table->string('evidence_kind', 32);
            $table->string('evidence_reference', 255)->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->string('correction_reason', 64)->nullable();
            $table->string('note', 1000)->nullable();
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->char('input_hash', 64);
            $table->json('evidence_snapshot');
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->foreign('organization_id', 'actual_sales_org_fk')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('owned_product_id', 'actual_sales_product_fk')
                ->references('id')->on('owned_products')->cascadeOnDelete();
            $table->foreign('sale_portfolio_entry_id', 'actual_sales_entry_fk')
                ->references('id')->on('sale_portfolio_entries')->cascadeOnDelete();
            $table->foreign('sale_portfolio_event_id', 'actual_sales_event_fk')
                ->references('id')->on('sale_portfolio_events')->cascadeOnDelete();
            $table->foreign('previous_sale_id', 'actual_sales_previous_fk')
                ->references('id')->on('actual_sales')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'actual_sales_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('source_currency_code', 'actual_sales_source_currency_fk')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign(
                'reporting_currency_code',
                'actual_sales_reporting_currency_fk',
            )->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('exchange_rate_id', 'actual_sales_rate_fk')
                ->references('id')->on('exchange_rates')->restrictOnDelete();

            $table->unique(
                ['sale_portfolio_entry_id', 'sequence'],
                'actual_sales_entry_sequence_uq',
            );
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'actual_sales_org_idempotency_uq',
            );
            $table->index(
                ['organization_id', 'owned_product_id', 'outcome_type'],
                'actual_sales_org_product_outcome_idx',
            );
            $table->index('input_hash', 'actual_sales_input_hash_idx');
        });

        Schema::create('realized_profits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('owned_product_id');
            $table->ulid('actual_purchase_id');
            $table->ulid('actual_cost_snapshot_id');
            $table->ulid('actual_sale_id');
            $table->unsignedInteger('run_number');
            $table->string('calculation_version', 100);
            $table->char('calculation_key', 64);
            $table->char('input_hash', 64);
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('purchase_price_minor');
            $table->unsignedBigInteger('sale_price_minor');
            $table->unsignedBigInteger('actual_costs_minor');
            $table->unsignedBigInteger('total_invested_minor');
            $table->bigInteger('net_profit_minor');
            $table->bigInteger('profit_margin_basis_points')->nullable();
            $table->bigInteger('return_on_invested_capital_basis_points')->nullable();
            $table->unsignedBigInteger('sale_duration_seconds');
            $table->json('reason_codes');
            $table->json('input_snapshot');
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->foreign('organization_id', 'realized_profits_org_fk')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('owned_product_id', 'realized_profits_product_fk')
                ->references('id')->on('owned_products')->cascadeOnDelete();
            $table->foreign('actual_purchase_id', 'realized_profits_purchase_fk')
                ->references('id')->on('actual_purchases')->cascadeOnDelete();
            $table->foreign(
                'actual_cost_snapshot_id',
                'realized_profits_cost_snapshot_fk',
            )->references('id')->on('actual_cost_snapshots')->cascadeOnDelete();
            $table->foreign('actual_sale_id', 'realized_profits_sale_fk')
                ->references('id')->on('actual_sales')->cascadeOnDelete();
            $table->foreign('currency_code', 'realized_profits_currency_fk')
                ->references('code')->on('currencies')->restrictOnDelete();

            $table->unique('calculation_key', 'realized_profits_calculation_key_uq');
            $table->unique(
                ['owned_product_id', 'run_number'],
                'realized_profits_product_run_uq',
            );
            $table->index(
                ['organization_id', 'owned_product_id', 'created_at'],
                'realized_profits_org_product_idx',
            );
            $table->index('input_hash', 'realized_profits_input_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realized_profits');
        Schema::dropIfExists('actual_sales');
        Schema::dropIfExists('actual_cost_items');
        Schema::dropIfExists('actual_cost_snapshots');
        Schema::dropIfExists('actual_purchases');
    }
};
