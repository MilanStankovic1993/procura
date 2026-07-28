<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owned_product_snapshots', function (Blueprint $table): void {
            $table->unique(
                ['id', 'owned_product_id'],
                'owned_product_snapshots_product_identity_unique',
            );
        });

        Schema::create('owned_product_assessments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('owned_product_id')
                ->constrained('owned_products')
                ->cascadeOnDelete();
            $table->ulid('owned_product_snapshot_id');
            $table->foreignId('assessed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedSmallInteger('run_number');
            $table->string('status', 32);
            $table->string('matcher_status', 32);
            $table->string('review_status', 32);
            $table->string('method', 80);
            $table->string('matcher_version', 100);
            $table->string('evaluator_version', 100);
            $table->char('snapshot_content_hash', 64);
            $table->char('image_evidence_hash', 64);
            $table->char('input_hash', 64);
            $table->char('assessment_key', 64)->unique();
            $table->unsignedSmallInteger('confidence_basis_points');
            $table->unsignedSmallInteger('completeness_basis_points');
            $table->foreignUlid('product_category_id')
                ->nullable()
                ->constrained('product_categories')
                ->restrictOnDelete();
            $table->foreignUlid('product_model_id')
                ->nullable()
                ->constrained('product_models')
                ->restrictOnDelete();
            $table->foreignUlid('product_variant_id')->nullable();
            $table->string('identified_brand_name', 160)->nullable();
            $table->string('identified_model_name', 200)->nullable();
            $table->string('identified_variant_name', 180)->nullable();
            $table->string('condition', 32);
            $table->json('included_accessories')->nullable();
            $table->json('missing_accessories')->nullable();
            $table->json('defects')->nullable();
            $table->json('candidate_snapshot');
            $table->json('reason_codes');
            $table->json('unknown_facts');
            $table->json('verification_actions');
            $table->json('input_snapshot');
            $table->timestamp('assessed_at');
            $table->timestamps();

            $table->foreign(
                ['owned_product_snapshot_id', 'owned_product_id'],
                'owned_product_assessments_snapshot_product_foreign',
            )
                ->references(['id', 'owned_product_id'])
                ->on('owned_product_snapshots')
                ->cascadeOnDelete();
            $table->foreign(
                ['product_variant_id', 'product_model_id'],
                'owned_product_assessments_variant_model_foreign',
            )
                ->references(['id', 'product_model_id'])
                ->on('product_variants')
                ->restrictOnDelete();
            $table->unique(
                ['owned_product_id', 'run_number'],
                'owned_product_assessments_product_run_unique',
            );
            $table->index(
                ['organization_id', 'status', 'created_at'],
                'owned_product_assessments_tenant_status_index',
            );
            $table->index(
                ['owned_product_snapshot_id', 'created_at'],
                'owned_product_assessments_snapshot_index',
            );
            $table->index(
                ['product_model_id', 'status'],
                'owned_product_assessments_model_status_index',
            );
            $table->index('input_hash', 'owned_product_assessments_input_hash_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owned_product_assessments');

        Schema::table('owned_product_snapshots', function (Blueprint $table): void {
            $table->dropUnique('owned_product_snapshots_product_identity_unique');
        });
    }
};
