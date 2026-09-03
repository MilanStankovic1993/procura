<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table): void {
            $table->index(
                ['status', 'processing_started_at'],
                'analyses_processing_recovery_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table): void {
            $table->dropIndex('analyses_processing_recovery_index');
        });
    }
};
