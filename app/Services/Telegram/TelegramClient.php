<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Minimal Telegram Bot API client (https://core.telegram.org/bots/api). */
class TelegramClient
{
    public function enabled(): bool
    {
        return filled(config('telegram.token'));
    }

    /** @param  array<string, mixed>  $extra  e.g. reply_markup */
    public function sendMessage(int $chatId, string $text, array $extra = []): array
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true] + $extra);
    }

    public function answerCallbackQuery(string $id, ?string $text = null): void
    {
        $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $id, 'text' => $text]));
    }

    /** Downloads a file the user sent (photo, voice note). */
    public function downloadFile(string $fileId): string
    {
        $path = $this->call('getFile', ['file_id' => $fileId])['file_path'] ?? null;
        if (! $path) {
            throw new RuntimeException('Telegram getFile returned no path');
        }

        $response = Http::timeout(60)->get(rtrim(config('telegram.api_base'), '/').'/file/bot'.config('telegram.token').'/'.$path);
        if ($response->failed()) {
            throw new RuntimeException('Telegram file download failed');
        }

        return $response->body();
    }

    /** @return list<array<string, mixed>> */
    public function getUpdates(int $offset, int $timeout = 25): array
    {
        return $this->call('getUpdates', ['offset' => $offset, 'timeout' => $timeout, 'allowed_updates' => ['message', 'callback_query']], $timeout + 10);
    }

    public function setWebhook(string $url, string $secret): array
    {
        return $this->call('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query']]);
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook', []);
    }

    private function call(string $method, array $params, int $timeout = 15): mixed
    {
        $response = Http::timeout($timeout)
            ->asJson()
            ->post(rtrim(config('telegram.api_base'), '/').'/bot'.config('telegram.token').'/'.$method, $params);

        if ($response->failed() || ! $response->json('ok')) {
            throw new RuntimeException("Telegram {$method} failed: ".$response->body());
        }

        return $response->json('result');
    }
}
