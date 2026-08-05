<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignId('requester_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('status', 32);
            $table->string('title', 160);
            $table->foreignUlid('product_category_id')
                ->nullable()
                ->constrained('product_categories')
                ->restrictOnDelete();
            $table->text('product_description');
            $table->string('brand_preference', 120)->nullable();
            $table->string('model_preference', 160)->nullable();
            $table->string('condition_preference', 24);
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('budget_max_minor')->nullable();
            $table->char('budget_currency_code', 3)->nullable();
            $table->json('target_country_codes');
            $table->date('needed_by')->nullable();
            $table->text('notes')->nullable();
            $table->ulid('current_event_id')->nullable();
            $table->unsignedInteger('event_sequence')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('budget_currency_code', 'broker_requests_currency_fk')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->index(
                ['organization_id', 'status', 'created_at'],
                'broker_requests_org_status_idx',
            );
            $table->index(
                ['requester_user_id', 'status', 'created_at'],
                'broker_requests_requester_status_idx',
            );
            $table->index(
                ['organization_id', 'needed_by'],
                'broker_requests_org_needed_idx',
            );
        });

        Schema::create('broker_request_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('broker_request_id')
                ->constrained('broker_requests')
                ->cascadeOnDelete();
            $table->foreignUlid('previous_event_id')
                ->nullable()
                ->constrained('broker_request_events')
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
            $table->char('request_hash', 64);
            $table->json('request_snapshot');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->unique(
                ['broker_request_id', 'sequence'],
                'broker_request_events_request_sequence_uq',
            );
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'broker_request_events_org_idempotency_uq',
            );
            $table->index(
                ['organization_id', 'event_type', 'occurred_at'],
                'broker_request_events_org_type_idx',
            );
            $table->index('payload_hash', 'broker_request_events_payload_idx');
            $table->index('request_hash', 'broker_request_events_request_hash_idx');
        });

        Schema::table('broker_requests', function (Blueprint $table): void {
            $table->foreign('current_event_id', 'broker_requests_current_event_fk')
                ->references('id')
                ->on('broker_request_events')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('broker_requests', function (Blueprint $table): void {
            $table->dropForeign('broker_requests_current_event_fk');
        });

        Schema::dropIfExists('broker_request_events');
        Schema::dropIfExists('broker_requests');
    }
};
