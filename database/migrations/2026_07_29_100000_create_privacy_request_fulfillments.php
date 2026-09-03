<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'privacy_request_fulfillments',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('privacy_request_id')
                    ->constrained('privacy_requests')
                    ->cascadeOnDelete();
                $table->foreignUlid('completion_event_id')
                    ->constrained('privacy_request_events')
                    ->restrictOnDelete();
                $table->foreignId('actor_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->string('request_type', 32);
                $table->string('execution_version', 100);
                $table->string('data_inventory_version', 100);
                $table->string('identity_evidence_reference', 255);
                $table->string('artifact_reference', 255)->nullable();
                $table->char('artifact_sha256', 64)->nullable();
                $table->unsignedBigInteger('artifact_size_bytes')->nullable();
                $table->timestamp('artifact_expires_at')->nullable();
                $table->string('delivery_evidence_reference', 255)->nullable();
                $table->json('clearance_references')->nullable();
                $table->uuid('idempotency_key');
                $table->char('payload_hash', 64);
                $table->timestamp('completed_at');
                $table->timestamp('created_at');

                $table->unique(
                    'privacy_request_id',
                    'privacy_fulfillments_request_uq',
                );
                $table->unique(
                    'completion_event_id',
                    'privacy_fulfillments_event_uq',
                );
                $table->unique(
                    ['privacy_request_id', 'idempotency_key'],
                    'privacy_fulfillments_idempotency_uq',
                );
                $table->index(
                    ['request_type', 'completed_at'],
                    'privacy_fulfillments_type_completed_idx',
                );
                $table->index('payload_hash');
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_request_fulfillments');
    }
};
