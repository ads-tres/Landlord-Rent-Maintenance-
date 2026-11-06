<?php

namespace App\Services\Portal;

use App\Models\Lease;
use App\Models\SmsMessage;
use App\Models\TelegramLink;
use App\Services\Telegram\TelegramClient;

/**
 * Adds "Your account: {link}" to a tenant message. Always on Telegram (free); on SMS only
 * when it doesn't make the message cost an extra segment.
 */
class PortalLink
{
    public function __construct(private readonly TelegramClient $telegram) {}

    public function append(Lease $lease, string $body, string $locale): string
    {
        $url = $lease->portalUrl();
        if (! $url) {
            return $body;
        }
        $with = $body.__('portal.sms_suffix', ['url' => $url], $locale);

        $onTelegram = $this->telegram->enabled() && $lease->tenant && TelegramLink::activeFor($lease->tenant);

        return $onTelegram || SmsMessage::segmentsFor($with) === SmsMessage::segmentsFor($body) ? $with : $body;
    }
}
