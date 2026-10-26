<?php

namespace Tests\Feature\Pages;

use App\Enums\AccountStatus;
use App\Enums\EventKind;
use App\Enums\MessageStatus;
use App\Jobs\SendAccountMessages;
use App\Models\Catalog;
use App\Models\MessageGroup;
use App\Models\SystemEvent;
use App\Models\User;
use App\Models\UserPhone;
use App\Monitoring\EventRecorder;
use App\Sending\SendScheduler;
use App\Sending\SendWorker;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The superadmin monitoring section: every page and endpoint, with real data behind it. */
class MonitoringPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private UserPhone $phone;

    private MessageGroup $group;

    private SystemEvent $error;

    private SystemEvent $flood;

    private SystemEvent $info;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['telegram.alerts.bot_token' => null, 'telegram.alerts.chat_id' => null]);

        $this->admin = $this->superadmin();
        $this->user = $this->user();
        $this->phone = $this->phoneOf($this->user);

        // A send with one delivered and one failed message.
        $catalog = Catalog::factory()->for($this->user)->peers(['@alice', '@bob', '@carol'])->create();
        $this->actingAs($this->user)->postJson(route('send.store'), [
            'phone_id' => $this->phone->id,
            'catalog_ids' => [$catalog->id],
            'message' => 'Hello <b>world</b>',
        ])->assertCreated();
        $this->group = MessageGroup::query()->latest('id')->firstOrFail();
        $messages = $this->group->messages()->orderBy('id')->get();
        $messages[0]->forceFill(['status' => MessageStatus::Sent, 'sent_at' => now()])->save();
        $messages[1]->forceFill(['status' => MessageStatus::Failed, 'error_key' => 'flood_wait_30'])->save();

        // Accounts that need attention.
        $this->phoneOf($this->user, ['status' => AccountStatus::Revoked, 'last_error' => 'session_revoked']);
        $this->phoneOf($this->user, ['flood_until' => now()->addHour()]);

        // A tick a moment ago, a send job running for the account (it still has a
        // pending message, so it is listed), and that account's job failed twice before.
        Cache::put(SendScheduler::TICK_KEY, ['at' => time(), 'rounds' => 0, 'queued' => 1, 'files_deleted' => 0], 3600);
        Cache::put(SendWorker::runningKey($this->phone->id), ['pid' => 123, 'started_at' => time() - 60], 600);
        Cache::put(SendAccountMessages::failuresKey($this->phone->id), 2, 600);

        $events = app(EventRecorder::class);
        $this->error = $events->error(EventKind::Sending, 'Peer not found', ['peer' => '@ghost', 'html' => '<script>x</script>'], $this->phone->id, $this->group->id, 'peer_not_found');
        $this->flood = $events->warning(EventKind::Flood, 'Flood wait', ['seconds' => 30], $this->phone->id, errorKey: 'flood_wait_30');
        $this->info = $events->info(EventKind::System, 'Settings changed', ['changed' => ['send_delay_min' => 1]]);
    }

    /** @return array<string, array{0: string, 1?: array<string, mixed>}> */
    public static function pages(): array
    {
        return [
            'overview' => ['monitoring.index'],
            'overview data' => ['monitoring.data'],
            'events' => ['monitoring.events'],
            'events data' => ['monitoring.events.data'],
            'analytics' => ['monitoring.analytics'],
            'analytics data' => ['monitoring.analytics.data'],
            'api usage' => ['monitoring.api'],
            'api usage 7 days' => ['monitoring.api', ['days' => 7]],
            'logs' => ['monitoring.logs'],
            'logs filtered' => ['monitoring.logs', ['level' => 'ERROR', 'search' => 'nothing-matches-this']],
            'settings' => ['monitoring.settings'],
        ];
    }

    #[DataProvider('pages')]
    public function test_superadmin_opens_page_and_user_is_forbidden(string $route, array $query = []): void
    {
        $this->actingAs($this->user)->get(route($route, $query))->assertForbidden();
        $this->actingAs($this->admin)->get(route($route, $query))->assertOk();
    }

    public function test_event_page_shows_context_escaped_and_is_superadmin_only(): void
    {
        $this->actingAs($this->user)->get(route('monitoring.events.show', $this->error))->assertForbidden();

        $this->actingAs($this->admin)->get(route('monitoring.events.show', $this->error))
            ->assertOk()
            ->assertSee('Peer not found')
            ->assertSee('@ghost')
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>x</script>', false)
            ->assertSee(route('sends.show', $this->group->id));

        $this->actingAs($this->admin)->get(route('monitoring.events.show', $this->info))->assertOk()->assertSee('Settings changed');
    }

    public function test_overview_snapshot_has_live_data(): void
    {
        $this->actingAs($this->admin)->get(route('monitoring.data'))
            ->assertOk()
            ->assertJsonMissingPath('processes.supervisor')
            ->assertJsonPath('processes.tick.alive', true)
            ->assertJsonPath('processes.tick.queued', 1)
            ->assertJsonPath('processes.workers.0.account_id', $this->phone->id)
            ->assertJsonPath('processes.workers.0.pid', 123)
            ->assertJsonPath('processes.backoff.0.account_id', $this->phone->id)
            ->assertJsonPath('processes.backoff.0.failures', 2)
            ->assertJsonPath('processes.alerts_configured', false)
            ->assertJsonPath('today.sent', 1)
            ->assertJsonPath('today.failed', 1)
            ->assertJsonPath('sending.groups.0.id', $this->group->id)
            ->assertJsonPath('problems.open', 2)
            ->assertJsonCount(2, 'accounts.attention')
            ->assertJsonCount(24, 'last_24h');

        $this->actingAs($this->admin)->get(route('monitoring.index'))
            ->assertOk()
            ->assertSee('monitoringPage', false)
            ->assertSee(__('monitoring.overview.title'))
            ->assertSee(__('monitoring.overview.sending'))
            ->assertSee('php artisan schedule:run')
            ->assertSee('php artisan queue:work --queue='.config('telegram.queue'))
            ->assertSee('php artisan queue:work --queue='.config('telegram.send_queue').' --max-jobs=1')
            ->assertSee(__('monitoring.overview.no_workers_text', ['queue' => config('telegram.send_queue')]))
            ->assertDontSee('--queue=telegram,default')
            ->assertDontSee('telegram:supervisor');
    }

    public function test_overview_reports_a_dead_tick_and_no_jobs(): void
    {
        Cache::forget(SendScheduler::TICK_KEY);
        Cache::forget(SendWorker::runningKey($this->phone->id));
        Cache::forget(SendAccountMessages::failuresKey($this->phone->id));

        $this->actingAs($this->admin)->getJson(route('monitoring.data'))
            ->assertOk()
            ->assertJsonPath('processes.tick.alive', false)
            ->assertJsonPath('processes.tick.at', null)
            ->assertJsonCount(0, 'processes.workers')
            ->assertJsonCount(0, 'processes.backoff');

        // a tick older than 3 minutes is dead too
        Cache::put(SendScheduler::TICK_KEY, ['at' => time() - 600, 'rounds' => 0, 'queued' => 0, 'files_deleted' => 0], 3600);
        $this->getJson(route('monitoring.data'))
            ->assertJsonPath('processes.tick.alive', false)
            ->assertJsonPath('processes.tick.at', fn ($at) => is_string($at) && $at !== '');
    }

    public function test_events_filters(): void
    {
        $this->actingAs($this->admin);
        $ids = fn (array $query) => collect($this->getJson(route('monitoring.events.data', $query))->assertOk()->json('events'))->pluck('id')->all();

        // default: open problems, info excluded
        $this->assertEqualsCanonicalizing([$this->error->id, $this->flood->id], $ids([]));
        $info = $ids(['level' => 'info']);
        $this->assertContains($this->info->id, $info);
        $this->assertNotContains($this->error->id, $info);
        $this->assertSame([$this->error->id], $ids(['level' => 'error']));
        $this->assertSame([$this->flood->id], $ids(['kind' => 'flood']));
        $this->assertSame([$this->error->id], $ids(['search' => 'peer_not']));
        $this->assertSame([], $ids(['state' => 'resolved']));
        $this->assertEqualsCanonicalizing([$this->error->id, $this->flood->id], $ids(['phone_id' => $this->phone->id, 'state' => 'all']));

        $this->getJson(route('monitoring.events.data'))
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('counts.sending', 1)
            ->assertJsonPath('counts.flood', 1)
            ->assertJsonPath('events.0.error_text', __('errors.codes.flood_wait', ['time' => __('time.seconds', ['n' => 30]), 'seconds' => 30]));

        $this->getJson(route('monitoring.events.data', ['level' => 'nope']))->assertUnprocessable();
    }

    public function test_resolve_one_and_resolve_all_by_kind(): void
    {
        $this->actingAs($this->user)->postJson(route('monitoring.events.resolve', $this->error))->assertForbidden();

        $this->actingAs($this->admin)->postJson(route('monitoring.events.resolve', $this->error))->assertOk()->assertJsonPath('ok', true);
        $this->assertNotNull($this->error->fresh()->resolved_at);
        $this->assertNull($this->flood->fresh()->resolved_at);

        $second = app(EventRecorder::class)->error(EventKind::Session, 'Session revoked', [], $this->phone->id, errorKey: 'session_revoked');

        $this->postJson(route('monitoring.events.resolve-all'), ['kind' => 'flood'])
            ->assertOk()
            ->assertJsonPath('message', __('monitoring.resolved_count', ['count' => 1]));
        $this->assertNotNull($this->flood->fresh()->resolved_at);
        $this->assertNull($second->fresh()->resolved_at);

        $this->postJson(route('monitoring.events.resolve-all'))->assertOk();
        $this->assertNotNull($second->fresh()->resolved_at);
        $this->assertSame(0, SystemEvent::query()->problems()->open()->count());
    }

    public function test_settings_update_persists_and_validates(): void
    {
        $valid = [
            'send_delay_min' => 3,
            'send_delay_max' => 9,
            'daily_limit_per_phone' => 250,
            'flood_wait_inline_max' => 45,
            'flood_wait_jitter' => 10,
            'peer_flood_rest_hours' => 12,
            'alerts_enabled' => '0',
            'alert_min_level' => 'warning',
            'alert_repeat_minutes' => 15,
        ];

        $this->actingAs($this->user)->put(route('monitoring.settings.update'), $valid)->assertForbidden();

        $this->actingAs($this->admin)->from(route('monitoring.settings'))
            ->put(route('monitoring.settings.update'), $valid)
            ->assertRedirect(route('monitoring.settings'))
            ->assertSessionHas('success', __('settings.saved'))
            ->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $this->assertSame(3, $settings->int('send_delay_min'));
        $this->assertSame(9, $settings->int('send_delay_max'));
        $this->assertSame(250, $settings->int('daily_limit_per_phone'));
        $this->assertSame(12, $settings->int('peer_flood_rest_hours'));
        $this->assertFalse($settings->bool('alerts_enabled'));
        $this->assertSame('warning', $settings->get('alert_min_level'));

        $this->get(route('monitoring.settings'))->assertOk()->assertSee('value="250"', false)
            ->assertDontSee('max_parallel_workers')
            ->assertSee(__('settings.parallel_hint', ['queue' => config('telegram.send_queue')]));

        $this->from(route('monitoring.settings'))
            ->put(route('monitoring.settings.update'), ['send_delay_min' => 10, 'send_delay_max' => 5] + $valid)
            ->assertRedirect(route('monitoring.settings'))
            ->assertSessionHasErrors('send_delay_max');
        $this->assertSame(9, app(Settings::class)->int('send_delay_max'));
    }

    public function test_test_alert_needs_a_configured_bot(): void
    {
        $this->actingAs($this->user)->postJson(route('monitoring.settings.test-alert'))->assertForbidden();

        $this->actingAs($this->admin)->postJson(route('monitoring.settings.test-alert'))
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', __('settings.test_alert_failed'));

        config(['telegram.alerts.bot_token' => '123:abc', 'telegram.alerts.chat_id' => '-100123']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->postJson(route('monitoring.settings.test-alert'))->assertOk()->assertJsonPath('ok', true);
    }

    public function test_the_minute_tick_runs_from_the_settings_page(): void
    {
        Cache::forget(SendScheduler::TICK_KEY);

        $this->actingAs($this->user)->postJson(route('monitoring.settings.sweep'))->assertForbidden();
        $this->assertNull(Cache::get(SendScheduler::TICK_KEY));

        $this->actingAs($this->admin)->postJson(route('monitoring.settings.sweep'))
            ->assertOk()
            ->assertJsonPath('message', __('settings.sweep_done'));

        // the account still has a pending message: the tick queued its send job
        $this->assertSame(1, Cache::get(SendScheduler::TICK_KEY)['queued'] ?? null);
        Bus::assertDispatched(SendAccountMessages::class);
    }

    public function test_analytics_data_for_every_period(): void
    {
        $this->actingAs($this->admin);

        foreach (['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90] as $period => $points) {
            $response = $this->getJson(route('monitoring.analytics.data', ['period' => $period]))
                ->assertOk()
                ->assertJsonStructure(['from', 'to', 'totals', 'previous', 'series', 'errors', 'users', 'phones'])
                ->assertJsonPath('totals.sent', 1)
                ->assertJsonPath('totals.failed', 1)
                ->assertJsonPath('totals.groups', 1)
                ->assertJsonPath('errors.0.error_key', 'flood_wait')
                ->assertJsonPath('users.0.user_id', $this->user->id)
                ->assertJsonPath('phones.0.phone_id', $this->phone->id)
                ->assertJsonPath('phones.0.flood', 1);

            $period === 'today'
                ? $this->assertGreaterThanOrEqual(1, count($response->json('series')))
                : $this->assertCount($points, $response->json('series'));
        }

        $this->getJson(route('monitoring.analytics.data', ['period' => '7d', 'user_id' => $this->admin->id]))
            ->assertOk()->assertJsonPath('totals.sent', 0)->assertJsonCount(0, 'users');
        $this->getJson(route('monitoring.analytics.data', ['phone_id' => $this->phone->id]))
            ->assertOk()->assertJsonPath('totals.sent', 1);
        $this->getJson(route('monitoring.analytics.data', ['period' => '1y']))->assertUnprocessable();

        $this->get(route('monitoring.analytics'))->assertOk()->assertSee($this->user->name);
    }

    public function test_api_usage_lists_used_and_never_called_endpoints(): void
    {
        \DB::table('api_usage')->insert([
            'day' => now()->toDateString(),
            'method' => 'GET',
            'endpoint' => 'api/v1/phones',
            'user_id' => $this->user->id,
            'calls' => 12,
            'errors' => 3,
            'last_called_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('monitoring.api', ['days' => 90]))
            ->assertOk()
            ->assertSee('api/v1/phones')
            ->assertSee('25%')
            ->assertSee($this->user->name);
    }

    public function test_logs_page_shows_entries_newest_first_and_filters(): void
    {
        $dir = storage_path('logs');
        $file = $dir.'/zz-monitoring-test.log';
        file_put_contents($file, implode("\n", [
            '[2026-10-26 10:00:00] testing.INFO: first entry',
            '[2026-10-26 10:00:01] testing.ERROR: second entry <b>bold</b> {"id":1}',
            '#0 /app/Foo.php(12): stack line',
            '[2026-10-26 10:00:02] testing.WARNING: third entry',
        ])."\n");
        touch($file, time() + 60); // newest file: selected by default

        try {
            $this->actingAs($this->admin)->get(route('monitoring.logs', ['file' => 'zz-monitoring-test.log']))
                ->assertOk()
                ->assertSeeInOrder(['third entry', 'second entry', 'first entry'])
                ->assertSee('&lt;b&gt;bold&lt;/b&gt;', false)
                ->assertSee('stack line');

            $this->get(route('monitoring.logs', ['file' => 'zz-monitoring-test.log', 'level' => 'ERROR']))
                ->assertOk()->assertSee('second entry')->assertDontSee('third entry');

            $this->get(route('monitoring.logs', ['file' => 'zz-monitoring-test.log', 'search' => 'first']))
                ->assertOk()->assertSee('first entry')->assertDontSee('third entry');
        } finally {
            @unlink($file);
        }
    }
}
