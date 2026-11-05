<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:set-webhook {--delete : Remove the webhook instead}')]
#[Description('Point the Telegram bot at this server (needs HTTPS and TELEGRAM_WEBHOOK_SECRET)')]
class TelegramSetWebhook extends Command
{
    public function handle(TelegramClient $telegram): int
    {
        if (! $telegram->enabled()) {
            $this->error('Set TELEGRAM_BOT_TOKEN first.');

            return self::FAILURE;
        }

        if ($this->option('delete')) {
            $telegram->deleteWebhook();
            $this->info('Webhook removed.');

            return self::SUCCESS;
        }

        $secret = (string) config('telegram.webhook_secret');
        $url = route('telegram.webhook');
        if ($secret === '' || ! str_starts_with($url, 'https://')) {
            $this->error('Needs TELEGRAM_WEBHOOK_SECRET and an https APP_URL.');

            return self::FAILURE;
        }

        $telegram->setWebhook($url, $secret);
        $this->info("Webhook set to {$url}");

        return self::SUCCESS;
    }
}
