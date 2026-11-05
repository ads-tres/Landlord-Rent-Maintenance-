<?php

namespace App\Services\Sms;

use App\Jobs\SendSms;
use App\Jobs\SendTelegram;
use App\Models\SmsMessage;
use App\Models\TelegramLink;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Database\Eloquent\Model;

/**
 * Sends messages to tenants and landlords: on Telegram when they have linked the bot
 * (free), otherwise by SMS. Every message is logged in sms_messages with its channel.
 * Login codes skip this and go straight to the SmsSender, so they are never delayed.
 */
class SmsNotifier
{
    public function __construct(private readonly TelegramClient $telegram) {}

    /** Telegram if the tenant linked and agreed, SMS otherwise. */
    public function toTenant(Tenant $tenant, string $kind, string $body, ?Model $related = null): SmsMessage
    {
        $link = $this->telegram->enabled() ? TelegramLink::activeFor($tenant) : null;

        return $link
            ? $this->queueTelegram($tenant->landlord_id, $link->chat_id, $tenant->phone, $kind, $body, $related)
            : $this->queue($tenant->landlord_id, $tenant->phone, $kind, $body, $related);
    }

    /**
     * Landlord alerts (e.g. a new repair from a tenant) go only to Telegram: they are
     * extras, not worth paying for by SMS. Returns null when the landlord hasn't linked.
     */
    public function toLandlord(User $landlord, string $kind, string $body, ?Model $related = null): ?SmsMessage
    {
        $link = $this->telegram->enabled() ? TelegramLink::activeFor($landlord) : null;

        return $link ? $this->queueTelegram($landlord->id, $link->chat_id, null, $kind, $body, $related) : null;
    }

    private function queueTelegram(int $landlordId, int $chatId, ?string $fallbackPhone, string $kind, string $body, ?Model $related): SmsMessage
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
        ]);

        SendTelegram::dispatch($message->id, $fallbackPhone)->afterCommit();

        return $message;
    }

    public function queue(?int $landlordId, string $to, string $kind, string $body, ?Model $related = null): SmsMessage
    {
        $message = SmsMessage::query()->create([
            'landlord_id' => $landlordId,
            'to' => $to,
            'kind' => $kind,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'body' => $body,
            'segments' => SmsMessage::segmentsFor($body),
        ]);

        SendSms::dispatch($message->id)->afterCommit();

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
