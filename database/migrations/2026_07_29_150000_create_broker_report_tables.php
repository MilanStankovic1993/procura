<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'broker_commission_events',
            function (Blueprint $table): void {
                $table->unique(
                    [
                        'id',
                        'broker_commission_id',
                        'broker_transaction_id',
                        'broker_request_id',
                        'broker_request_offer_id',
                        'organization_id',
                    ],
                    'broker_commission_events_id_commission_uq',
                );
            },
        );

        Schema::create('broker_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->ulid('broker_request_id');
            $table->ulid('broker_request_offer_id');
            $table->ulid('broker_transaction_id');
            $table->ulid('broker_commission_id');
            $table->foreignId('generated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('status', 16);
            $table->string('report_version', 64);
            $table->string('locale', 16);
            $table->unsignedInteger('sequence');
            $table->ulid('source_transaction_event_id');
            $table->ulid('source_commission_event_id');
            $table->ulid('current_event_id')->nullable();
            $table->unsignedInteger('event_sequence')->default(0);
            $table->uuid('idempotency_key');
            $table->char('generation_payload_hash', 64);
            $table->string('disk', 64);
            $table->string('path', 512);
            $table->string('filename', 160);
            $table->string('mime_type', 64)->default('application/pdf');
            $table->char('artifact_sha256', 64);
            $table->unsignedBigInteger('artifact_size_bytes');
            $table->unsignedSmallInteger('page_count');
            $table->char('report_hash', 64);
            $table->json('report_snapshot');
            $table->timestamp('generated_at');
            $table->timestamp('artifact_expires_at');
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->foreign(
                [
                    'broker_transaction_id',
                    'broker_request_id',
                    'broker_request_offer_id',
                    'organization_id',
                ],
                'broker_reports_transaction_fk',
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
                    'broker_commission_id',
                    'broker_transaction_id',
                    'broker_request_id',
                    'broker_request_offer_id',
                    'organization_id',
                ],
                'broker_reports_commission_fk',
            )
                ->references([
                    'id',
                    'broker_transaction_id',
                    'broker_request_id',
                    'broker_request_offer_id',
                    'organization_id',
                ])
                ->on('broker_commissions')
                ->restrictOnDelete();
            $table->foreign(
                [
                    'source_transaction_event_id',
                    'broker_transaction_id',
                    'broker_request_id',
                    'broker_request_offer_id',
                    'organization_id',
                ],
                'broker_reports_transaction_event_fk',
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
            $table->foreign(
                [
                    'source_commission_event_id',
                    'broker_commission_id',
                    'broker_transaction_id',
                    'broker_request_id',
                    'broker_request_offer_id',
                    'organization_id',
                ],
                'broker_reports_commission_event_fk',
            )
                ->references([
                    'id',
                    'broker_commission_id',
                    'broker_transaction_id',
                    'broker_request_id',
                    'broker_request_offer_id',
                    'organization_id',
                ])
                ->on('broker_commission_events')
                ->restrictOnDelete();
            $table->unique(
                ['broker_transaction_id', 'sequence'],
                'broker_reports_transaction_sequence_uq',
            );
            $table->unique(
                ['broker_transaction_id', 'idempotency_key'],
                'broker_reports_transaction_idempotency_uq',
            );
            $table->unique(
                [
                    'broker_transaction_id',
                    'source_transaction_event_id',
                    'source_commission_event_id',
                    'report_version',
                    'locale',
                ],
                'broker_reports_source_version_locale_uq',
            );
            $table->unique(
                [
                    'id',
                    'broker_transaction_id',
                    'broker_commission_id',
                    'broker_request_id',
                    'broker_request_offer_id',
                    'organization_id',
                ],
                'broker_reports_id_transaction_commission_uq',
            );
            $table->index(
                ['organization_id', 'status', 'artifact_expires_at'],
                'broker_reports_org_status_expiry_idx',
            );
            $table->index('report_hash', 'broker_reports_hash_idx');
            $table->index(
                'artifact_sha256',
                'broker_reports_artifact_hash_idx',
            );
        });

        Schema::create(
            'broker_report_events',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('organization_id')
                    ->constrained('organizations')
                    ->cascadeOnDelete();
                $table->ulid('broker_request_id');
                $table->ulid('broker_request_offer_id');
                $table->ulid('broker_transaction_id');
                $table->ulid('broker_commission_id');
                $table->ulid('broker_report_id');
                $table->foreignUlid('previous_event_id')
                    ->nullable()
                    ->constrained('broker_report_events')
                    ->restrictOnDelete();
                $table->foreignId('actor_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->unsignedInteger('sequence');
                $table->string('event_type', 16);
                $table->string('from_status', 16)->nullable();
                $table->string('to_status', 16);
                $table->string('reason_code', 64);
                $table->string('evidence_reference', 255)->nullable();
                $table->uuid('idempotency_key');
                $table->char('payload_hash', 64);
                $table->char('report_hash', 64);
                $table->json('report_snapshot');
                $table->timestamp('occurred_at');
                $table->timestamp('created_at');

                $table->foreign(
                    [
                        'broker_report_id',
                        'broker_transaction_id',
                        'broker_commission_id',
                        'broker_request_id',
                        'broker_request_offer_id',
                        'organization_id',
                    ],
                    'broker_report_events_report_fk',
                )
                    ->references([
                        'id',
                        'broker_transaction_id',
                        'broker_commission_id',
                        'broker_request_id',
                        'broker_request_offer_id',
                        'organization_id',
                    ])
                    ->on('broker_reports')
                    ->cascadeOnDelete();
                $table->unique(
                    ['broker_report_id', 'sequence'],
                    'broker_report_events_sequence_uq',
                );
                $table->unique(
                    ['broker_report_id', 'idempotency_key'],
                    'broker_report_events_idempotency_uq',
                );
                $table->index(
                    ['organization_id', 'event_type', 'occurred_at'],
                    'broker_report_events_org_type_idx',
                );
                $table->index(
                    'payload_hash',
                    'broker_report_events_payload_idx',
                );
            },
        );

        Schema::table('broker_reports', function (Blueprint $table): void {
            $table->foreign(
                'current_event_id',
                'broker_reports_current_event_fk',
            )
                ->references('id')
                ->on('broker_report_events')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('broker_reports', function (Blueprint $table): void {
            $table->dropForeign('broker_reports_current_event_fk');
        });
        Schema::dropIfExists('broker_report_events');
        Schema::dropIfExists('broker_reports');

        Schema::table(
            'broker_commission_events',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'broker_commission_events_id_commission_uq',
                );
            },
        );
    }
};
