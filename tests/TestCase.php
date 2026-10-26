<?php

namespace Tests;

use App\Jobs\SendAccountMessages;
use App\Jobs\StartLoopRound;
use App\Models\User;
use App\Models\UserPhone;
use App\Telegram\Contracts\TelegramGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Bus;
use Tests\Fakes\FakeTelegram;

abstract class TestCase extends BaseTestCase
{
    protected FakeTelegram $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        // Telegram is never called for real in tests.
        $this->telegram = new FakeTelegram;
        $this->app->instance(TelegramGateway::class, $this->telegram);
        $this->withoutVite();

        // Sending runs when a test says so (SendWorker::run), not on dispatch.
        Bus::fake([SendAccountMessages::class, StartLoopRound::class]);
    }

    protected function user(): User
    {
        return User::factory()->create();
    }

    protected function superadmin(): User
    {
        return User::factory()->superadmin()->create();
    }

    protected function phoneOf(User $user, array $attributes = []): UserPhone
    {
        return UserPhone::factory()->for($user)->create($attributes);
    }
}
