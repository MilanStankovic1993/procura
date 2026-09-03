<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('telegram_connections')
            ->where('status', '!=', 'connected')
            ->update([
                'telegram_user_id' => null,
                'telegram_user_id_hash' => null,
                'chat_id' => null,
                'chat_id_hash' => null,
                'username' => null,
            ]);

        foreach (['telegram_user_id_hash', 'chat_id_hash'] as $column) {
            $hasDuplicate = DB::table('telegram_connections')
                ->select($column)
                ->where('status', 'connected')
                ->whereNotNull($column)
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1')
                ->limit(1)
                ->first() !== null;

            if ($hasDuplicate) {
                throw new RuntimeException(
                    "Duplicate connected Telegram identity in {$column}.",
                );
            }
        }

        Schema::table(
            'telegram_connections',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'telegram_connections_user_hash_idx',
                );
                $table->dropIndex(
                    'telegram_connections_chat_hash_idx',
                );
                $table->unique(
                    'telegram_user_id_hash',
                    'telegram_connections_user_hash_uq',
                );
                $table->unique(
                    'chat_id_hash',
                    'telegram_connections_chat_hash_uq',
                );
            },
        );
    }

    public function down(): void
    {
        Schema::table(
            'telegram_connections',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'telegram_connections_user_hash_uq',
                );
                $table->dropUnique(
                    'telegram_connections_chat_hash_uq',
                );
                $table->index(
                    ['telegram_user_id_hash', 'status'],
                    'telegram_connections_user_hash_idx',
                );
                $table->index(
                    ['chat_id_hash', 'status'],
                    'telegram_connections_chat_hash_idx',
                );
            },
        );
    }
};
