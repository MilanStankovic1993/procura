<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table): void {
            $table->unique(
                ['id', 'organization_id'],
                'analyses_id_org_uq',
            );
        });

        Schema::table('analysis_dispatches', function (Blueprint $table): void {
            $table->unique(
                ['id', 'analysis_id', 'organization_id'],
                'analysis_dispatches_id_analysis_org_uq',
            );
        });

        Schema::create('analysis_retry_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id');
            $table->foreignUlid('analysis_id');
            $table->foreignUlid('previous_dispatch_id');
            $table->foreignUlid('new_dispatch_id')->unique();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedSmallInteger('run_number');
            $table->unsignedSmallInteger('previous_processing_attempts');
            $table->timestamp('previous_failed_at')->nullable();
            $table->string('previous_error_code', 100)->nullable();
            $table->char('previous_error_hash', 64)->nullable();
            $table->text('reason');
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->timestamp('requested_at');
            $table->timestamp('created_at');

            $table->foreign(
                ['analysis_id', 'organization_id'],
                'analysis_retry_events_analysis_org_fk',
            )
                ->references(['id', 'organization_id'])
                ->on('analyses')
                ->cascadeOnDelete();
            $table->foreign(
                ['previous_dispatch_id', 'analysis_id', 'organization_id'],
                'analysis_retry_events_previous_dispatch_fk',
            )
                ->references(['id', 'analysis_id', 'organization_id'])
                ->on('analysis_dispatches')
                ->cascadeOnDelete();
            $table->foreign(
                ['new_dispatch_id', 'analysis_id', 'organization_id'],
                'analysis_retry_events_new_dispatch_fk',
            )
                ->references(['id', 'analysis_id', 'organization_id'])
                ->on('analysis_dispatches')
                ->cascadeOnDelete();
            $table->unique(
                ['analysis_id', 'run_number'],
                'analysis_retry_events_analysis_run_uq',
            );
            $table->unique(
                ['analysis_id', 'idempotency_key'],
                'analysis_retry_events_analysis_idempotency_uq',
            );
            $table->index(
                ['organization_id', 'requested_at'],
                'analysis_retry_events_org_requested_idx',
            );
            $table->index(
                ['actor_user_id', 'requested_at'],
                'analysis_retry_events_actor_requested_idx',
            );
            $table->index('payload_hash');
            $table->index('previous_error_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_retry_events');

        Schema::table('analysis_dispatches', function (Blueprint $table): void {
            $table->dropUnique(
                'analysis_dispatches_id_analysis_org_uq',
            );
        });

        Schema::table('analyses', function (Blueprint $table): void {
            $table->dropUnique('analyses_id_org_uq');
        });
    }
};
