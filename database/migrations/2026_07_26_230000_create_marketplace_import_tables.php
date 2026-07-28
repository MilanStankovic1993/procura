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
        Schema::table('marketplace_sources', function (Blueprint $table): void {
            $table->json('supported_language_tags')
                ->nullable()
                ->after('supported_currency_codes');
            $table->string('geographic_coverage', 255)
                ->nullable()
                ->after('supported_language_tags');
            $table->string('compliance_status', 32)
                ->default('pending_review')
                ->after('cross_border_supported');
            $table->index(
                ['compliance_status', 'active'],
                'marketplace_sources_compliance_active_index',
            );
        });

        DB::table('marketplace_sources')
            ->where('key', 'manual')
            ->update([
                'compliance_status' => 'approved',
                'geographic_coverage' => 'User-supplied evidence; no automated source coverage.',
                'updated_at' => now(),
            ]);

        DB::table('marketplace_sources')->updateOrInsert(
            ['key' => 'authorized_csv'],
            [
                'id' => (string) Str::ulid(),
                'name' => 'Authorized CSV import',
                'connector_type' => 'csv',
                'capabilities' => json_encode(['import'], JSON_THROW_ON_ERROR),
                'supported_country_codes' => null,
                'supported_currency_codes' => null,
                'supported_language_tags' => null,
                'geographic_coverage' => 'Organization-authorized structured source evidence.',
                'cross_border_supported' => true,
                'compliance_status' => 'approved',
                'terms_reviewed_at' => null,
                'legal_basis' => 'Organization authorization and source-rights attestation at upload.',
                'allowed_operations' => 'Private CSV upload, validation, normalization, and tenant listing creation.',
                'prohibited_operations' => 'Remote fetching, crawling, seller messaging, and marketplace mutation.',
                'rate_limits' => 'Upload and row limits are enforced by the application connector policy.',
                'data_retention_rules' => 'Private source files and row evidence follow the configured connector retention policy.',
                'attribution_rules' => 'Every row must preserve marketplace name and external source identity.',
                'contact_person' => null,
                'review_notes' => 'Generic controlled import boundary; each external data source still requires production-register approval.',
                'reliability_score' => null,
                'freshness_score' => null,
                'completeness_score' => null,
                'asking_price_only' => true,
                'transaction_price_supported' => false,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        Schema::create('marketplace_imports', function (Blueprint $table): void {
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
            $table->uuid('idempotency_key');
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('delimiter', 8)->default('comma');
            $table->char('default_target_country_code', 2)->nullable();
            $table->string('disk', 32);
            $table->string('path', 512);
            $table->string('original_file_name', 180);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('content_hash', 64);
            $table->timestamp('authorization_confirmed_at');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->foreign('default_target_country_code')
                ->references('code')
                ->on('countries')
                ->restrictOnDelete();
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'marketplace_imports_tenant_idempotency_unique',
            );
            $table->unique(
                ['organization_id', 'marketplace_source_id', 'content_hash'],
                'marketplace_imports_tenant_source_content_unique',
            );
            $table->index(
                ['organization_id', 'created_at', 'id'],
                'marketplace_imports_tenant_created_index',
            );
            $table->index(
                ['status', 'created_at'],
                'marketplace_imports_status_created_index',
            );
        });

        Schema::create('marketplace_import_rows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('marketplace_import_id')
                ->constrained('marketplace_imports')
                ->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('status', 24);
            $table->foreignUlid('listing_id')
                ->nullable()
                ->constrained('listings')
                ->nullOnDelete();
            $table->json('raw_payload');
            $table->json('normalized_payload')->nullable();
            $table->json('validation_errors')->nullable();
            $table->char('row_hash', 64);
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->unique(
                ['marketplace_import_id', 'row_number'],
                'marketplace_import_rows_import_row_unique',
            );
            $table->index(
                ['marketplace_import_id', 'status', 'row_number'],
                'marketplace_import_rows_import_status_index',
            );
            $table->index('row_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_import_rows');
        Schema::dropIfExists('marketplace_imports');

        DB::table('marketplace_sources')->where('key', 'authorized_csv')->delete();

        Schema::table('marketplace_sources', function (Blueprint $table): void {
            $table->dropIndex('marketplace_sources_compliance_active_index');
            $table->dropColumn([
                'supported_language_tags',
                'geographic_coverage',
                'compliance_status',
            ]);
        });
    }
};
