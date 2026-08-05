<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_payment_cases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->ulid('broker_request_id');
            $table->ulid('broker_request_offer_id');
            $table->ulid('broker_transaction_id');
            $table->ulid('source_transaction_event_id');
            $table->foreignId('opened_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('type', 16);
            $table->string('status', 24);
            $table->unsignedBigInteger('requested_amount_minor');
            $table->unsignedBigInteger('resolved_amount_minor')->nullable();
            $table->char('currency_code', 3);
            $table->string('resolution_outcome', 32)->nullable();
            $table->string('external_case_reference', 255);
            $table->char('logical_case_hash', 64);
            $table->ulid('current_event_id')->nullable();
            $table->unsignedInteger('event_sequence')->default(0);
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign(
                [
                    'broker_transaction_id',
                    'broker_request_id',
                    'broker_request_offer_id',
                    'organization_id',
                ],
                'broker_payment_cases_transaction_fk',
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
                'broker_payment_cases_source_event_fk',
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
            $table->foreign('currency_code', 'broker_payment_cases_currency_fk')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->unique(
                ['broker_transaction_id', 'type', 'logical_case_hash'],
                'broker_payment_cases_transaction_logical_uq',
            );
            $table->unique(
                [
                    'id',
                    'broker_transaction_id',
                    'broker_request_id',
                    'broker_request_offer_id',
                    'organization_id',
                ],
                'broker_payment_cases_id_transaction_uq',
            );
            $table->index(
                ['organization_id', 'status', 'type', 'created_at'],
                'broker_payment_cases_org_status_type_idx',
            );
        });

        Schema::create(
            'broker_payment_case_events',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('organization_id')
                    ->constrained('organizations')
                    ->cascadeOnDelete();
                $table->ulid('broker_request_id');
                $table->ulid('broker_request_offer_id');
                $table->ulid('broker_transaction_id');
                $table->ulid('broker_payment_case_id');
                $table->foreignUlid('previous_event_id')
                    ->nullable()
                    ->constrained('broker_payment_case_events')
                    ->restrictOnDelete();
                $table->foreignId('actor_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->unsignedInteger('sequence');
                $table->string('event_type', 24);
                $table->string('from_status', 24)->nullable();
                $table->string('to_status', 24);
                $table->string('resolution_outcome', 32)->nullable();
                $table->string('reason_code', 64);
                $table->string('evidence_reference', 255);
                $table->uuid('idempotency_key');
                $table->char('payload_hash', 64);
                $table->char('payment_case_hash', 64);
                $table->json('payment_case_snapshot');
                $table->timestamp('occurred_at');
                $table->timestamp('created_at');

                $table->foreign(
                    [
                        'broker_payment_case_id',
                        'broker_transaction_id',
                        'broker_request_id',
                        'broker_request_offer_id',
                        'organization_id',
                    ],
                    'broker_payment_case_events_case_fk',
                )
                    ->references([
                        'id',
                        'broker_transaction_id',
                        'broker_request_id',
                        'broker_request_offer_id',
                        'organization_id',
                    ])
                    ->on('broker_payment_cases')
                    ->cascadeOnDelete();
                $table->unique(
                    ['broker_payment_case_id', 'sequence'],
                    'broker_payment_case_events_sequence_uq',
                );
                $table->unique(
                    ['broker_transaction_id', 'idempotency_key'],
                    'broker_payment_case_events_idempotency_uq',
                );
                $table->unique(
                    [
                        'id',
                        'broker_payment_case_id',
                        'broker_transaction_id',
                        'broker_request_id',
                        'broker_request_offer_id',
                        'organization_id',
                    ],
                    'broker_payment_case_events_id_case_uq',
                );
                $table->index(
                    ['organization_id', 'event_type', 'occurred_at'],
                    'broker_payment_case_events_org_type_idx',
                );
                $table->index(
                    'payload_hash',
                    'broker_payment_case_events_payload_idx',
                );
            },
        );

        Schema::table('broker_payment_cases', function (Blueprint $table): void {
            $table->foreign(
                'current_event_id',
                'broker_payment_cases_current_event_fk',
            )
                ->references('id')
                ->on('broker_payment_case_events')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('broker_payment_cases', function (Blueprint $table): void {
            $table->dropForeign('broker_payment_cases_current_event_fk');
        });
        Schema::dropIfExists('broker_payment_case_events');
        Schema::dropIfExists('broker_payment_cases');
    }
};
