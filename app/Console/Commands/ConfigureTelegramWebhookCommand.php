<?php

namespace App\Console\Commands;

use App\Monitoring\Telegram\TelegramWebhookRegistrar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

final class ConfigureTelegramWebhookCommand extends Command
{
    protected $signature = 'notifications:configure-telegram-webhook
        {url? : Public HTTPS webhook URL}';

    protected $description = 'Register the configured Procura Telegram webhook.';

    public function handle(TelegramWebhookRegistrar $registrar): int
    {
        $url = trim((string) (
            $this->argument('url')
            ?: config('monitoring.telegram.webhook_url')
        ));
        $validated = Validator::make(
            ['url' => $url],
            ['url' => ['required', 'url:https', 'max:2048']],
        )->validate();
        $registrar->register($validated['url']);
        $this->info('Telegram webhook registered successfully.');

        return self::SUCCESS;
    }
}
