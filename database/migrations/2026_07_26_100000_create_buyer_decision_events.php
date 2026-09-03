<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'buyer_decision_events',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('organization_id')
                    ->constrained('organizations')
                    ->cascadeOnDelete();
                $table->foreignUlid('analysis_id')
                    ->constrained('analyses')
                    ->cascadeOnDelete();
                $table->foreignUlid('deal_score_id')
                    ->constrained('deal_scores')
                    ->cascadeOnDelete();
                $table->foreignUlid('previous_event_id')
                    ->nullable()
                    ->constrained('buyer_decision_events')
                    ->cascadeOnDelete();
                $table->foreignId('actor_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->unsignedSmallInteger('sequence');
                $table->string('prior_state', 32)->nullable();
                $table->string('next_state', 32);
                $table->string('reason_code', 64)->nullable();
                $table->string('note', 1000)->nullable();
                $table->uuid('idempotency_key');
                $table->char('payload_hash', 64);
                $table->timestamp('decided_at');
                $table->timestamps();

                $table->unique(
                    ['analysis_id', 'sequence'],
                    'buyer_decisions_analysis_sequence_unique',
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'buyer_decisions_tenant_idempotency_unique',
                );
                $table->index(
                    ['organization_id', 'analysis_id', 'created_at'],
                    'buyer_decisions_tenant_analysis_index',
                );
                $table->index(
                    ['deal_score_id', 'sequence'],
                    'buyer_decisions_score_sequence_index',
                );
                $table->index(
                    ['actor_user_id', 'decided_at'],
                    'buyer_decisions_actor_time_index',
                );
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_decision_events');
    }
};
