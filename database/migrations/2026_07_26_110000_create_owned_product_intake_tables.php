<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owned_products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignUlid('product_category_id')
                ->nullable()
                ->constrained('product_categories')
                ->restrictOnDelete();
            $table->string('brand_name', 160)->nullable();
            $table->string('model_name', 200)->nullable();
            $table->string('condition', 32)->default('unknown');
            $table->unsignedSmallInteger('age_months')->nullable();
            $table->json('accessories')->nullable();
            $table->json('defects')->nullable();
            $table->boolean('purchase_history_known')->default(false);
            $table->text('purchase_history')->nullable();
            $table->char('target_continent_code', 2);
            $table->string('cross_border_preference', 32)->default('unknown');
            $table->string('desired_sale_speed', 32)->default('unknown');
            $table->string('status', 24)->default('draft');
            $table->text('notes')->nullable();
            $table->json('raw_input');
            $table->timestamps();

            $table->foreign('target_continent_code')
                ->references('code')
                ->on('continents')
                ->restrictOnDelete();
            $table->index(['organization_id', 'created_at', 'id']);
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(
                ['organization_id', 'target_continent_code', 'desired_sale_speed'],
                'owned_products_target_speed_index',
            );
        });

        Schema::create('owned_product_target_countries', function (Blueprint $table): void {
            $table->foreignUlid('owned_product_id')
                ->constrained('owned_products')
                ->cascadeOnDelete();
            $table->char('country_code', 2);
            $table->unsignedSmallInteger('sort_order');

            $table->foreign('country_code')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->primary(['owned_product_id', 'country_code']);
            $table->unique(
                ['owned_product_id', 'sort_order'],
                'owned_product_targets_sort_order_unique',
            );
            $table->index(
                ['country_code', 'owned_product_id'],
                'owned_product_targets_country_product_index',
            );
        });

        Schema::create('owned_product_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('owned_product_id')
                ->constrained('owned_products')
                ->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->foreignId('captured_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('captured_at');
            $table->foreignUlid('product_category_id')
                ->nullable()
                ->constrained('product_categories')
                ->restrictOnDelete();
            $table->string('brand_name', 160)->nullable();
            $table->string('model_name', 200)->nullable();
            $table->string('condition', 32);
            $table->unsignedSmallInteger('age_months')->nullable();
            $table->json('accessories')->nullable();
            $table->json('defects')->nullable();
            $table->boolean('purchase_history_known');
            $table->text('purchase_history')->nullable();
            $table->char('target_continent_code', 2);
            $table->json('target_country_codes');
            $table->string('cross_border_preference', 32);
            $table->string('desired_sale_speed', 32);
            $table->string('status', 24);
            $table->text('notes')->nullable();
            $table->json('raw_payload');
            $table->char('content_hash', 64);

            $table->foreign('target_continent_code')
                ->references('code')
                ->on('continents')
                ->restrictOnDelete();
            $table->unique(['owned_product_id', 'sequence']);
            $table->index(['owned_product_id', 'captured_at']);
            $table->index('content_hash');
        });

        Schema::create('owned_product_images', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('owned_product_id')
                ->constrained('owned_products')
                ->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('kind', 32);
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

            $table->unique(
                ['owned_product_id', 'kind', 'position'],
                'owned_product_images_kind_position_unique',
            );
            $table->index(
                ['owned_product_id', 'kind', 'checksum_sha256'],
                'owned_product_images_checksum_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owned_product_images');
        Schema::dropIfExists('owned_product_snapshots');
        Schema::dropIfExists('owned_product_target_countries');
        Schema::dropIfExists('owned_products');
    }
};
