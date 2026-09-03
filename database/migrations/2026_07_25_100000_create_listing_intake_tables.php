<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('name', 100);
            $table->string('connector_type', 32);
            $table->json('capabilities');
            $table->json('supported_country_codes')->nullable();
            $table->json('supported_currency_codes')->nullable();
            $table->boolean('cross_border_supported')->default(false);
            $table->date('terms_reviewed_at')->nullable();
            $table->string('legal_basis', 255)->nullable();
            $table->text('allowed_operations')->nullable();
            $table->text('prohibited_operations')->nullable();
            $table->text('rate_limits')->nullable();
            $table->text('data_retention_rules')->nullable();
            $table->text('attribution_rules')->nullable();
            $table->string('contact_person', 160)->nullable();
            $table->text('review_notes')->nullable();
            $table->unsignedTinyInteger('reliability_score')->nullable();
            $table->unsignedTinyInteger('freshness_score')->nullable();
            $table->unsignedTinyInteger('completeness_score')->nullable();
            $table->boolean('asking_price_only')->default(true);
            $table->boolean('transaction_price_supported')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['connector_type', 'active']);
        });

        Schema::create('listings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUlid('marketplace_source_id')
                ->constrained('marketplace_sources')
                ->restrictOnDelete();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('source_url', 2048)->nullable();
            $table->string('external_id', 128)->nullable();
            $table->string('marketplace_name', 160);
            $table->string('marketplace_key', 80);
            $table->string('title', 240);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('asking_price_minor')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->text('seller_information')->nullable();
            $table->string('location', 255)->nullable();
            $table->char('source_country_code', 2);
            $table->char('target_country_code', 2);
            $table->string('status', 32)->default('unknown');
            $table->text('notes')->nullable();
            $table->json('raw_input');
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('source_country_code')->references('code')->on('countries')->restrictOnDelete();
            $table->foreign('target_country_code')->references('code')->on('countries')->restrictOnDelete();
            $table->unique(
                ['organization_id', 'marketplace_source_id', 'marketplace_key', 'external_id'],
                'listings_source_external_unique',
            );
            $table->index(['organization_id', 'created_at', 'id']);
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(
                ['organization_id', 'source_country_code', 'target_country_code'],
                'listings_market_route_index',
            );
        });

        Schema::create('listing_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->foreignId('captured_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('captured_at');
            $table->string('source_url', 2048)->nullable();
            $table->string('external_id', 128)->nullable();
            $table->string('marketplace_name', 160);
            $table->string('marketplace_key', 80);
            $table->string('title', 240);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('asking_price_minor')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->text('seller_information')->nullable();
            $table->string('location', 255)->nullable();
            $table->char('source_country_code', 2);
            $table->char('target_country_code', 2);
            $table->string('status', 32);
            $table->text('notes')->nullable();
            $table->json('raw_payload');
            $table->char('content_hash', 64);

            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('source_country_code')->references('code')->on('countries')->restrictOnDelete();
            $table->foreign('target_country_code')->references('code')->on('countries')->restrictOnDelete();
            $table->unique(['listing_id', 'sequence']);
            $table->index(['listing_id', 'captured_at']);
            $table->index('content_hash');
        });

        Schema::create('listing_images', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('kind', 24);
            $table->string('disk', 32);
            $table->string('path', 512);
            $table->string('client_filename', 180);
            $table->string('mime_type', 64);
            $table->string('extension', 8);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->char('checksum_sha256', 64);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['listing_id', 'kind', 'position']);
            $table->index(['listing_id', 'kind', 'checksum_sha256'], 'listing_images_checksum_index');
        });

        DB::table('marketplace_sources')->insert([
            'id' => (string) Str::ulid(),
            'key' => 'manual',
            'name' => 'Manual entry',
            'connector_type' => 'manual',
            'capabilities' => json_encode([], JSON_THROW_ON_ERROR),
            'supported_country_codes' => null,
            'supported_currency_codes' => null,
            'cross_border_supported' => true,
            'allowed_operations' => 'User-authorized manual data entry and private file upload.',
            'prohibited_operations' => 'Automated fetching, crawling, or seller messaging.',
            'data_retention_rules' => 'Tenant-controlled source data subject to the platform retention policy.',
            'asking_price_only' => true,
            'transaction_price_supported' => false,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_images');
        Schema::dropIfExists('listing_snapshots');
        Schema::dropIfExists('listings');
        Schema::dropIfExists('marketplace_sources');
    }
};
