<?php

namespace App\Jobs;

use App\Models\SmsMessage;
use App\Models\TelegramLink;
use App\Services\Sms\SmsNotifier;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Sends one Telegram message. If the person blocked the bot (403), Telegram is switched
 * off for that chat and the message goes by SMS right away. Other errors are retried,
 * then fall back to SMS too.
 */
class SendTelegram implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [30];

    public function __construct(public int $messageId, public ?string $fallbackPhone = null) {}

    public function handle(TelegramClient $telegram): void
    {
        $message = SmsMessage::query()->find($this->messageId);
        if (! $message || $message->status === 'sent') {
            return;
        }

        try {
            $telegram->sendMessage((int) $message->to, $message->body);
        } catch (RuntimeException $e) {
            if (! self::isPermanent($e)) {
                throw $e; // retry
            }
            TelegramLink::query()->where('chat_id', (int) $message->to)->update(['consented_at' => null]);
            $this->fallBack($message, $e);

            return;
        }

        $message->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
    }

    public function failed(Throwable $e): void
    {
        $message = SmsMessage::query()->find($this->messageId);
        if ($message && $message->status !== 'failed') {
            $this->fallBack($message, $e);
        }
    }

    /** "Forbidden: bot was blocked by the user", "chat not found" and similar. */
    private static function isPermanent(Throwable $e): bool
    {
        return (bool) preg_match('/"error_code":\s*(400|403)|Forbidden|chat not found/i', $e->getMessage());
    }

    private function fallBack(SmsMessage $message, Throwable $e): void
    {
        $message->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);

        if ($this->fallbackPhone) {
            app(SmsNotifier::class)->queue(
                $message->landlord_id,
                $this->fallbackPhone,
                $message->kind,
                $message->body,
                $message->related_type ? $message->related : null,
            );
        }
    }
}
