<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 32);
            $table->unsignedSmallInteger('version');
            $table->string('name', 80);
            $table->string('description', 255);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
            $table->unique(['code', 'version']);
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->foreignUlid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature_code', 64);
            $table->boolean('is_enabled')->default(false);
            $table->unsignedBigInteger('limit')->nullable();
            $table->timestamps();
            $table->primary(['plan_id', 'feature_code']);
            $table->index('feature_code');
        });

        Schema::create('organization_plan_assignments', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->primary()->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['plan_id', 'ends_at']);
        });

        Schema::create('subscription_usages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('feature_code', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('used')->default(0);
            $table->timestamps();
            $table->unique(
                ['organization_id', 'feature_code', 'period_start'],
                'subscription_usage_period_unique',
            );
            $table->index(['organization_id', 'period_end']);
        });

        Schema::create('subscription_usage_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('subscription_usage_id')->constrained('subscription_usages')->cascadeOnDelete();
            $table->string('feature_code', 64);
            $table->string('idempotency_key', 160);
            $table->unsignedBigInteger('quantity');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['organization_id', 'feature_code', 'idempotency_key'], 'usage_events_idempotency_unique');
            $table->index(['subscription_usage_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_usage_events');
        Schema::dropIfExists('subscription_usages');
        Schema::dropIfExists('organization_plan_assignments');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plans');
    }
};
