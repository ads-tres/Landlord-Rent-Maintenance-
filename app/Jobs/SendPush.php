<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Services\Push\FcmClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends one push to every phone of a user; forgets phones that uninstalled the app. */
class SendPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /** @param  array<string, string>  $data */
    public function __construct(public int $userId, public string $title, public string $body, public array $data = []) {}

    public function handle(FcmClient $fcm): void
    {
        if (! $fcm->enabled()) {
            return;
        }

        foreach (DeviceToken::query()->where('user_id', $this->userId)->get() as $device) {
            if ($fcm->send($device->token, $this->title, $this->body, $this->data) === 'unregistered') {
                $device->delete();
            }
        }
    }
}
