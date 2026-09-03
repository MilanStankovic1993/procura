<?php

namespace App\Http\Middleware;

use App\Monitoring\Telegram\TelegramConfiguration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyTelegramWebhookSecret
{
    public function __construct(
        private readonly TelegramConfiguration $configuration,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $configured = $this->configuration->webhookSecret();
        $provided = (string) $request->header(
            'X-Telegram-Bot-Api-Secret-Token',
            '',
        );

        abort_unless(
            $configured !== ''
                && $provided !== ''
                && hash_equals($configured, $provided),
            403,
        );

        return $next($request);
    }
}
