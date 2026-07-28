<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('parent_id')
                ->nullable()
                ->constrained('product_categories')
                ->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['parent_id', 'active', 'name']);
        });

        Schema::create('brands', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('normalized_name', 140)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'name']);
        });

        Schema::create('product_models', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained('brands')->restrictOnDelete();
            $table->foreignUlid('product_category_id')
                ->constrained('product_categories')
                ->restrictOnDelete();
            $table->string('name', 160);
            $table->string('normalized_name', 180);
            $table->string('model_number', 120);
            $table->string('normalized_model_number', 140);
            $table->string('canonical_key', 220)->unique();
            $table->json('specifications')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(
                ['brand_id', 'normalized_model_number'],
                'product_models_brand_number_unique',
            );
            $table->index(['active', 'normalized_name']);
            $table->index(['active', 'normalized_model_number']);
            $table->index(['product_category_id', 'active']);
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_model_id')
                ->constrained('product_models')
                ->restrictOnDelete();
            $table->string('name', 180);
            $table->string('canonical_key', 240)->unique();
            $table->string('sku', 120)->nullable();
            $table->string('normalized_sku', 140)->nullable();
            $table->json('attributes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(
                ['id', 'product_model_id'],
                'product_variants_model_identity_unique',
            );
            $table->index(['product_model_id', 'active']);
            $table->index(['normalized_sku', 'active']);
        });

        Schema::create('product_variant_markets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_variant_id')
                ->constrained('product_variants')
                ->cascadeOnDelete();
            $table->char('country_code', 2);
            $table->string('market_model_number', 120)->nullable();
            $table->unsignedInteger('voltage_millivolts')->nullable();
            $table->string('plug_type', 32)->nullable();
            $table->string('measurement_system', 16)->nullable();
            $table->boolean('warranty_applicable')->nullable();
            $table->json('included_accessories')->nullable();
            $table->timestamps();

            $table->foreign('country_code')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->unique(
                ['product_variant_id', 'country_code'],
                'product_variant_markets_country_unique',
            );
            $table->index(['country_code', 'product_variant_id']);
        });

        Schema::create('product_aliases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_model_id')
                ->constrained('product_models')
                ->cascadeOnDelete();
            $table->foreignUlid('product_variant_id')->nullable();
            $table->string('alias', 180);
            $table->string('normalized_alias', 200);
            $table->string('locale', 35)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('source', 40)->default('catalog');
            $table->char('alias_key', 64)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('country_code')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->foreign(
                ['product_variant_id', 'product_model_id'],
                'product_aliases_variant_model_foreign',
            )
                ->references(['id', 'product_model_id'])
                ->on('product_variants')
                ->cascadeOnDelete();
            $table->index(['normalized_alias', 'active']);
            $table->index(['product_model_id', 'active']);
            $table->index(['product_variant_id', 'active']);
            $table->index(['country_code', 'active']);
        });

        Schema::create('product_matches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('analysis_id')
                ->constrained('analyses')
                ->cascadeOnDelete();
            $table->foreignUlid('ai_analysis_id')
                ->constrained('ai_analyses')
                ->cascadeOnDelete();
            $table->foreignUlid('product_model_id')
                ->nullable()
                ->constrained('product_models')
                ->restrictOnDelete();
            $table->foreignUlid('product_variant_id')->nullable();
            $table->unsignedSmallInteger('run_number');
            $table->string('status', 32);
            $table->string('review_status', 32);
            $table->string('method', 80);
            $table->string('matcher_version', 100);
            $table->char('input_hash', 64);
            $table->char('match_key', 64)->unique();
            $table->unsignedSmallInteger('confidence_basis_points');
            $table->json('candidate_snapshot');
            $table->json('reason_codes');
            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['analysis_id', 'run_number']);
            $table->foreign(
                ['product_variant_id', 'product_model_id'],
                'product_matches_variant_model_foreign',
            )
                ->references(['id', 'product_model_id'])
                ->on('product_variants')
                ->restrictOnDelete();
            $table->index(
                ['organization_id', 'status', 'created_at'],
                'product_matches_tenant_status_index',
            );
            $table->index(
                ['review_status', 'created_at'],
                'product_matches_review_queue_index',
            );
            $table->index(['product_model_id', 'status']);
            $table->index(['ai_analysis_id', 'created_at']);
            $table->index('input_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_matches');
        Schema::dropIfExists('product_aliases');
        Schema::dropIfExists('product_variant_markets');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_models');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('product_categories');
    }
};
