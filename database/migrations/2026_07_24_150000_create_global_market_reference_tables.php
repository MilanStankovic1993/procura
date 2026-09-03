<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('continents', function (Blueprint $table): void {
            $table->char('code', 2)->primary();
            $table->string('name', 64);
            $table->unsignedTinyInteger('sort_order')->unique();
            $table->boolean('active')->default(true)->index();
            $table->string('source_version', 32);
            $table->timestamps();
        });

        Schema::create('currencies', function (Blueprint $table): void {
            $table->char('code', 3)->primary();
            $table->char('numeric_code', 3)->nullable()->index();
            $table->string('name', 128);
            $table->string('symbol', 16);
            $table->unsignedTinyInteger('minor_unit');
            $table->unsignedTinyInteger('cash_minor_unit');
            $table->boolean('active')->default(true)->index();
            $table->string('source_version', 32);
            $table->timestamps();
        });

        Schema::create('countries', function (Blueprint $table): void {
            $table->char('code', 2)->primary();
            $table->char('alpha3_code', 3)->unique();
            $table->char('numeric_code', 3)->unique();
            $table->char('continent_code', 2);
            $table->string('name', 128);
            $table->char('currency_code', 3)->nullable();
            $table->string('measurement_system', 16)->default('metric');
            $table->boolean('active')->default(true)->index();
            $table->string('source_version', 32);
            $table->timestamps();

            $table->foreign('continent_code')->references('code')->on('continents');
            $table->foreign('currency_code')->references('code')->on('currencies')->nullOnDelete();
            $table->index(['continent_code', 'active', 'name']);
            $table->index(['currency_code', 'active']);
        });

        Schema::create('organization_market_preferences', function (Blueprint $table): void {
            $table->foreignUlid('organization_id')
                ->primary()
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->char('home_country_code', 2)->nullable();
            $table->char('reporting_currency_code', 3)->nullable();
            $table->string('locale', 35)->default('en');
            $table->string('timezone', 64)->default('UTC');
            $table->string('measurement_system', 16)->default('metric');
            $table->boolean('include_cross_border')->default(false);
            $table->timestamps();

            $table->foreign('home_country_code')->references('code')->on('countries');
            $table->foreign('reporting_currency_code')->references('code')->on('currencies');
        });

        Schema::create('organization_market_countries', function (Blueprint $table): void {
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->char('country_code', 2);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->foreign('country_code')->references('code')->on('countries');
            $table->primary(['organization_id', 'country_code']);
            $table->unique(['organization_id', 'sort_order']);
            $table->index(['country_code', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_market_countries');
        Schema::dropIfExists('organization_market_preferences');
        Schema::dropIfExists('countries');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('continents');
    }
};
