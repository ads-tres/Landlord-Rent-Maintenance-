<?php

namespace App\Services\Sms;

use App\Jobs\SendSms;
use App\Jobs\SendTelegram;
use App\Models\SmsMessage;
use App\Models\TelegramLink;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Push\PushNotifier;
use App\Services\Telegram\TelegramClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Sends messages to tenants and landlords: on Telegram when they have linked the bot
 * (free), otherwise by SMS. Every message is logged in sms_messages with its channel.
 * Login codes skip this and go straight to the SmsSender, so they are never delayed.
 */
class SmsNotifier
{
    /** No messages to tenants from 21:00 to 07:00 Addis Ababa time; they go out at 07:00. */
    public const QUIET_FROM = 21;

    public const QUIET_UNTIL = 7;

    public const TIMEZONE = 'Africa/Addis_Ababa';

    public function __construct(private readonly TelegramClient $telegram) {}

    /** Telegram if the tenant linked and agreed, SMS otherwise; held until morning at night. */
    public function toTenant(Tenant $tenant, string $kind, string $body, ?Model $related = null): SmsMessage
    {
        $link = $this->telegram->enabled() ? TelegramLink::activeFor($tenant) : null;
        $after = self::quietUntil();

        return $link
            ? $this->queueTelegram($tenant->landlord_id, $link->chat_id, $tenant->phone, $kind, $body, $related, $after)
            : $this->queue($tenant->landlord_id, $tenant->phone, $kind, $body, $related, $after);
    }

    /** When quiet hours end, if it is quiet now; otherwise null. */
    public static function quietUntil(): ?CarbonImmutable
    {
        $now = CarbonImmutable::now(self::TIMEZONE);
        if ($now->hour >= self::QUIET_FROM) {
            return $now->addDay()->setTime(self::QUIET_UNTIL, 0);
        }
        if ($now->hour < self::QUIET_UNTIL) {
            return $now->setTime(self::QUIET_UNTIL, 0);
        }

        return null;
    }

    /**
     * Landlord alerts (e.g. a new repair from a tenant) go to the landlord's phone as a push
     * notification and to Telegram: they are extras, not worth paying for by SMS.
     * Returns the Telegram message, or null when the landlord hasn't linked Telegram.
     */
    public function toLandlord(User $landlord, string $kind, string $body, ?Model $related = null): ?SmsMessage
    {
        app(PushNotifier::class)->toUser($landlord, $kind, $body, $related);

        $link = $this->telegram->enabled() ? TelegramLink::activeFor($landlord) : null;

        return $link ? $this->queueTelegram($landlord->id, $link->chat_id, null, $kind, $body, $related) : null;
    }

    private function queueTelegram(int $landlordId, int $chatId, ?string $fallbackPhone, string $kind, string $body, ?Model $related, ?CarbonImmutable $after = null): SmsMessage
    {
        $message = SmsMessage::query()->create([
            'landlord_id' => $landlordId,
            'to' => (string) $chatId,
            'channel' => SmsMessage::CHANNEL_TELEGRAM,
            'kind' => $kind,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'body' => $body,
            'segments' => 0,
            'send_after' => $after,
        ]);

        $job = SendTelegram::dispatch($message->id, $fallbackPhone)->afterCommit();
        if ($after) {
            $job->delay($after);
        }

        return $message;
    }

    public function queue(?int $landlordId, string $to, string $kind, string $body, ?Model $related = null, ?CarbonImmutable $after = null): SmsMessage
    {
        $message = SmsMessage::query()->create([
            'landlord_id' => $landlordId,
            'to' => $to,
            'kind' => $kind,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'body' => $body,
            'segments' => SmsMessage::segmentsFor($body),
            'send_after' => $after,
        ]);

        $job = SendSms::dispatch($message->id)->afterCommit();
        if ($after) {
            $job->delay($after);
        }

        return $message;
    }

    public function alreadySent(string $kind, Model $related): bool
    {
        return SmsMessage::query()
            ->where('kind', $kind)
            ->where('related_type', $related->getMorphClass())
            ->where('related_id', $related->getKey())
            ->where('status', '!=', 'failed')
            ->exists();
    }
}
