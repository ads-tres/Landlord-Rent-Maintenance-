<?php

namespace App\Services\Sms;

use App\Jobs\SendSms;
use App\Models\SmsMessage;
use Illuminate\Database\Eloquent\Model;

/**
 * Logs an SMS for a landlord and queues it. Login codes skip this and go straight
 * to the SmsSender, so they are never delayed by the queue.
 */
class SmsNotifier
{
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
