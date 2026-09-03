<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('privacy_erased_at')
                ->nullable()
                ->after('preferred_locale')
                ->index();
            $table->foreignUlid('privacy_erasure_request_id')
                ->nullable()
                ->after('privacy_erased_at')
                ->constrained('privacy_requests')
                ->restrictOnDelete();
        });

        Schema::table(
            'privacy_request_fulfillments',
            function (Blueprint $table): void {
                $table->string('erasure_evidence_reference', 255)
                    ->nullable()
                    ->after('delivery_evidence_reference');
                $table->string('storage_evidence_reference', 255)
                    ->nullable()
                    ->after('erasure_evidence_reference');
                $table->string('processor_evidence_reference', 255)
                    ->nullable()
                    ->after('storage_evidence_reference');
                $table->timestamp('backup_purge_due_at')
                    ->nullable()
                    ->after('processor_evidence_reference');
            },
        );
    }

    public function down(): void
    {
        Schema::table(
            'privacy_request_fulfillments',
            function (Blueprint $table): void {
                $table->dropColumn([
                    'erasure_evidence_reference',
                    'storage_evidence_reference',
                    'processor_evidence_reference',
                    'backup_purge_due_at',
                ]);
            },
        );

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId(
                'privacy_erasure_request_id',
            );
            $table->dropColumn('privacy_erased_at');
        });
    }
};
