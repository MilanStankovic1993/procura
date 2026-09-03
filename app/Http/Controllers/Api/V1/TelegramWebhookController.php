<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Monitoring\ClaimTelegramConnection;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TelegramWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        ClaimTelegramConnection $claim,
    ): Response {
        $updateId = $request->input('update_id');
        $text = $request->input('message.text');
        $chatId = $request->input('message.chat.id');
        $chatType = $request->input('message.chat.type');
        $telegramUserId = $request->input('message.from.id');
        $username = $request->input('message.from.username');

        if (
            ! is_int($updateId)
            || ! is_string($text)
            || strlen($text) > 128
            || ! in_array($chatType, ['private'], true)
            || (! is_int($chatId) && ! is_string($chatId))
            || (! is_int($telegramUserId) && ! is_string($telegramUserId))
            || (string) $chatId !== (string) $telegramUserId
            || (
                $username !== null
                && (! is_string($username) || strlen($username) > 64)
            )
            || preg_match(
                '/^\/start(?:@[A-Za-z0-9_]{5,32})?\s+([A-Za-z0-9_-]{43})$/',
                $text,
                $matches,
            ) !== 1
        ) {
            return response()->noContent();
        }

        $claim->claim(
            $matches[1],
            (string) $telegramUserId,
            (string) $chatId,
            $username,
            $updateId,
        );

        return response()->noContent();
    }
}
