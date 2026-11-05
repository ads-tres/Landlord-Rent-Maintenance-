<?php

namespace App\Services\Auth;

use App\Models\OtpCode;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public function __construct(private readonly SmsSender $sms) {}

    public function send(string $phone, string $locale = 'am'): void
    {
        $length = (int) config('sms.otp.length');
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

        // Only the latest code is valid.
        OtpCode::query()->where('phone', $phone)->whereNull('consumed_at')->delete();

        OtpCode::query()->create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('sms.otp.ttl_minutes')),
        ]);

        $this->sms->send($phone, __('sms.otp', ['code' => $code], $locale));
    }

    public function verify(string $phone, string $code): bool
    {
        $otp = OtpCode::query()
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp || $otp->attempts >= (int) config('sms.otp.max_attempts')) {
            return false;
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return false;
        }

        $otp->update(['consumed_at' => now()]);

        return true;
    }
}
