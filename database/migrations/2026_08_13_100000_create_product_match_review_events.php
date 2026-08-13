<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_matches', function (Blueprint $table): void {
            $table->unique(
                ['id', 'organization_id', 'analysis_id'],
                'product_matches_id_org_analysis_uq',
            );
        });

        Schema::create('product_match_review_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id');
            $table->foreignUlid('analysis_id');
            $table->foreignUlid('product_match_id')->unique();
            $table->foreignUlid('result_product_match_id')->nullable()->unique();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('decision', 16);
            $table->foreignUlid('product_model_id')->nullable();
            $table->foreignUlid('product_variant_id')->nullable();
            $table->foreignUlid('product_alias_id')->nullable();
            $table->text('reason');
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->timestamp('reviewed_at');
            $table->timestamp('created_at');

            $table->foreign(
                ['product_match_id', 'organization_id', 'analysis_id'],
                'product_match_reviews_source_match_fk',
            )
                ->references(['id', 'organization_id', 'analysis_id'])
                ->on('product_matches')
                ->cascadeOnDelete();
            $table->foreign(
                ['result_product_match_id', 'organization_id', 'analysis_id'],
                'product_match_reviews_result_match_fk',
            )
                ->references(['id', 'organization_id', 'analysis_id'])
                ->on('product_matches')
                ->cascadeOnDelete();
            $table->foreign('product_model_id')
                ->references('id')
                ->on('product_models')
                ->restrictOnDelete();
            $table->foreign(
                ['product_variant_id', 'product_model_id'],
                'product_match_reviews_variant_model_fk',
            )
                ->references(['id', 'product_model_id'])
                ->on('product_variants')
                ->restrictOnDelete();
            $table->foreign('product_alias_id')
                ->references('id')
                ->on('product_aliases')
                ->restrictOnDelete();
            $table->unique(
                ['analysis_id', 'idempotency_key'],
                'product_match_reviews_analysis_idempotency_uq',
            );
            $table->index(
                ['organization_id', 'decision', 'reviewed_at'],
                'product_match_reviews_org_decision_reviewed_idx',
            );
            $table->index(
                ['actor_user_id', 'reviewed_at'],
                'product_match_reviews_actor_reviewed_idx',
            );
            $table->index('payload_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_match_review_events');

        Schema::table('product_matches', function (Blueprint $table): void {
            $table->dropUnique('product_matches_id_org_analysis_uq');
        });
    }
};
