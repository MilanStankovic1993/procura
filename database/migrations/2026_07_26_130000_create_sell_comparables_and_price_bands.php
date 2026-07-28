<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owned_products', function (Blueprint $table): void {
            $table->unique(
                ['id', 'organization_id'],
                'owned_products_tenant_identity_unique',
            );
        });

        Schema::table('owned_product_assessments', function (Blueprint $table): void {
            $table->unique(
                ['id', 'owned_product_id'],
                'owned_product_assessments_product_identity_unique',
            );
        });

        Schema::create('sell_comparable_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id');
            $table->foreignUlid('owned_product_id');
            $table->ulid('owned_product_assessment_id');
            $table->foreignUlid('marketplace_source_id')
                ->constrained('marketplace_sources')
                ->restrictOnDelete();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignUlid('product_model_id')
                ->constrained('product_models')
                ->restrictOnDelete();
            $table->foreignUlid('product_variant_id')->nullable();
            $table->char('assessment_input_hash', 64);
            $table->char('source_identity_hash', 64);
            $table->char('evidence_hash', 64);
            $table->char('record_key', 64)->unique();
            $table->string('marketplace_name', 160);
            $table->string('marketplace_key', 80);
            $table->string('source_url', 2048)->nullable();
            $table->string('external_id', 128)->nullable();
            $table->string('title', 240);
            $table->text('description')->nullable();
            $table->string('listing_type', 32);
            $table->string('condition_code', 32);
            $table->string('seller_type', 32);
            $table->unsignedBigInteger('asking_price_minor');
            $table->char('currency_code', 3);
            $table->char('country_code', 2);
            $table->string('location', 255)->nullable();
            $table->json('included_accessories');
            $table->json('missing_accessories');
            $table->unsignedSmallInteger('source_reliability_basis_points');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('observed_at');
            $table->json('raw_input');
            $table->timestamps();

            $table->foreign(
                ['owned_product_id', 'organization_id'],
                'sell_comparable_records_product_tenant_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('owned_products')
                ->cascadeOnDelete();
            $table->foreign(
                ['owned_product_assessment_id', 'owned_product_id'],
                'sell_comparable_records_assessment_product_foreign',
            )
                ->references(['id', 'owned_product_id'])
                ->on('owned_product_assessments')
                ->cascadeOnDelete();
            $table->foreign(
                ['product_variant_id', 'product_model_id'],
                'sell_comparable_records_variant_model_foreign',
            )
                ->references(['id', 'product_model_id'])
                ->on('product_variants')
                ->restrictOnDelete();
            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->foreign('country_code')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->index(
                ['organization_id', 'owned_product_id', 'observed_at', 'id'],
                'sell_comparables_tenant_product_time_index',
            );
            $table->index(
                ['owned_product_assessment_id', 'country_code', 'currency_code'],
                'sell_comparables_assessment_market_index',
            );
            $table->index(
                ['organization_id', 'source_identity_hash', 'observed_at'],
                'sell_comparables_source_history_index',
            );
        });

        Schema::create('sell_comparable_selections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id');
            $table->foreignUlid('owned_product_id');
            $table->ulid('owned_product_assessment_id');
            $table->unsignedSmallInteger('run_number');
            $table->string('status', 32);
            $table->string('selector_version', 100);
            $table->char('input_hash', 64);
            $table->char('selection_key', 64)->unique();
            $table->char('target_country_code', 2);
            $table->char('target_currency_code', 3);
            $table->unsignedSmallInteger('candidate_count');
            $table->unsignedSmallInteger('included_count');
            $table->unsignedSmallInteger('excluded_count');
            $table->unsignedSmallInteger('minimum_required');
            $table->json('reason_codes');
            $table->json('input_snapshot');
            $table->timestamps();

            $table->foreign(
                ['owned_product_id', 'organization_id'],
                'sell_selections_product_tenant_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('owned_products')
                ->cascadeOnDelete();
            $table->foreign(
                ['owned_product_assessment_id', 'owned_product_id'],
                'sell_selections_assessment_product_foreign',
            )
                ->references(['id', 'owned_product_id'])
                ->on('owned_product_assessments')
                ->cascadeOnDelete();
            $table->foreign('target_country_code')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->foreign('target_currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->unique(
                ['owned_product_id', 'run_number'],
                'sell_selections_product_run_unique',
            );
            $table->index(
                ['organization_id', 'status', 'created_at'],
                'sell_selections_tenant_status_index',
            );
            $table->index(
                ['owned_product_assessment_id', 'target_country_code', 'target_currency_code'],
                'sell_selections_assessment_market_index',
            );
        });

        Schema::create('sell_comparable_selection_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('sell_comparable_selection_id');
            $table->ulid('sell_comparable_record_id');
            $table->foreign(
                'sell_comparable_selection_id',
                'sell_selection_items_selection_fk',
            )
                ->references('id')
                ->on('sell_comparable_selections')
                ->cascadeOnDelete();
            $table->foreign(
                'sell_comparable_record_id',
                'sell_selection_items_record_fk',
            )
                ->references('id')
                ->on('sell_comparable_records')
                ->cascadeOnDelete();
            $table->string('decision', 24);
            $table->unsignedSmallInteger('rank')->nullable();
            $table->unsignedSmallInteger('score_basis_points');
            $table->json('factor_scores');
            $table->json('reason_codes');
            $table->json('evidence_snapshot');
            $table->timestamps();

            $table->unique(
                ['sell_comparable_selection_id', 'sell_comparable_record_id'],
                'sell_selection_items_record_unique',
            );
            $table->index(
                ['sell_comparable_selection_id', 'decision', 'rank'],
                'sell_selection_items_decision_index',
            );
        });

        Schema::create('sell_price_bands', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id');
            $table->foreignUlid('owned_product_id');
            $table->ulid('owned_product_assessment_id');
            $table->ulid('sell_comparable_selection_id');
            $table->foreign(
                'sell_comparable_selection_id',
                'sell_price_bands_selection_fk',
            )
                ->references('id')
                ->on('sell_comparable_selections')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('run_number');
            $table->string('status', 32);
            $table->string('algorithm_version', 100);
            $table->char('input_hash', 64);
            $table->char('price_band_key', 64)->unique();
            $table->timestamp('calculated_at');
            $table->char('target_country_code', 2);
            $table->char('target_currency_code', 3);
            $table->unsignedSmallInteger('input_count');
            $table->unsignedSmallInteger('included_count');
            $table->unsignedSmallInteger('outlier_count');
            $table->unsignedBigInteger('quick_sale_low_minor')->nullable();
            $table->unsignedBigInteger('quick_sale_high_minor')->nullable();
            $table->unsignedBigInteger('recommended_low_minor')->nullable();
            $table->unsignedBigInteger('recommended_high_minor')->nullable();
            $table->unsignedBigInteger('ambitious_low_minor')->nullable();
            $table->unsignedBigInteger('ambitious_high_minor')->nullable();
            $table->unsignedBigInteger('median_minor')->nullable();
            $table->unsignedBigInteger('weighted_median_minor')->nullable();
            $table->unsignedBigInteger('q1_minor')->nullable();
            $table->unsignedBigInteger('q3_minor')->nullable();
            $table->unsignedBigInteger('mad_minor')->nullable();
            $table->unsignedInteger('dispersion_basis_points')->nullable();
            $table->unsignedSmallInteger('confidence_basis_points')->nullable();
            $table->string('confidence_level', 24)->nullable();
            $table->unsignedSmallInteger('completeness_basis_points');
            $table->json('confidence_components');
            $table->json('reason_codes');
            $table->json('unknown_facts');
            $table->json('verification_actions');
            $table->json('input_snapshot');
            $table->timestamps();

            $table->foreign(
                ['owned_product_id', 'organization_id'],
                'sell_price_bands_product_tenant_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('owned_products')
                ->cascadeOnDelete();
            $table->foreign(
                ['owned_product_assessment_id', 'owned_product_id'],
                'sell_price_bands_assessment_product_foreign',
            )
                ->references(['id', 'owned_product_id'])
                ->on('owned_product_assessments')
                ->cascadeOnDelete();
            $table->foreign('target_country_code')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->foreign('target_currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->unique(
                ['owned_product_id', 'run_number'],
                'sell_price_bands_product_run_unique',
            );
            $table->index(
                ['organization_id', 'status', 'created_at'],
                'sell_price_bands_tenant_status_index',
            );
            $table->index(
                ['owned_product_assessment_id', 'target_country_code', 'target_currency_code'],
                'sell_price_bands_assessment_market_index',
            );
        });

        Schema::create('sell_price_band_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('sell_price_band_id');
            $table->ulid('sell_comparable_selection_item_id');
            $table->ulid('sell_comparable_record_id');
            $table->foreign(
                'sell_price_band_id',
                'sell_band_items_band_fk',
            )
                ->references('id')
                ->on('sell_price_bands')
                ->cascadeOnDelete();
            $table->foreign(
                'sell_comparable_selection_item_id',
                'sell_band_items_selection_item_fk',
            )
                ->references('id')
                ->on('sell_comparable_selection_items')
                ->cascadeOnDelete();
            $table->foreign(
                'sell_comparable_record_id',
                'sell_band_items_record_fk',
            )
                ->references('id')
                ->on('sell_comparable_records')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('decision', 24);
            $table->unsignedBigInteger('asking_price_minor');
            $table->char('currency_code', 3);
            $table->unsignedSmallInteger('weight_basis_points');
            $table->json('reason_codes');
            $table->json('evidence_snapshot');
            $table->timestamps();

            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->unique(
                ['sell_price_band_id', 'sell_comparable_selection_item_id'],
                'sell_price_band_items_selection_unique',
            );
            $table->unique(
                ['sell_price_band_id', 'position'],
                'sell_price_band_items_position_unique',
            );
            $table->index(
                ['sell_price_band_id', 'decision', 'position'],
                'sell_price_band_items_decision_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sell_price_band_items');
        Schema::dropIfExists('sell_price_bands');
        Schema::dropIfExists('sell_comparable_selection_items');
        Schema::dropIfExists('sell_comparable_selections');
        Schema::dropIfExists('sell_comparable_records');

        Schema::table('owned_product_assessments', function (Blueprint $table): void {
            $table->dropUnique(
                'owned_product_assessments_product_identity_unique',
            );
        });

        Schema::table('owned_products', function (Blueprint $table): void {
            $table->dropUnique('owned_products_tenant_identity_unique');
        });
    }
};
