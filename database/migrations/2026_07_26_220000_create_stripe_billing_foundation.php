<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('stripe_id')->nullable()->unique()->after('personal_user_id');
            $table->string('pm_type')->nullable()->after('stripe_id');
            $table->string('pm_last_four', 4)->nullable()->after('pm_type');
            $table->timestamp('trial_ends_at')->nullable()->after('pm_last_four');
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'stripe_status'], 'subscriptions_org_status_idx');
        });

        Schema::create('subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'stripe_price'], 'subscription_items_sub_price_idx');
        });

        Schema::table('organization_plan_assignments', function (Blueprint $table): void {
            $table->string('source', 24)->default('manual')->after('plan_id');
            $table->string('provider', 24)->nullable()->after('source');
            $table->string('provider_subscription_id')->nullable()->after('provider');
            $table->string('provider_price_id')->nullable()->after('provider_subscription_id');
            $table->string('provider_status', 32)->nullable()->after('provider_price_id');
            $table->string('provider_event_id')->nullable()->after('provider_status');
            $table->timestamp('provider_synced_at')->nullable()->after('provider_event_id');

            $table->index(
                ['provider', 'provider_subscription_id'],
                'org_plan_provider_subscription_idx',
            );
        });

        Schema::create('billing_checkout_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 24);
            $table->char('idempotency_hash', 64);
            $table->string('plan_code', 32);
            $table->string('billing_interval', 16);
            $table->string('provider_session_id')->unique();
            $table->string('provider_customer_id');
            $table->string('provider_price_id');
            $table->text('checkout_url');
            $table->string('status', 24)->default('open');
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['organization_id', 'idempotency_hash'],
                'billing_checkout_org_idempotency_uq',
            );
            $table->index(
                ['organization_id', 'status', 'expires_at'],
                'billing_checkout_org_status_idx',
            );
        });

        Schema::create('billing_provider_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider', 24);
            $table->string('provider_event_id');
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('event_type', 100);
            $table->string('provider_customer_id')->nullable();
            $table->string('provider_subscription_id')->nullable();
            $table->string('provider_price_id')->nullable();
            $table->string('provider_status', 32)->nullable();
            $table->char('payload_sha256', 64);
            $table->boolean('livemode');
            $table->string('outcome', 40);
            $table->string('reason_code', 80);
            $table->string('projected_plan_code', 32)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['provider', 'provider_event_id'],
                'billing_provider_event_uq',
            );
            $table->index(
                ['organization_id', 'occurred_at'],
                'billing_events_org_occurred_idx',
            );
            $table->index(
                ['provider_subscription_id', 'occurred_at'],
                'billing_events_subscription_occurred_idx',
            );
            $table->index(['outcome', 'processed_at'], 'billing_events_outcome_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_provider_events');
        Schema::dropIfExists('billing_checkout_sessions');

        Schema::table('organization_plan_assignments', function (Blueprint $table): void {
            $table->dropIndex('org_plan_provider_subscription_idx');
            $table->dropColumn([
                'source',
                'provider',
                'provider_subscription_id',
                'provider_price_id',
                'provider_status',
                'provider_event_id',
                'provider_synced_at',
            ]);
        });

        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropUnique(['stripe_id']);
            $table->dropColumn([
                'stripe_id',
                'pm_type',
                'pm_last_four',
                'trial_ends_at',
            ]);
        });
    }
};
