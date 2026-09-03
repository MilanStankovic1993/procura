<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broker_requests', function (Blueprint $table): void {
            $table->unique(
                ['id', 'organization_id'],
                'broker_requests_id_org_uq',
            );
        });

        Schema::table('broker_request_events', function (Blueprint $table): void {
            $table->unique(
                ['id', 'broker_request_id', 'organization_id'],
                'broker_request_events_id_request_org_uq',
            );
        });

        Schema::create(
            'broker_request_offers',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('organization_id')
                    ->constrained('organizations')
                    ->cascadeOnDelete();
                $table->ulid('broker_request_id');
                $table->foreignId('presented_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->string('status', 24);
                $table->string('supplier_display_name', 160);
                $table->string('supplier_reference', 255);
                $table->text('item_description');
                $table->string('condition', 24);
                $table->unsignedSmallInteger('quantity');
                $table->unsignedBigInteger('unit_price_minor');
                $table->unsignedBigInteger('item_subtotal_minor');
                $table->unsignedBigInteger('shipping_cost_minor')->default(0);
                $table->unsignedBigInteger('tax_duty_cost_minor')->default(0);
                $table->unsignedBigInteger('other_cost_minor')->default(0);
                $table->unsignedBigInteger('total_minor');
                $table->char('currency_code', 3);
                $table->char('origin_country_code', 2)->nullable();
                $table->date('estimated_delivery_date')->nullable();
                $table->timestamp('valid_until');
                $table->unsignedSmallInteger('warranty_months')->nullable();
                $table->text('return_policy_summary')->nullable();
                $table->ulid('source_request_event_id');
                $table->ulid('current_event_id')->nullable();
                $table->unsignedInteger('event_sequence')->default(0);
                $table->timestamp('presented_at');
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->foreign(
                    ['broker_request_id', 'organization_id'],
                    'broker_offers_request_org_fk',
                )
                    ->references(['id', 'organization_id'])
                    ->on('broker_requests')
                    ->cascadeOnDelete();
                $table->foreign(
                    [
                        'source_request_event_id',
                        'broker_request_id',
                        'organization_id',
                    ],
                    'broker_offers_source_event_fk',
                )
                    ->references([
                        'id',
                        'broker_request_id',
                        'organization_id',
                    ])
                    ->on('broker_request_events')
                    ->restrictOnDelete();
                $table->foreign('currency_code', 'broker_offers_currency_fk')
                    ->references('code')
                    ->on('currencies')
                    ->restrictOnDelete();
                $table->foreign(
                    'origin_country_code',
                    'broker_offers_origin_country_fk',
                )
                    ->references('code')
                    ->on('countries')
                    ->restrictOnDelete();
                $table->unique(
                    ['id', 'broker_request_id', 'organization_id'],
                    'broker_offers_id_request_org_uq',
                );
                $table->index(
                    ['organization_id', 'status', 'created_at'],
                    'broker_offers_org_status_idx',
                );
                $table->index(
                    ['broker_request_id', 'status', 'total_minor'],
                    'broker_offers_request_status_total_idx',
                );
                $table->index(
                    ['status', 'valid_until'],
                    'broker_offers_status_valid_idx',
                );
            },
        );

        Schema::create(
            'broker_request_offer_events',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('organization_id')
                    ->constrained('organizations')
                    ->cascadeOnDelete();
                $table->ulid('broker_request_id');
                $table->ulid('broker_request_offer_id');
                $table->foreignUlid('previous_event_id')
                    ->nullable()
                    ->constrained('broker_request_offer_events')
                    ->restrictOnDelete();
                $table->foreignId('actor_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->unsignedInteger('sequence');
                $table->string('event_type', 24);
                $table->string('from_status', 24)->nullable();
                $table->string('to_status', 24);
                $table->string('reason_code', 64);
                $table->string('evidence_reference', 255)->nullable();
                $table->uuid('idempotency_key');
                $table->char('payload_hash', 64);
                $table->char('offer_hash', 64);
                $table->json('offer_snapshot');
                $table->timestamp('occurred_at');
                $table->timestamp('created_at');

                $table->foreign(
                    [
                        'broker_request_offer_id',
                        'broker_request_id',
                        'organization_id',
                    ],
                    'broker_offer_events_offer_request_org_fk',
                )
                    ->references([
                        'id',
                        'broker_request_id',
                        'organization_id',
                    ])
                    ->on('broker_request_offers')
                    ->cascadeOnDelete();
                $table->unique(
                    ['broker_request_offer_id', 'sequence'],
                    'broker_offer_events_offer_sequence_uq',
                );
                $table->unique(
                    ['broker_request_offer_id', 'idempotency_key'],
                    'broker_offer_events_offer_idempotency_uq',
                );
                $table->index(
                    ['organization_id', 'event_type', 'occurred_at'],
                    'broker_offer_events_org_type_idx',
                );
                $table->index(
                    ['broker_request_id', 'idempotency_key'],
                    'broker_offer_events_request_idempotency_idx',
                );
                $table->index('payload_hash', 'broker_offer_events_payload_idx');
                $table->index('offer_hash', 'broker_offer_events_offer_hash_idx');
            },
        );

        Schema::table(
            'broker_request_offers',
            function (Blueprint $table): void {
                $table->foreign(
                    'current_event_id',
                    'broker_offers_current_event_fk',
                )
                    ->references('id')
                    ->on('broker_request_offer_events')
                    ->restrictOnDelete();
            },
        );
    }

    public function down(): void
    {
        Schema::table(
            'broker_request_offers',
            function (Blueprint $table): void {
                $table->dropForeign('broker_offers_current_event_fk');
            },
        );
        Schema::dropIfExists('broker_request_offer_events');
        Schema::dropIfExists('broker_request_offers');

        Schema::table('broker_request_events', function (Blueprint $table): void {
            $table->dropUnique('broker_request_events_id_request_org_uq');
        });
        Schema::table('broker_requests', function (Blueprint $table): void {
            $table->dropUnique('broker_requests_id_org_uq');
        });
    }
};
