<?php

namespace Tests;

use App\Models\User;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeSmsSender;

abstract class TestCase extends BaseTestCase
{
    protected FakeSmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
    }

    protected function actingAsLandlord(?User $user = null): User
    {
        $user ??= User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }
}
