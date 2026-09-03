<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_provider_budget_periods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->date('period_start');
            $table->string('scope_type', 24);
            $table->string('scope_id', 32);
            $table->unsignedBigInteger('reserved_cost_minor')->default(0);
            $table->unsignedBigInteger('consumed_cost_minor')->default(0);
            $table->timestamps();

            $table->unique(
                ['period_start', 'scope_type', 'scope_id'],
                'ai_budget_period_scope_unique',
            );
            $table->index(
                ['period_start', 'scope_type'],
                'ai_budget_period_scope_index',
            );
        });

        Schema::create('analysis_provider_circuits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider', 80);
            $table->string('model', 120);
            $table->string('state', 24);
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->foreignUlid('probe_ai_analysis_id')
                ->nullable()
                ->constrained('ai_analyses')
                ->nullOnDelete();
            $table->string('last_failure_code', 120)->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'model']);
            $table->index(['state', 'retry_at']);
        });

        Schema::create('analysis_provider_usages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignUlid('analysis_id')
                ->constrained('analyses')
                ->cascadeOnDelete();
            $table->foreignUlid('ai_analysis_id')
                ->unique()
                ->constrained('ai_analyses')
                ->cascadeOnDelete();
            $table->string('provider', 80);
            $table->string('model', 120);
            $table->date('period_start');
            $table->string('status', 24);
            $table->unsignedBigInteger('reserved_cost_minor');
            $table->unsignedBigInteger('actual_cost_minor')->nullable();
            $table->char('cost_currency', 3)->default('USD');
            $table->string('failure_code', 120)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['organization_id', 'period_start', 'status'],
                'ai_usage_org_period_status_index',
            );
            $table->index(
                ['user_id', 'period_start', 'status'],
                'ai_usage_user_period_status_index',
            );
            $table->index(
                ['provider', 'model', 'status', 'started_at'],
                'ai_usage_provider_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_provider_usages');
        Schema::dropIfExists('analysis_provider_circuits');
        Schema::dropIfExists('analysis_provider_budget_periods');
    }
};
