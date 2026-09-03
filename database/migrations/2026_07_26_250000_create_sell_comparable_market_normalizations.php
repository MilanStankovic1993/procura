<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sell_comparable_market_normalizations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id');
            $table->foreignUlid('owned_product_id');
            $table->ulid('owned_product_assessment_id');
            $table->ulid('sell_comparable_record_id');
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

            $table->foreign(
                ['owned_product_id', 'organization_id'],
                'sell_norm_product_tenant_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('owned_products')
                ->cascadeOnDelete();
            $table->foreign(
                ['owned_product_assessment_id', 'owned_product_id'],
                'sell_norm_assessment_product_foreign',
            )
                ->references(['id', 'owned_product_id'])
                ->on('owned_product_assessments')
                ->cascadeOnDelete();
            $table->foreign(
                'sell_comparable_record_id',
                'sell_norm_comparable_foreign',
            )
                ->references('id')
                ->on('sell_comparable_records')
                ->cascadeOnDelete();
            $table->foreign(
                'source_country_code',
                'sell_norm_source_country_foreign',
            )
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->foreign(
                'target_country_code',
                'sell_norm_target_country_foreign',
            )
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->foreign(
                'source_currency_code',
                'sell_norm_source_currency_foreign',
            )
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->foreign(
                'target_currency_code',
                'sell_norm_target_currency_foreign',
            )
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
            $table->index(
                [
                    'owned_product_assessment_id',
                    'sell_comparable_record_id',
                    'target_country_code',
                    'target_currency_code',
                    'observed_at',
                ],
                'sell_norm_assessment_record_target_index',
            );
            $table->index(
                ['organization_id', 'compatibility_status', 'created_at'],
                'sell_norm_tenant_status_index',
            );
            $table->index(
                ['target_country_code', 'target_currency_code', 'observed_at'],
                'sell_norm_target_market_index',
            );
            $table->index('evidence_hash', 'sell_norm_evidence_hash_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sell_comparable_market_normalizations');
    }
};
