<?php

namespace App\Monitoring\Telegram;

use LogicException;

final class TelegramIdentityHasher
{
    public function __construct(
        private readonly TelegramConfiguration $configuration,
    ) {}

    public function hash(string $identifier): string
    {
        $key = $this->configuration->identityHashKey();

        if ($key === '') {
            throw new LogicException(
                'Telegram identity hashing is not configured.',
            );
        }

        return hash_hmac('sha256', $identifier, $key);
    }
}
