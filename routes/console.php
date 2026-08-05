<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('analyses:dispatch-pending --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('marketplace-imports:dispatch-pending --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('notifications:recover-email-deliveries --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('notifications:recover-telegram-deliveries --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('notifications:expire-telegram-connections --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('operations:dispatch-queue-heartbeats')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('broker-reports:purge-expired')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();
