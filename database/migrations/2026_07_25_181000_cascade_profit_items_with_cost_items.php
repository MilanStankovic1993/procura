<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profit_estimate_items', function (Blueprint $table): void {
            $table->dropForeign(['cost_input_item_id']);
            $table->foreign('cost_input_item_id')
                ->references('id')
                ->on('cost_input_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('profit_estimate_items', function (Blueprint $table): void {
            $table->dropForeign(['cost_input_item_id']);
            $table->foreign('cost_input_item_id')
                ->references('id')
                ->on('cost_input_items')
                ->restrictOnDelete();
        });
    }
};
