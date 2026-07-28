<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comparable_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
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

            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->foreign('country_code')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->foreign(
                ['product_variant_id', 'product_model_id'],
                'comparable_records_variant_model_foreign',
            )
                ->references(['id', 'product_model_id'])
                ->on('product_variants')
                ->restrictOnDelete();
            $table->index(
                ['organization_id', 'product_model_id', 'observed_at', 'id'],
                'comparable_records_tenant_model_time_index',
            );
            $table->index(
                ['organization_id', 'source_identity_hash', 'observed_at'],
                'comparable_records_source_history_index',
            );
            $table->index(
                ['product_variant_id', 'country_code', 'currency_code'],
                'comparable_records_variant_market_index',
            );
        });

        Schema::create('comparable_sets', function (Blueprint $table): void {
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
            $table->unsignedSmallInteger('run_number');
            $table->string('status', 32);
            $table->string('selector_version', 100);
            $table->char('input_hash', 64);
            $table->char('selection_key', 64)->unique();
            $table->char('target_country_code', 2);
            $table->char('target_currency_code', 3)->nullable();
            $table->unsignedSmallInteger('candidate_count');
            $table->unsignedSmallInteger('included_count');
            $table->unsignedSmallInteger('excluded_count');
            $table->unsignedSmallInteger('minimum_required');
            $table->json('reason_codes');
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
                'comparable_sets_tenant_status_index',
            );
            $table->index(['product_match_id', 'created_at']);
            $table->index('input_hash');
        });

        Schema::create('comparable_set_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('comparable_set_id')
                ->constrained('comparable_sets')
                ->cascadeOnDelete();
            $table->foreignUlid('comparable_record_id')
                ->constrained('comparable_records')
                ->cascadeOnDelete();
            $table->string('decision', 24);
            $table->unsignedSmallInteger('rank')->nullable();
            $table->unsignedSmallInteger('score_basis_points');
            $table->json('factor_scores');
            $table->json('reason_codes');
            $table->json('evidence_snapshot');
            $table->timestamps();

            $table->unique(
                ['comparable_set_id', 'comparable_record_id'],
                'comparable_set_items_record_unique',
            );
            $table->index(
                ['comparable_set_id', 'decision', 'rank'],
                'comparable_set_items_decision_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comparable_set_items');
        Schema::dropIfExists('comparable_sets');
        Schema::dropIfExists('comparable_records');
    }
};
