<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\EventKind;
use App\Http\Middleware\SetLocale;
use App\Jobs\MinuteTick;
use App\Models\User;
use App\Monitoring\EventRecorder;
use App\Monitoring\QueueHealth;
use App\Telegram\Contracts\TelegramGateway;
use App\Telegram\Madeline\MadelineGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TelegramGateway::class, MadelineGateway::class);
    }

    public function boot(): void
    {
        SetLocale::enforceSupported();
        Model::preventLazyLoading(! $this->app->isProduction());
        Paginator::defaultView('components.ui.pagination');

        // admins and the superadmin: users, monitoring, settings, global catalogs (accounts: User::oversees)
        Gate::define('admin', fn (User $user) => $user->seesOthers());

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        $this->watchQueue();
    }

    /** Queue worker pulse, the minute tick (the worker is the clock, no cron) and failed jobs. */
    private function watchQueue(): void
    {
        // A listener of Looping that returns false pauses the worker: these return nothing.
        Event::listen(Looping::class, function (): void {
            QueueHealth::beat();
            MinuteTick::dispatchIfDue();
        });
        Event::listen(JobProcessed::class, fn () => QueueHealth::beat());
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            app(EventRecorder::class)->error(EventKind::Queue, 'Queue job failed: '.class_basename($event->job->resolveName()), [
                'queue' => $event->job->getQueue(),
                'exception' => mb_substr($event->exception->getMessage(), 0, 500),
            ], errorKey: 'job_failed');
        });
    }
}
