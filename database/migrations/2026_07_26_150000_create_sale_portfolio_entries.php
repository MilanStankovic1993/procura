<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'sale_portfolio_entries',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('organization_id');
                $table->ulid('owned_product_id');
                $table->ulid('sell_listing_draft_id');
                $table->foreignId('created_by_user_id');
                $table->unsignedInteger('sequence');
                $table->char('listing_draft_input_hash', 64);
                $table->char('assessment_input_hash', 64);
                $table->char('price_band_input_hash', 64);
                $table->char('image_evidence_hash', 64);
                $table->char('entry_key', 64);
                $table->char('payload_hash', 64);
                $table->uuid('idempotency_key');
                $table->char('target_country_code', 2);
                $table->char('target_currency_code', 3);
                $table->string('listing_language', 16);
                $table->string('price_strategy', 32);
                $table->unsignedBigInteger('initial_asking_price_minor');
                $table->json('source_identifiers');
                $table->json('input_snapshot');
                $table->timestamp('entered_at');
                $table->timestamps();

                $table->foreign(
                    'organization_id',
                    'sale_portfolio_entries_org_fk',
                )->references('id')->on('organizations')->cascadeOnDelete();
                $table->foreign(
                    'owned_product_id',
                    'sale_portfolio_entries_product_fk',
                )->references('id')->on('owned_products')->cascadeOnDelete();
                $table->foreign(
                    'sell_listing_draft_id',
                    'sale_portfolio_entries_draft_fk',
                )->references('id')->on('sell_listing_drafts')
                    ->cascadeOnDelete();
                $table->foreign(
                    'created_by_user_id',
                    'sale_portfolio_entries_creator_fk',
                )->references('id')->on('users')->restrictOnDelete();
                $table->foreign(
                    'target_country_code',
                    'sale_portfolio_entries_country_fk',
                )->references('code')->on('countries')->restrictOnDelete();
                $table->foreign(
                    'target_currency_code',
                    'sale_portfolio_entries_currency_fk',
                )->references('code')->on('currencies')->restrictOnDelete();

                $table->unique(
                    ['owned_product_id', 'sequence'],
                    'sale_portfolio_entries_product_sequence_uq',
                );
                $table->unique(
                    ['organization_id', 'sell_listing_draft_id'],
                    'sale_portfolio_entries_org_draft_uq',
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'sale_portfolio_entries_org_idempotency_uq',
                );
                $table->unique('entry_key', 'sale_portfolio_entries_key_uq');
                $table->index(
                    ['organization_id', 'owned_product_id', 'created_at'],
                    'sale_portfolio_entries_org_product_idx',
                );
            },
        );

        Schema::create(
            'sale_portfolio_events',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('organization_id');
                $table->ulid('sale_portfolio_entry_id');
                $table->ulid('previous_event_id')->nullable();
                $table->foreignId('actor_user_id');
                $table->unsignedInteger('sequence');
                $table->string('event_type', 32);
                $table->string('prior_status', 32);
                $table->string('next_status', 32);
                $table->string('marketplace_name', 160)->nullable();
                $table->string('marketplace_key', 80)->nullable();
                $table->string('external_listing_id', 128)->nullable();
                $table->string('external_listing_url', 2048)->nullable();
                $table->unsignedBigInteger('advertised_price_minor')
                    ->nullable();
                $table->char('advertised_currency_code', 3)->nullable();
                $table->string('reason_code', 64)->nullable();
                $table->string('note', 1000)->nullable();
                $table->uuid('idempotency_key');
                $table->char('payload_hash', 64);
                $table->json('input_snapshot');
                $table->timestamp('occurred_at');
                $table->timestamp('recorded_at');
                $table->timestamps();

                $table->foreign(
                    'organization_id',
                    'sale_portfolio_events_org_fk',
                )->references('id')->on('organizations')->cascadeOnDelete();
                $table->foreign(
                    'sale_portfolio_entry_id',
                    'sale_portfolio_events_entry_fk',
                )->references('id')->on('sale_portfolio_entries')
                    ->cascadeOnDelete();
                $table->foreign(
                    'previous_event_id',
                    'sale_portfolio_events_previous_fk',
                )->references('id')->on('sale_portfolio_events')
                    ->cascadeOnDelete();
                $table->foreign(
                    'actor_user_id',
                    'sale_portfolio_events_actor_fk',
                )->references('id')->on('users')->restrictOnDelete();
                $table->foreign(
                    'advertised_currency_code',
                    'sale_portfolio_events_currency_fk',
                )->references('code')->on('currencies')->restrictOnDelete();

                $table->unique(
                    ['sale_portfolio_entry_id', 'sequence'],
                    'sale_portfolio_events_entry_sequence_uq',
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'sale_portfolio_events_org_idempotency_uq',
                );
                $table->index(
                    [
                        'organization_id',
                        'sale_portfolio_entry_id',
                        'created_at',
                    ],
                    'sale_portfolio_events_org_entry_idx',
                );
                $table->index(
                    ['organization_id', 'marketplace_key', 'external_listing_id'],
                    'sale_portfolio_events_external_idx',
                );
                $table->index(
                    ['actor_user_id', 'recorded_at'],
                    'sale_portfolio_events_actor_time_idx',
                );
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_portfolio_events');
        Schema::dropIfExists('sale_portfolio_entries');
    }
};
