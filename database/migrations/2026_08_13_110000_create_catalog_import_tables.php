<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('source_name', 160);
            $table->string('source_key', 180);
            $table->string('source_url', 500)->nullable();
            $table->string('license_name', 160);
            $table->string('dataset_version', 100);
            $table->text('notes')->nullable();
            $table->timestamp('rights_confirmed_at');
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('delimiter', 8)->default('comma');
            $table->string('disk', 32);
            $table->string('path', 512);
            $table->string('original_file_name', 180);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('content_hash', 64);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('unchanged_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_key', 'dataset_version'],
                'catalog_imports_source_version_unique',
            );
            $table->index(['status', 'created_at']);
            $table->index('content_hash');
        });

        Schema::create('catalog_import_rows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('catalog_import_id')
                ->constrained('catalog_imports')
                ->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('status', 24);
            $table->foreignUlid('product_category_id')
                ->nullable()
                ->constrained('product_categories')
                ->nullOnDelete();
            $table->foreignUlid('brand_id')
                ->nullable()
                ->constrained('brands')
                ->nullOnDelete();
            $table->foreignUlid('product_model_id')
                ->nullable()
                ->constrained('product_models')
                ->nullOnDelete();
            $table->foreignUlid('product_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->nullOnDelete();
            $table->json('raw_payload');
            $table->json('normalized_payload')->nullable();
            $table->json('validation_errors')->nullable();
            $table->char('row_hash', 64);
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->unique(
                ['catalog_import_id', 'row_number'],
                'catalog_import_rows_import_row_unique',
            );
            $table->index(
                ['catalog_import_id', 'status', 'row_number'],
                'catalog_import_rows_import_status_index',
            );
            $table->index('row_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_rows');
        Schema::dropIfExists('catalog_imports');
    }
};
