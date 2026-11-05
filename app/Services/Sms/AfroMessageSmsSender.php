<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * AfroMessage gateway (integrated with Ethio telecom and Safaricom Ethiopia).
 *
 * @see https://www.afromessage.com/developers
 */
class AfroMessageSmsSender implements SmsSender
{
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl,
        private readonly ?string $identifierId = null,
        private readonly ?string $sender = null,
    ) {}

    public function send(string $phone, string $message): void
    {
        $response = Http::withToken($this->token)
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 500)
            ->post(rtrim($this->baseUrl, '/').'/send', array_filter([
                'from' => $this->identifierId,
                'sender' => $this->sender,
                'to' => $phone,
                'message' => $message,
            ]));

        if ($response->failed() || $response->json('acknowledge') !== 'success') {
            throw new RuntimeException('AfroMessage send failed: '.$response->body());
        }
    }
}
