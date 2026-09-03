<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_features', function (Blueprint $table) {
            $table->ulid('id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('plan_features', function (Blueprint $table) {
            $table->ulid('id')->nullable()->change();
        });
    }
};
