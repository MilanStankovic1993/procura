<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('status', 24);
            $table->text('challenge_token')->nullable();
            $table->char('challenge_token_hash', 64)->unique();
            $table->timestamp('challenge_expires_at');
            $table->text('telegram_user_id')->nullable();
            $table->char('telegram_user_id_hash', 64)->nullable();
            $table->text('chat_id')->nullable();
            $table->char('chat_id_hash', 64)->nullable();
            $table->text('username')->nullable();
            $table->string('bot_username', 64);
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(
                ['user_id', 'status', 'created_at'],
                'telegram_connections_user_status_idx',
            );
            $table->index(
                ['status', 'challenge_expires_at'],
                'telegram_connections_pending_idx',
            );
            $table->index(
                ['telegram_user_id_hash', 'status'],
                'telegram_connections_user_hash_idx',
            );
            $table->index(
                ['chat_id_hash', 'status'],
                'telegram_connections_chat_hash_idx',
            );
        });

        Schema::create(
            'telegram_connection_events',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('telegram_connection_id')
                    ->constrained('telegram_connections')
                    ->cascadeOnDelete();
                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();
                $table->foreignUlid('previous_event_id')
                    ->nullable()
                    ->constrained('telegram_connection_events')
                    ->cascadeOnDelete();
                $table->string('event_type', 24);
                $table->unsignedInteger('sequence');
                $table->json('payload');
                $table->timestamp('occurred_at');
                $table->timestamp('created_at');

                $table->unique(
                    ['telegram_connection_id', 'sequence'],
                    'telegram_connection_events_sequence_uq',
                );
                $table->index(
                    ['user_id', 'occurred_at'],
                    'telegram_connection_events_user_idx',
                );
                $table->index(
                    ['event_type', 'occurred_at'],
                    'telegram_connection_events_type_idx',
                );
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_connection_events');
        Schema::dropIfExists('telegram_connections');
    }
};
