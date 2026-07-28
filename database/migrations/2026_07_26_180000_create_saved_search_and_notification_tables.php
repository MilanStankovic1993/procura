<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignId('owner_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('title', 120);
            $table->boolean('active')->default(true);
            $table->ulid('current_version_id')->nullable();
            $table->unsignedInteger('version_sequence')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(
                ['organization_id', 'archived_at', 'active', 'created_at'],
                'saved_searches_org_state_idx',
            );
            $table->index(
                ['owner_user_id', 'archived_at', 'created_at'],
                'saved_searches_owner_idx',
            );
        });

        Schema::create('saved_search_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('saved_search_id')
                ->constrained('saved_searches')
                ->cascadeOnDelete();
            $table->foreignUlid('previous_version_id')
                ->nullable()
                ->constrained('saved_search_versions')
                ->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('title', 120);
            $table->boolean('active');
            $table->foreignUlid('product_category_id')
                ->nullable()
                ->constrained('product_categories')
                ->restrictOnDelete();
            $table->foreignUlid('brand_id')
                ->nullable()
                ->constrained('brands')
                ->restrictOnDelete();
            $table->foreignUlid('product_model_id')
                ->nullable()
                ->constrained('product_models')
                ->restrictOnDelete();
            $table->unsignedBigInteger('minimum_price_minor')->nullable();
            $table->unsignedBigInteger('maximum_price_minor')->nullable();
            $table->char('price_currency_code', 3)->nullable();
            $table->char('continent_code', 2)->nullable();
            $table->json('country_codes');
            $table->string('city', 120)->nullable();
            $table->unsignedSmallInteger('radius_km')->nullable();
            $table->boolean('include_cross_border')->default(false);
            $table->json('required_keywords');
            $table->json('excluded_keywords');
            $table->bigInteger('minimum_profit_minor')->nullable();
            $table->char('profit_currency_code', 3)->nullable();
            $table->integer('minimum_margin_basis_points')->nullable();
            $table->unsignedInteger('minimum_deal_score_basis_points')->nullable();
            $table->unsignedTinyInteger('maximum_risk_score')->nullable();
            $table->json('notification_channels');
            $table->string('reason_code', 64);
            $table->uuid('idempotency_key');
            $table->char('criteria_hash', 64);
            $table->char('payload_hash', 64);
            $table->json('criteria_snapshot');
            $table->timestamp('created_at');

            $table->foreign('price_currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->foreign('profit_currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->foreign('continent_code')
                ->references('code')
                ->on('continents')
                ->restrictOnDelete();
            $table->unique(
                ['saved_search_id', 'sequence'],
                'saved_search_versions_sequence_uq',
            );
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'saved_search_versions_idempotency_uq',
            );
            $table->index(
                ['organization_id', 'saved_search_id', 'created_at'],
                'saved_search_versions_org_search_idx',
            );
            $table->index(
                [
                    'organization_id',
                    'active',
                    'price_currency_code',
                    'continent_code',
                ],
                'saved_search_versions_match_idx',
            );
            $table->index('criteria_hash');
            $table->index('payload_hash');
        });

        Schema::table('saved_searches', function (Blueprint $table): void {
            $table->foreign('current_version_id', 'saved_searches_current_version_fk')
                ->references('id')
                ->on('saved_search_versions')
                ->nullOnDelete();
        });

        Schema::create('saved_search_matches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('saved_search_id')
                ->constrained('saved_searches')
                ->cascadeOnDelete();
            $table->foreignUlid('saved_search_version_id')
                ->constrained('saved_search_versions')
                ->cascadeOnDelete();
            $table->foreignUlid('listing_id')
                ->constrained('listings')
                ->cascadeOnDelete();
            $table->foreignUlid('listing_snapshot_id')
                ->constrained('listing_snapshots')
                ->cascadeOnDelete();
            $table->foreignUlid('analysis_id')
                ->nullable()
                ->constrained('analyses')
                ->cascadeOnDelete();
            $table->foreignUlid('product_match_id')
                ->nullable()
                ->constrained('product_matches')
                ->cascadeOnDelete();
            $table->foreignUlid('profit_estimate_id')
                ->nullable()
                ->constrained('profit_estimates')
                ->cascadeOnDelete();
            $table->foreignUlid('risk_assessment_id')
                ->nullable()
                ->constrained('risk_assessments')
                ->cascadeOnDelete();
            $table->foreignUlid('deal_score_id')
                ->nullable()
                ->constrained('deal_scores')
                ->cascadeOnDelete();
            $table->string('status', 32);
            $table->string('matcher_version', 100);
            $table->char('match_key', 64)->unique();
            $table->json('reason_codes');
            $table->json('unknown_criteria');
            $table->json('evidence_snapshot');
            $table->timestamp('evaluated_at');
            $table->timestamp('created_at');

            $table->index(
                ['saved_search_version_id', 'listing_snapshot_id'],
                'saved_search_matches_evidence_idx',
            );
            $table->index(
                ['organization_id', 'saved_search_id', 'status', 'evaluated_at'],
                'saved_search_matches_search_status_idx',
            );
            $table->index(
                ['organization_id', 'listing_id', 'status', 'evaluated_at'],
                'saved_search_matches_listing_status_idx',
            );
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignId('recipient_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignUlid('saved_search_id')
                ->constrained('saved_searches')
                ->cascadeOnDelete();
            $table->foreignUlid('saved_search_version_id')
                ->constrained('saved_search_versions')
                ->cascadeOnDelete();
            $table->foreignUlid('saved_search_match_id')
                ->constrained('saved_search_matches')
                ->cascadeOnDelete();
            $table->foreignUlid('listing_id')
                ->constrained('listings')
                ->cascadeOnDelete();
            $table->foreignUlid('listing_snapshot_id')
                ->constrained('listing_snapshots')
                ->cascadeOnDelete();
            $table->string('alert_type', 48);
            $table->char('alert_key', 64)->unique();
            $table->json('payload');
            $table->timestamp('triggered_at');
            $table->timestamp('created_at');

            $table->unique(
                [
                    'recipient_user_id',
                    'listing_id',
                    'saved_search_id',
                    'alert_type',
                ],
                'alerts_recipient_listing_search_type_uq',
            );
            $table->index(
                ['organization_id', 'recipient_user_id', 'triggered_at'],
                'alerts_org_recipient_idx',
            );
        });

        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('alert_id')
                ->constrained('alerts')
                ->cascadeOnDelete();
            $table->foreignId('recipient_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignUlid('previous_log_id')
                ->nullable()
                ->constrained('notification_logs')
                ->cascadeOnDelete();
            $table->string('channel', 24);
            $table->string('event_type', 24);
            $table->unsignedInteger('sequence');
            $table->uuid('idempotency_key')->nullable();
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->unique(
                ['alert_id', 'channel', 'sequence'],
                'notification_logs_alert_channel_sequence_uq',
            );
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'notification_logs_idempotency_uq',
            );
            $table->index(
                [
                    'organization_id',
                    'recipient_user_id',
                    'channel',
                    'event_type',
                    'occurred_at',
                ],
                'notification_logs_inbox_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('saved_search_matches');
        Schema::table('saved_searches', function (Blueprint $table): void {
            $table->dropForeign('saved_searches_current_version_fk');
        });
        Schema::dropIfExists('saved_search_versions');
        Schema::dropIfExists('saved_searches');
    }
};
