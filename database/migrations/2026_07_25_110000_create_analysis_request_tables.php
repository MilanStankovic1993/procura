<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analyses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('listing_id')
                ->constrained('listings')
                ->cascadeOnDelete();
            $table->foreignUlid('listing_snapshot_id')
                ->constrained('listing_snapshots')
                ->restrictOnDelete();
            $table->foreignId('requested_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('analysis_type', 24);
            $table->string('status', 32)->default('draft');
            $table->char('source_country_code', 2);
            $table->char('target_country_code', 2);
            $table->string('pipeline_version', 100);
            $table->json('request_payload');
            $table->char('request_hash', 64);
            $table->json('result_payload')->nullable();
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->foreign('source_country_code')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->foreign('target_country_code')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->index(
                ['organization_id', 'status', 'created_at', 'id'],
                'analyses_tenant_status_index',
            );
            $table->index(
                ['organization_id', 'listing_id', 'created_at'],
                'analyses_tenant_listing_index',
            );
            $table->index(['status', 'next_retry_at'], 'analyses_retry_index');
            $table->index('request_hash');
        });

        Schema::create('analysis_dispatches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('analysis_id')
                ->constrained('analyses')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('run_number')->default(1);
            $table->string('pipeline_version', 100);
            $table->string('dispatch_key', 160)->unique();
            $table->string('status', 32)->default('pending');
            $table->string('queue_name', 64);
            $table->unsignedSmallInteger('dispatch_attempts')->default(0);
            $table->unsignedSmallInteger('max_processing_attempts')->default(3);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('last_dispatch_attempt_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['analysis_id', 'run_number']);
            $table->index(
                ['status', 'available_at', 'created_at'],
                'analysis_dispatches_recovery_index',
            );
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('ai_analyses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('analysis_id')
                ->constrained('analyses')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 32);
            $table->string('provider', 80);
            $table->string('model', 120);
            $table->string('prompt_version', 100);
            $table->char('input_hash', 64);
            $table->json('input_snapshot');
            $table->json('result_json')->nullable();
            $table->string('validation_status', 32)->default('pending');
            $table->unsignedSmallInteger('confidence_basis_points')->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->unsignedBigInteger('estimated_cost_minor')->nullable();
            $table->char('estimated_cost_currency', 3)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['analysis_id', 'attempt_number']);
            $table->index(
                ['organization_id', 'status', 'created_at'],
                'ai_analyses_tenant_status_index',
            );
            $table->index(['provider', 'model', 'prompt_version']);
            $table->index('input_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_analyses');
        Schema::dropIfExists('analysis_dispatches');
        Schema::dropIfExists('analyses');
    }
};
