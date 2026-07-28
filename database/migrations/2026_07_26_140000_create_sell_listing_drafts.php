<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sell_price_bands', function (Blueprint $table): void {
            $table->unique(
                ['id', 'owned_product_id'],
                'sell_price_bands_product_identity_unique',
            );
        });

        Schema::create('sell_listing_drafts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id');
            $table->foreignUlid('owned_product_id');
            $table->ulid('owned_product_assessment_id');
            $table->ulid('sell_price_band_id');
            $table->foreignId('generated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedSmallInteger('run_number');
            $table->string('status', 32);
            $table->string('photo_readiness_status', 32);
            $table->unsignedSmallInteger('photo_readiness_basis_points');
            $table->string('listing_language', 16);
            $table->string('template_version', 100);
            $table->string('generator_version', 100);
            $table->string('photo_evaluator_version', 100);
            $table->char('assessment_input_hash', 64);
            $table->char('price_band_input_hash', 64);
            $table->char('image_evidence_hash', 64);
            $table->char('input_hash', 64);
            $table->char('draft_key', 64)->unique();
            $table->timestamp('generated_at');
            $table->char('target_country_code', 2);
            $table->char('target_currency_code', 3);
            $table->string('price_strategy', 24);
            $table->unsignedBigInteger('target_asking_price_minor');
            $table->unsignedBigInteger('selected_band_low_minor');
            $table->unsignedBigInteger('selected_band_high_minor');
            $table->text('price_override_reason')->nullable();
            $table->string('title', 240);
            $table->longText('description');
            $table->unsignedSmallInteger('completeness_basis_points');
            $table->json('reason_codes');
            $table->json('unknown_facts');
            $table->json('warnings');
            $table->json('verification_actions');
            $table->json('source_fact_identifiers');
            $table->json('input_snapshot');
            $table->timestamps();

            $table->foreign(
                ['owned_product_id', 'organization_id'],
                'sell_listing_drafts_product_tenant_fk',
            )
                ->references(['id', 'organization_id'])
                ->on('owned_products')
                ->cascadeOnDelete();
            $table->foreign(
                ['owned_product_assessment_id', 'owned_product_id'],
                'sell_listing_drafts_assessment_product_fk',
            )
                ->references(['id', 'owned_product_id'])
                ->on('owned_product_assessments')
                ->cascadeOnDelete();
            $table->foreign(
                ['sell_price_band_id', 'owned_product_id'],
                'sell_listing_drafts_band_product_fk',
            )
                ->references(['id', 'owned_product_id'])
                ->on('sell_price_bands')
                ->cascadeOnDelete();
            $table->foreign(
                'target_country_code',
                'sell_listing_drafts_country_fk',
            )
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->foreign(
                'target_currency_code',
                'sell_listing_drafts_currency_fk',
            )
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->unique(
                ['owned_product_id', 'run_number'],
                'sell_listing_drafts_product_run_unique',
            );
            $table->index(
                ['organization_id', 'status', 'created_at'],
                'sell_listing_drafts_tenant_status_index',
            );
            $table->index(
                [
                    'owned_product_assessment_id',
                    'target_country_code',
                    'target_currency_code',
                    'listing_language',
                ],
                'sell_listing_drafts_current_scope_index',
            );
        });

        Schema::create('sell_listing_draft_facts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('sell_listing_draft_id');
            $table->unsignedSmallInteger('position');
            $table->string('fact_code', 80);
            $table->string('source_kind', 48);
            $table->string('source_id', 64)->nullable();
            $table->string('source_field', 100);
            $table->boolean('is_unknown')->default(false);
            $table->text('disclosure');
            $table->json('value_snapshot');
            $table->timestamps();

            $table->foreign(
                'sell_listing_draft_id',
                'sell_listing_facts_draft_fk',
            )
                ->references('id')
                ->on('sell_listing_drafts')
                ->cascadeOnDelete();
            $table->unique(
                ['sell_listing_draft_id', 'position'],
                'sell_listing_facts_position_unique',
            );
            $table->index(
                ['sell_listing_draft_id', 'fact_code'],
                'sell_listing_facts_code_index',
            );
        });

        Schema::create('sell_listing_photo_check_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('sell_listing_draft_id');
            $table->unsignedSmallInteger('position');
            $table->string('check_code', 80);
            $table->string('status', 32);
            $table->boolean('required');
            $table->string('image_kind', 32)->nullable();
            $table->unsignedSmallInteger('minimum_count');
            $table->unsignedSmallInteger('observed_count');
            $table->json('matching_image_ids');
            $table->json('reason_codes');
            $table->json('verification_actions');
            $table->json('evidence_snapshot');
            $table->timestamps();

            $table->foreign(
                'sell_listing_draft_id',
                'sell_listing_photo_items_draft_fk',
            )
                ->references('id')
                ->on('sell_listing_drafts')
                ->cascadeOnDelete();
            $table->unique(
                ['sell_listing_draft_id', 'position'],
                'sell_listing_photo_items_position_unique',
            );
            $table->index(
                ['sell_listing_draft_id', 'status', 'position'],
                'sell_listing_photo_items_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sell_listing_photo_check_items');
        Schema::dropIfExists('sell_listing_draft_facts');
        Schema::dropIfExists('sell_listing_drafts');

        Schema::table('sell_price_bands', function (Blueprint $table): void {
            $table->dropUnique('sell_price_bands_product_identity_unique');
        });
    }
};
