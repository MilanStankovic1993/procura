<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comparable_market_normalizations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('analysis_id')
                ->constrained('analyses')
                ->cascadeOnDelete();
            $table->foreignUlid('comparable_record_id')
                ->constrained('comparable_records')
                ->cascadeOnDelete();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignUlid('exchange_rate_id')
                ->nullable()
                ->constrained('exchange_rates')
                ->restrictOnDelete();
            $table->char('normalization_key', 64)->unique();
            $table->char('evidence_hash', 64);
            $table->string('calculation_version', 100);
            $table->string('compatibility_status', 32);
            $table->char('source_country_code', 2);
            $table->char('target_country_code', 2);
            $table->char('source_currency_code', 3);
            $table->char('target_currency_code', 3);
            $table->unsignedBigInteger('source_amount_minor');
            $table->unsignedBigInteger('converted_amount_minor')->nullable();
            $table->unsignedSmallInteger('market_factor_basis_points')->nullable();
            $table->unsignedBigInteger('market_adjusted_amount_minor')->nullable();
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->unsignedBigInteger('import_duty_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('other_cost_minor')->default(0);
            $table->unsignedBigInteger('normalized_amount_minor')->nullable();
            $table->string('rate_direction', 24)->nullable();
            $table->decimal('rate_value', 30, 18)->nullable();
            $table->timestamp('rate_effective_at')->nullable();
            $table->string('rate_provider', 80)->nullable();
            $table->string('rate_provider_reference', 160)->nullable();
            $table->string('evidence_reference', 2048);
            $table->text('compatibility_note');
            $table->json('reason_codes');
            $table->json('raw_evidence');
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->foreign('source_country_code')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->foreign('target_country_code')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->foreign('source_currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->foreign('target_currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->index(
                ['analysis_id', 'comparable_record_id', 'created_at', 'id'],
                'comparable_normalizations_analysis_record_index',
            );
            $table->index(
                ['organization_id', 'compatibility_status', 'created_at'],
                'comparable_normalizations_tenant_status_index',
            );
            $table->index(
                ['target_country_code', 'target_currency_code', 'observed_at'],
                'comparable_normalizations_target_market_index',
            );
            $table->index('evidence_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comparable_market_normalizations');
    }
};
