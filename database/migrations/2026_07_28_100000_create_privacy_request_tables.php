<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('subject_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->char('requester_email_hash', 64);
            $table->string('type', 32);
            $table->string('status', 32);
            $table->char('active_key', 64)->nullable()->unique();
            $table->ulid('current_event_id')->nullable();
            $table->unsignedInteger('event_sequence')->default(0);
            $table->char('residence_country_code', 2)->nullable();
            $table->string('preferred_locale', 12);
            $table->text('reason')->nullable();
            $table->json('blocking_reason_codes');
            $table->string('workflow_version', 100);
            $table->string('privacy_notice_version', 100);
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->timestamp('requested_at');
            $table->timestamp('response_target_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('residence_country_code', 'privacy_requests_country_fk')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->unique(
                ['subject_user_id', 'idempotency_key'],
                'privacy_requests_subject_idempotency_uq',
            );
            $table->index(
                ['subject_user_id', 'requested_at'],
                'privacy_requests_subject_requested_idx',
            );
            $table->index(
                ['status', 'response_target_at'],
                'privacy_requests_status_target_idx',
            );
            $table->index(['type', 'status'], 'privacy_requests_type_status_idx');
            $table->index('payload_hash');
        });

        Schema::create('privacy_request_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('privacy_request_id')
                ->constrained('privacy_requests')
                ->cascadeOnDelete();
            $table->foreignUlid('previous_event_id')
                ->nullable()
                ->constrained('privacy_request_events')
                ->cascadeOnDelete();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('prior_status', 32)->nullable();
            $table->string('next_status', 32);
            $table->string('actor_type', 24);
            $table->string('reason_code', 80);
            $table->text('note')->nullable();
            $table->string('evidence_reference', 255)->nullable();
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->unique(
                ['privacy_request_id', 'sequence'],
                'privacy_request_events_sequence_uq',
            );
            $table->unique(
                ['privacy_request_id', 'idempotency_key'],
                'privacy_request_events_idempotency_uq',
            );
            $table->index(
                ['privacy_request_id', 'occurred_at'],
                'privacy_request_events_request_time_idx',
            );
            $table->index(
                ['next_status', 'occurred_at'],
                'privacy_request_events_status_time_idx',
            );
            $table->index('payload_hash');
        });

        Schema::table('privacy_requests', function (Blueprint $table): void {
            $table->foreign('current_event_id', 'privacy_requests_current_event_fk')
                ->references('id')
                ->on('privacy_request_events')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('privacy_requests', function (Blueprint $table): void {
            $table->dropForeign('privacy_requests_current_event_fk');
        });

        Schema::dropIfExists('privacy_request_events');
        Schema::dropIfExists('privacy_requests');
    }
};
