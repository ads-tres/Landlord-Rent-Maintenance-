<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBot;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the bot by long polling instead of a webhook: for local testing before the
 * server has HTTPS. Don't run it while a webhook is set (Telegram allows one or the other).
 */
#[Signature('telegram:poll {--once : Handle one batch of updates and exit}')]
#[Description('Run the Telegram bot by long polling (local testing)')]
class TelegramPoll extends Command
{
    public function handle(TelegramClient $telegram, TelegramBot $bot): int
    {
        if (! $telegram->enabled()) {
            $this->error('Set TELEGRAM_BOT_TOKEN first.');

            return self::FAILURE;
        }

        $telegram->deleteWebhook();
        $this->info('Polling Telegram. Press Ctrl+C to stop.');

        do {
            $offset = (int) Cache::get('telegram:offset', 0);
            foreach ($telegram->getUpdates($offset, $this->option('once') ? 0 : 25) as $update) {
                $bot->handle($update);
                Cache::forever('telegram:offset', $update['update_id'] + 1);
                $this->line('Handled update '.$update['update_id']);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
