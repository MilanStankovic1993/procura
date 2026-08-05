<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broker_request_offers', function (Blueprint $table): void {
            $table->string('commission_rule_version', 64)
                ->default('broker-commission:v1')
                ->after('total_minor');
            $table->unsignedSmallInteger('commission_rate_basis_points')
                ->default(0)
                ->after('commission_rule_version');
            $table->unsignedBigInteger('commission_base_minor')
                ->default(0)
                ->after('commission_rate_basis_points');
            $table->unsignedBigInteger('commission_amount_minor')
                ->default(0)
                ->after('commission_base_minor');
            $table->unsignedBigInteger('payable_total_minor')
                ->default(0)
                ->after('commission_amount_minor');
        });

        Schema::table(
            'broker_request_offer_events',
            function (Blueprint $table): void {
                $table->unique(
                    [
                        'id',
                        'broker_request_offer_id',
                        'broker_request_id',
                        'organization_id',
                    ],
                    'broker_offer_events_id_offer_request_org_uq',
                );
            },
        );

        Schema::create('broker_transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->ulid('broker_request_id');
            $table->ulid('broker_request_offer_id');
            $table->foreignId('opened_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('status', 32);
            $table->unsignedBigInteger('supplier_total_minor');
            $table->unsignedBigInteger('commission_amount_minor');
            $table->unsignedBigInteger('payable_total_minor');
            $table->char('currency_code', 3);
            $table->ulid('source_request_event_id');
            $table->ulid('source_offer_event_id');
            $table->ulid('current_event_id')->nullable();
            $table->unsignedInteger('event_sequence')->default(0);
            $table->timestamp('opened_at');
            $table->timestamp('payment_confirmed_at')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign(
                ['broker_request_id', 'organization_id'],
                'broker_transactions_request_org_fk',
            )
                ->references(['id', 'organization_id'])
                ->on('broker_requests')
                ->cascadeOnDelete();
            $table->foreign(
                [
                    'broker_request_offer_id',
                    'broker_request_id',
                    'organization_id',
                ],
                'broker_transactions_offer_request_org_fk',
            )
                ->references([
                    'id',
                    'broker_request_id',
                    'organization_id',
                ])
                ->on('broker_request_offers')
                ->restrictOnDelete();
            $table->foreign(
                [
                    'source_request_event_id',
                    'broker_request_id',
                    'organization_id',
                ],
                'broker_transactions_request_event_fk',
            )
                ->references(['id', 'broker_request_id', 'organization_id'])
                ->on('broker_request_events')
                ->restrictOnDelete();
            $table->foreign(
                [
                    'source_offer_event_id',
                    'broker_request_offer_id',
                    'broker_request_id',
                    'organization_id',
                ],
                'broker_transactions_offer_event_fk',
            )
                ->references([
                    'id',
                    'broker_request_offer_id',
                    'broker_request_id',
                    'organization_id',
                ])
                ->on('broker_request_offer_events')
                ->restrictOnDelete();
            $table->foreign('currency_code', 'broker_transactions_currency_fk')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->unique(
                ['broker_request_id'],
                'broker_transactions_request_uq',
            );
            $table->unique(
                ['broker_request_offer_id'],
                'broker_transactions_offer_uq',
            );
            $table->unique(
                [
                    'id',
                    'broker_request_id',
                    'broker_request_offer_id',
                    'organization_id',
                ],
                'broker_transactions_id_request_offer_org_uq',
            );
            $table->index(
                ['organization_id', 'status', 'created_at'],
                'broker_transactions_org_status_idx',
            );
        });

        Schema::create(
            'broker_transaction_events',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('organization_id')
                    ->constrained('organizations')
                    ->cascadeOnDelete();
                $table->ulid('broker_request_id');
                $table->ulid('broker_request_offer_id');
                $table->ulid('broker_transaction_id');
                $table->foreignUlid('previous_event_id')
                    ->nullable()
                    ->constrained('broker_transaction_events')
                    ->restrictOnDelete();
                $table->foreignId('actor_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->unsignedInteger('sequence');
                $table->string('event_type', 32);
                $table->string('from_status', 32)->nullable();
                $table->string('to_status', 32);
                $table->string('reason_code', 64);
                $table->string('evidence_reference', 255)->nullable();
                $table->uuid('idempotency_key');
                $table->char('payload_hash', 64);
                $table->char('transaction_hash', 64);
                $table->json('transaction_snapshot');
                $table->timestamp('occurred_at');
                $table->timestamp('created_at');

                $table->foreign(
                    [
                        'broker_transaction_id',
                        'broker_request_id',
                        'broker_request_offer_id',
                        'organization_id',
                    ],
                    'broker_transaction_events_transaction_fk',
                )
                    ->references([
                        'id',
                        'broker_request_id',
                        'broker_request_offer_id',
                        'organization_id',
                    ])
                    ->on('broker_transactions')
                    ->cascadeOnDelete();
                $table->unique(
                    ['broker_transaction_id', 'sequence'],
                    'broker_transaction_events_sequence_uq',
                );
                $table->unique(
                    ['broker_transaction_id', 'idempotency_key'],
                    'broker_transaction_events_idempotency_uq',
                );
                $table->unique(
                    [
                        'id',
                        'broker_transaction_id',
                        'broker_request_id',
                        'broker_request_offer_id',
                        'organization_id',
                    ],
                    'broker_transaction_events_id_transaction_uq',
                );
                $table->index(
                    ['organization_id', 'event_type', 'occurred_at'],
                    'broker_transaction_events_org_type_idx',
                );
                $table->index(
                    'payload_hash',
                    'broker_transaction_events_payload_idx',
                );
            },
        );

        Schema::table('broker_transactions', function (Blueprint $table): void {
            $table->foreign(
                'current_event_id',
                'broker_transactions_current_event_fk',
            )
                ->references('id')
                ->on('broker_transaction_events')
                ->restrictOnDelete();
        });

        Schema::create('broker_commissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->ulid('broker_request_id');
            $table->ulid('broker_request_offer_id');
            $table->ulid('broker_transaction_id');
            $table->string('status', 24);
            $table->string('rule_version', 64);
            $table->unsignedSmallInteger('rate_basis_points');
            $table->unsignedBigInteger('base_minor');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->ulid('source_transaction_event_id');
            $table->ulid('current_event_id')->nullable();
            $table->unsignedInteger('event_sequence')->default(0);
            $table->timestamp('recorded_at');
            $table->timestamp('earned_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('waived_at')->nullable();
            $table->timestamps();

            $table->foreign(
                [
                    'broker_transaction_id',
                    'broker_request_id',
                    'broker_request_offer_id',
                    'organization_id',
                ],
                'broker_commissions_transaction_fk',
            )
                ->references([
                    'id',
                    'broker_request_id',
                    'broker_request_offer_id',
                    'organization_id',
                ])
                ->on('broker_transactions')
                ->cascadeOnDelete();
            $table->foreign(
                [
                    'source_transaction_event_id',
                    'broker_transaction_id',
                    'broker_request_id',
                    'broker_request_offer_id',
                    'organization_id',
                ],
                'broker_commissions_source_event_fk',
            )
                ->references([
                    'id',
                    'broker_transaction_id',
                    'broker_request_id',
                    'broker_request_offer_id',
                    'organization_id',
                ])
                ->on('broker_transaction_events')
                ->restrictOnDelete();
            $table->foreign('currency_code', 'broker_commissions_currency_fk')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->unique(
                ['broker_transaction_id'],
                'broker_commissions_transaction_uq',
            );
            $table->unique(
                [
                    'id',
                    'broker_transaction_id',
                    'broker_request_id',
                    'broker_request_offer_id',
                    'organization_id',
                ],
                'broker_commissions_id_transaction_uq',
            );
            $table->index(
                ['organization_id', 'status', 'created_at'],
                'broker_commissions_org_status_idx',
            );
        });

        Schema::create(
            'broker_commission_events',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('organization_id')
                    ->constrained('organizations')
                    ->cascadeOnDelete();
                $table->ulid('broker_request_id');
                $table->ulid('broker_request_offer_id');
                $table->ulid('broker_transaction_id');
                $table->ulid('broker_commission_id');
                $table->foreignUlid('previous_event_id')
                    ->nullable()
                    ->constrained('broker_commission_events')
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
                $table->char('commission_hash', 64);
                $table->json('commission_snapshot');
                $table->timestamp('occurred_at');
                $table->timestamp('created_at');

                $table->foreign(
                    [
                        'broker_commission_id',
                        'broker_transaction_id',
                        'broker_request_id',
                        'broker_request_offer_id',
                        'organization_id',
                    ],
                    'broker_commission_events_commission_fk',
                )
                    ->references([
                        'id',
                        'broker_transaction_id',
                        'broker_request_id',
                        'broker_request_offer_id',
                        'organization_id',
                    ])
                    ->on('broker_commissions')
                    ->cascadeOnDelete();
                $table->unique(
                    ['broker_commission_id', 'sequence'],
                    'broker_commission_events_sequence_uq',
                );
                $table->unique(
                    ['broker_commission_id', 'idempotency_key'],
                    'broker_commission_events_idempotency_uq',
                );
                $table->index(
                    ['organization_id', 'event_type', 'occurred_at'],
                    'broker_commission_events_org_type_idx',
                );
                $table->index(
                    'payload_hash',
                    'broker_commission_events_payload_idx',
                );
            },
        );

        Schema::table('broker_commissions', function (Blueprint $table): void {
            $table->foreign(
                'current_event_id',
                'broker_commissions_current_event_fk',
            )
                ->references('id')
                ->on('broker_commission_events')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('broker_commissions', function (Blueprint $table): void {
            $table->dropForeign('broker_commissions_current_event_fk');
        });
        Schema::dropIfExists('broker_commission_events');
        Schema::dropIfExists('broker_commissions');

        Schema::table('broker_transactions', function (Blueprint $table): void {
            $table->dropForeign('broker_transactions_current_event_fk');
        });
        Schema::dropIfExists('broker_transaction_events');
        Schema::dropIfExists('broker_transactions');

        Schema::table(
            'broker_request_offer_events',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'broker_offer_events_id_offer_request_org_uq',
                );
            },
        );

        Schema::table('broker_request_offers', function (Blueprint $table): void {
            $table->dropColumn([
                'commission_rule_version',
                'commission_rate_basis_points',
                'commission_base_minor',
                'commission_amount_minor',
                'payable_total_minor',
            ]);
        });
    }
};
