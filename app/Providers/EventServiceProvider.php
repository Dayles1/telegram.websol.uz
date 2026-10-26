<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Events\TelegramMessageCreated;
use App\Listeners\SendTelegramMessageListener;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        TelegramMessageCreated::class => [
            SendTelegramMessageListener::class,
        ],
    ];

    public function boot()
    {
        parent::boot();
    }
}
