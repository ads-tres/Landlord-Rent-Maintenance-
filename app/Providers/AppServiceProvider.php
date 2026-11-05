<?php

namespace App\Providers;

use App\Services\Sms\AfroMessageSmsSender;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsSender::class, function () {
            return match (config('sms.driver')) {
                'afromessage' => new AfroMessageSmsSender(
                    token: (string) config('sms.afromessage.token'),
                    baseUrl: (string) config('sms.afromessage.base_url'),
                    identifierId: config('sms.afromessage.identifier_id'),
                    sender: config('sms.afromessage.sender'),
                ),
                default => new LogSmsSender,
            };
        });
    }

    public function boot(): void
    {
        // SMS costs money: limit login codes per phone and per IP.
        RateLimiter::for('otp', function (Request $request) {
            return [
                Limit::perMinute(1)->by('otp-phone:'.$request->input('phone')),
                Limit::perHour(5)->by('otp-phone-hour:'.$request->input('phone')),
                Limit::perHour(20)->by('otp-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('waitlist', fn (Request $request) => Limit::perHour(10)->by($request->ip()));
    }
}
