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
        Schema::table('plan_features', function (Blueprint $table) {
            $table->ulid('id')->nullable()->unique()->after('plan_id');
        });

        DB::table('plan_features')
            ->whereNull('id')
            ->orderBy('plan_id')
            ->orderBy('feature_code')
            ->get()
            ->each(function (object $feature): void {
                DB::table('plan_features')
                    ->where('plan_id', $feature->plan_id)
                    ->where('feature_code', $feature->feature_code)
                    ->update(['id' => (string) Str::ulid()]);
            });
    }

    public function down(): void
    {
        Schema::table('plan_features', function (Blueprint $table) {
            $table->dropColumn('id');
        });
    }
};
