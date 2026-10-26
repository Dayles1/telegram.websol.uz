<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Enums\LoopStatus;
use App\Enums\MessageStatus;
use App\Jobs\Accounts\CheckAccountSession;
use App\Jobs\Accounts\RequestLoginCode;
use App\Jobs\FetchChatHistory;
use App\Jobs\MinuteTick;
use App\Jobs\SendAccountMessages;
use App\Jobs\StartLoopRound;
use App\Models\Attachment;
use App\Models\Catalog;
use App\Models\MessageGroup;
use App\Models\SendLoop;
use App\Models\User;
use App\Models\UserPhone;
use App\Sending\MassSend;
use App\Sending\SendScheduler;
use App\Sending\SendWorker;
use App\Settings\Settings;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Sending through the queue: direct peers, files, repeating sends, the minute tick. */
class SendQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private UserPhone $phone;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = $this->user();
        $this->phone = $this->phoneOf($this->user);
        app(Settings::class)->update(['send_delay_min' => 0, 'send_delay_max' => 0]);
    }

    private function api(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')->post('/api/tg/send', $payload + ['phone_id' => $this->phone->id], ['Accept' => 'application/json']);
    }

    private function work(): int
    {
        return app(SendWorker::class)->sleepUsing(fn () => null)->run($this->phone->id, 60);
    }

    public function test_api_sends_to_one_peer_without_a_catalog(): void
    {
        $groupId = $this->api(['peer' => 'https://t.me/Alice', 'message' => 'hi'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('peers_count', 1)
            ->json('group_id');

        Bus::assertDispatched(SendAccountMessages::class, fn ($job) => $job->accountId === $this->phone->id);
        $this->work();

        $this->assertSame(['@Alice'], $this->telegram->sentTo());
        $this->assertSame(GroupStatus::Completed, MessageGroup::query()->findOrFail($groupId)->status);
    }

    public function test_api_takes_a_catalog_or_a_peer_but_not_both_or_none(): void
    {
        $catalog = Catalog::factory()->for($this->user)->create();

        $this->api(['peer' => '@alice', 'catalog_ids' => [$catalog->id], 'message' => 'hi'])
            ->assertUnprocessable()->assertJsonValidationErrors('catalog_ids');
        $this->api(['message' => 'hi'])
            ->assertUnprocessable()->assertJsonValidationErrors(['peer', 'catalog_ids']);
        $this->api(['peer' => '@alice'])
            ->assertUnprocessable()->assertJsonValidationErrors('message');
    }

    public function test_a_phone_number_peer_is_looked_up_by_phone(): void
    {
        $this->api(['peer' => '+998 90 123-45-67', 'message' => 'hi'])->assertOk();
        $this->api(['peer' => '+998900000000', 'message' => 'hi'])->assertOk(); // the fake knows nobody on ...0000

        $this->work();

        $this->assertCount(1, $this->telegram->sentTo());
        $this->assertStringContainsString('+998901234567', $this->telegram->sentTo()[0]);
        $this->assertSame('phone_not_found', MessageGroup::query()->latest('id')->first()->messages()->value('error_key'));
    }

    public function test_a_file_is_sent_with_the_text_as_its_caption(): void
    {
        $this->api([
            'peer' => '@alice',
            'message' => '<b>Price list</b>',
            'file' => UploadedFile::fake()->create('prices.pdf', 300, 'application/pdf'),
        ])->assertOk();

        $attachment = Attachment::query()->sole();
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame('document', $attachment->kind);

        $this->work();

        $this->assertSame([['peer' => '@alice', 'name' => 'prices.pdf', 'caption' => '<b>Price list</b>', 'photo' => false]], $this->telegram->filesSent());
    }

    public function test_a_file_alone_is_enough_and_a_long_text_follows_the_file(): void
    {
        $this->api(['peer' => '@alice', 'file' => UploadedFile::fake()->image('photo.jpg')])->assertOk();
        $this->api(['peer' => '@bob', 'message' => Str::repeat('a', MassSend::MAX_CAPTION_LENGTH + 1), 'file' => UploadedFile::fake()->image('photo.jpg')])->assertOk();

        $this->work();

        $files = $this->telegram->filesSent();
        $this->assertTrue($files[0]['photo']);
        $this->assertSame('', $files[1]['caption']);
        $this->assertSame(['@alice', '@bob', '@bob'], $this->telegram->sentTo(), 'file, then file + text for the long one');
    }

    public function test_a_file_bigger_than_50_mb_is_refused(): void
    {
        $this->api(['peer' => '@alice', 'file' => UploadedFile::fake()->create('big.zip', 51201)])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_a_refused_send_does_not_keep_its_file(): void
    {
        $theirs = Catalog::factory()->for($this->user())->create();

        $this->api(['catalog_ids' => [$theirs->id], 'file' => UploadedFile::fake()->create('a.pdf', 10)])->assertForbidden();

        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_file_is_deleted_once_nothing_can_send_it(): void
    {
        $this->api(['peer' => '@alice', 'message' => 'hi', 'file' => UploadedFile::fake()->create('a.pdf', 10)])->assertOk();
        $attachment = Attachment::query()->sole();
        $this->travel(15)->minutes();

        app(SendScheduler::class)->tick();
        Storage::disk('local')->assertExists($attachment->path); // still to be sent

        $this->work();
        app(SendScheduler::class)->tick();
        Storage::disk('local')->assertExists($attachment->path); // kept a while for "send the failed ones again"

        $this->travel((int) config('telegram.attachments.keep_minutes') + 1)->minutes();
        app(SendScheduler::class)->tick();

        Storage::disk('local')->assertMissing($attachment->path);
        $this->assertNotNull($attachment->refresh()->removed_at);
    }

    public function test_retry_is_refused_when_the_file_is_gone(): void
    {
        $this->telegram->failPeer('@alice', 'USER_PRIVACY_RESTRICTED');
        $groupId = $this->api(['peer' => '@alice', 'message' => 'hi', 'file' => UploadedFile::fake()->create('a.pdf', 10)])->json('group_id');
        $this->work();
        Attachment::query()->update(['removed_at' => now()]);

        $this->actingAs($this->user)->postJson(route('sends.retry', $groupId))
            ->assertUnprocessable()->assertJsonPath('reason', 'attachment_removed');
    }

    public function test_a_repeating_send_starts_the_next_round_after_the_pause(): void
    {
        $response = $this->api(['peer' => '@alice', 'message' => 'hi', 'repeat_every_minutes' => 30, 'max_rounds' => 2, 'file' => UploadedFile::fake()->create('a.pdf', 10)])->assertOk();
        $loop = SendLoop::query()->findOrFail($response->json('loop_id'));
        $this->assertSame(1, $loop->rounds_done);

        $this->work();

        $loop->refresh();
        $this->assertTrue($loop->next_run_at->between(now()->addMinutes(29), now()->addMinutes(31)));
        Bus::assertDispatched(StartLoopRound::class, fn ($job) => $job->loopId === $loop->id);
        $this->assertNull(app(MassSend::class)->startRound($loop->id), 'not due yet');

        $this->travel(31)->minutes();
        app(SendScheduler::class)->tick(); // starts the overdue round even if the queued job was lost
        $this->assertSame(2, MessageGroup::query()->where('send_loop_id', $loop->id)->count());

        $this->work();

        $this->assertSame(LoopStatus::Finished, $loop->refresh()->status, 'max_rounds reached');
        $this->assertSame(['@alice', '@alice'], $this->telegram->sentTo());

        $this->travel(2)->hours();
        app(SendScheduler::class)->tick();
        $this->assertNotNull(Attachment::query()->sole()->removed_at, 'the file goes once the loop is over');
    }

    public function test_stopping_a_repeating_send_cancels_its_round(): void
    {
        $loopId = $this->api(['peer' => '@alice', 'message' => 'hi', 'repeat_every_minutes' => 5])->json('loop_id');
        $stranger = $this->user();

        $this->actingAs($stranger, 'sanctum')->postJson("/api/loops/{$loopId}/stop")->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson("/api/loops/{$loopId}")->assertForbidden();

        $this->actingAs($this->user, 'sanctum')->getJson('/api/loops')
            ->assertOk()->assertJsonPath('data.0.id', $loopId)->assertJsonPath('data.0.status', 'active');
        $this->actingAs($this->user, 'sanctum')->postJson("/api/loops/{$loopId}/stop")->assertOk();
        $this->work();

        $this->assertSame([], $this->telegram->sentTo());
        $this->assertSame(LoopStatus::Stopped, SendLoop::query()->findOrFail($loopId)->status);
        $this->assertSame(GroupStatus::Canceled, MessageGroup::query()->where('send_loop_id', $loopId)->sole()->status);
        $this->actingAs($this->user, 'sanctum')->getJson("/api/loops/{$loopId}")
            ->assertOk()->assertJsonPath('data.stop_reason', 'canceled')->assertJsonPath('data.rounds.0.status', 'canceled');
    }

    public function test_canceling_a_round_stops_the_repeating(): void
    {
        $response = $this->api(['peer' => '@alice', 'message' => 'hi', 'repeat_every_minutes' => 5]);

        $this->actingAs($this->user, 'sanctum')->postJson("/api/groups/{$response->json('group_id')}/cancel")->assertOk();

        $this->assertSame(LoopStatus::Stopped, SendLoop::query()->findOrFail($response->json('loop_id'))->status);
    }

    public function test_the_job_continues_itself_when_time_is_up(): void
    {
        config(['telegram.worker.max_runtime' => 0]);
        $this->api(['peer' => '@alice', 'message' => 'hi'])->assertOk();

        $job = (new SendAccountMessages($this->phone->id))->withFakeQueueInteractions();
        $job->handle(app(SendWorker::class)->sleepUsing(fn () => null));

        $job->assertReleased();
        $this->assertSame([], $this->telegram->sentTo());
    }

    public function test_the_job_sends_and_finishes(): void
    {
        $this->api(['peer' => '@alice', 'message' => 'hi'])->assertOk();

        $job = (new SendAccountMessages($this->phone->id))->withFakeQueueInteractions();
        $job->handle(app(SendWorker::class)->sleepUsing(fn () => null));

        $job->assertNotReleased();
        $this->assertSame(['@alice'], $this->telegram->sentTo());
        $this->assertSame('telegramWebsol', (new SendAccountMessages(1))->queue, 'one queue for everything');
    }

    public function test_the_tick_queues_every_account_with_due_messages(): void
    {
        $other = $this->phoneOf($this->user);
        $this->api(['peer' => '@alice', 'message' => 'hi'])->assertOk();
        $this->api(['peer' => '@bob', 'message' => 'hi', 'phone_id' => $other->id])->assertOk();
        $flooded = $this->phoneOf($this->user, ['flood_until' => now()->addHour()]);
        MessageGroup::query()->latest('id')->first()->update(['user_phone_id' => $flooded->id]);

        $this->assertSame([$this->phone->id], app(SendScheduler::class)->dueAccounts());
        $this->assertSame(1, app(SendScheduler::class)->tick()['queued']);
    }

    public function test_the_tick_frees_a_unique_lock_no_queued_job_holds(): void
    {
        config(['queue.default' => 'database']);
        $this->api(['peer' => '@alice', 'message' => 'hi'])->assertOk();
        Bus::assertDispatchedTimes(SendAccountMessages::class, 1);

        // the job is in the table: the tick does not queue a second one
        Queue::connection('database')->push(new SendAccountMessages($this->phone->id));
        app(SendScheduler::class)->tick();
        Bus::assertDispatchedTimes(SendAccountMessages::class, 1);

        // a dispatch that threw left the lock without a job: the tick takes it back
        DB::table('jobs')->delete();
        app(SendScheduler::class)->tick();
        Bus::assertDispatchedTimes(SendAccountMessages::class, 2);
    }

    public function test_a_send_waits_while_every_send_slot_is_taken(): void
    {
        config(['telegram.worker.send_slots' => 1]);
        $this->api(['peer' => '@alice', 'message' => 'hi'])->assertOk();
        $taken = Cache::lock('telegram:send-slot:0', 60);
        $taken->get();

        $job = (new SendAccountMessages($this->phone->id))->withFakeQueueInteractions();
        $job->handle(app(SendWorker::class)->sleepUsing(fn () => null));
        $job->assertReleased(15);
        $this->assertSame([], $this->telegram->sentTo(), 'the other processes stay free for logins');

        $taken->release();
        $job = (new SendAccountMessages($this->phone->id))->withFakeQueueInteractions();
        $job->handle(app(SendWorker::class)->sleepUsing(fn () => null));
        $job->assertNotReleased();
        $this->assertSame(['@alice'], $this->telegram->sentTo());
        $this->assertTrue(Cache::lock('telegram:send-slot:0', 60)->get(), 'the job gives its slot back');
    }

    public function test_the_worker_queues_the_minute_tick_once_a_minute(): void
    {
        Bus::fake([SendAccountMessages::class, StartLoopRound::class, MinuteTick::class]);

        event(new Looping('database', 'telegramWebsol'));
        event(new Looping('database', 'telegramWebsol'));
        Bus::assertDispatchedTimes(MinuteTick::class, 1);

        // a tick still waiting for a busy worker is not queued twice
        $this->travel(61)->seconds();
        event(new Looping('database', 'telegramWebsol'));
        Bus::assertDispatchedTimes(MinuteTick::class, 1);

        (new UniqueLock(Cache::driver()))->release(new MinuteTick); // what the worker does once the tick ran
        $this->travel(61)->seconds();
        event(new Looping('database', 'telegramWebsol'));
        Bus::assertDispatchedTimes(MinuteTick::class, 2);
    }

    public function test_the_minute_tick_queues_due_sends_and_runs_the_daily_chores_once_a_day(): void
    {
        Bus::fake([SendAccountMessages::class, StartLoopRound::class, CheckAccountSession::class]);
        $this->travelTo(now()->setTime(5, 0));
        $this->api(['peer' => '@alice', 'message' => 'hi'])->assertOk();

        (new MinuteTick)->handle(app(SendScheduler::class));
        Bus::assertDispatched(SendAccountMessages::class);
        Bus::assertNotDispatched(CheckAccountSession::class);

        $this->travelTo(now()->setTime(6, 1));
        (new MinuteTick)->handle(app(SendScheduler::class));
        (new MinuteTick)->handle(app(SendScheduler::class));
        Bus::assertDispatchedTimes(CheckAccountSession::class, 1);

        $this->travelTo(now()->addDay()->setTime(6, 0));
        (new MinuteTick)->handle(app(SendScheduler::class));
        Bus::assertDispatchedTimes(CheckAccountSession::class, 2);
        $this->assertSame('telegramWebsol', (new MinuteTick)->queue);
    }

    public function test_every_job_can_be_put_on_the_database_queue(): void
    {
        // Bus::fake() never builds a payload; the real queue does (a private backoff() once broke every send).
        $queue = Queue::connection('database');
        $queue->push(new SendAccountMessages($this->phone->id));
        $queue->push(new MinuteTick);
        $queue->push(new StartLoopRound(1));
        $queue->push(new RequestLoginCode($this->phone->id));
        $queue->push(new CheckAccountSession($this->phone->id));
        $queue->push(new FetchChatHistory(1));

        $this->assertSame(6, DB::table('jobs')->count());
    }

    public function test_peers_of_catalogs_and_a_retried_send_keep_the_file(): void
    {
        $this->telegram->failPeer('@bob', 'USER_PRIVACY_RESTRICTED');
        $catalog = Catalog::factory()->for($this->user)->peers(['@alice', '@bob'])->create();
        $groupId = $this->api(['catalog_ids' => [$catalog->id], 'message' => 'hi', 'file' => UploadedFile::fake()->create('a.pdf', 10)])->json('group_id');
        $this->work();

        $retry = $this->actingAs($this->user)->postJson(route('sends.retry', $groupId))->assertOk();
        $this->work();

        $this->assertSame(['@alice', '@bob'], array_column($this->telegram->filesSent(), 'peer'));
        $this->assertNotNull($retry->json('redirect'));
        $this->assertSame(MessageGroup::query()->findOrFail($groupId)->attachment_id, MessageGroup::query()->latest('id')->first()->attachment_id);
        $this->assertSame(MessageStatus::Sent, MessageGroup::query()->latest('id')->first()->messages()->sole()->status);
    }
}
