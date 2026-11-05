<?php

namespace App\Jobs;

use App\Models\SmsMessage;
use App\Services\Sms\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public int $smsMessageId) {}

    public function handle(SmsSender $sender): void
    {
        $message = SmsMessage::query()->find($this->smsMessageId);

        if (! $message || $message->status === 'sent') {
            return;
        }

        $sender->send($message->to, $message->body);
        $message->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
    }

    public function failed(Throwable $e): void
    {
        SmsMessage::query()->whereKey($this->smsMessageId)->update([
            'status' => 'failed',
            'error' => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }
}
