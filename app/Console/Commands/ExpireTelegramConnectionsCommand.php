<?php

namespace App\Console\Commands;

use App\Enums\Monitoring\TelegramConnectionEventType;
use App\Enums\Monitoring\TelegramConnectionStatus;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Monitoring\Telegram\TelegramConnectionEvents;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExpireTelegramConnectionsCommand extends Command
{
    protected $signature = 'notifications:expire-telegram-connections {--limit=100}';

    protected $description = 'Expire bounded stale Telegram connection challenges.';

    public function handle(TelegramConnectionEvents $events): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $candidateIds = TelegramConnection::query()
            ->where('status', TelegramConnectionStatus::Pending)
            ->where('challenge_expires_at', '<=', now())
            ->orderBy('challenge_expires_at')
            ->limit($limit)
            ->pluck('id');
        $expired = 0;

        foreach ($candidateIds as $candidateId) {
            $didExpire = DB::transaction(
                function () use ($candidateId, $events): bool {
                    $candidate = TelegramConnection::query()
                        ->find($candidateId);

                    if ($candidate === null) {
                        return false;
                    }

                    User::query()
                        ->lockForUpdate()
                        ->findOrFail($candidate->user_id);
                    $connection = TelegramConnection::query()
                        ->whereKey($candidateId)
                        ->where(
                            'status',
                            TelegramConnectionStatus::Pending,
                        )
                        ->where('challenge_expires_at', '<=', now())
                        ->lockForUpdate()
                        ->first();

                    if ($connection === null) {
                        return false;
                    }

                    $connection->forceFill([
                        'status' => TelegramConnectionStatus::Expired,
                        'challenge_token' => null,
                    ])->save();
                    $events->append(
                        $connection,
                        TelegramConnectionEventType::Expired,
                        [
                            'provider' => $connection->provider,
                            'reason_code' => (
                                'connection_challenge_expired'
                            ),
                        ],
                    );

                    return true;
                },
                attempts: 3,
            );

            if ($didExpire) {
                $expired++;
            }
        }

        $this->info("Expired {$expired} Telegram connection challenges.");

        return self::SUCCESS;
    }
}
