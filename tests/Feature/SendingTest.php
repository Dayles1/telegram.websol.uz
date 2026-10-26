<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\GroupStatus;
use App\Enums\MessageStatus;
use App\Models\Catalog;
use App\Models\MessageGroup;
use App\Models\SystemEvent;
use App\Models\User;
use App\Models\UserPhone;
use App\Sending\SendScheduler;
use App\Sending\SendWorker;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SendingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private UserPhone $phone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->user();
        $this->phone = $this->phoneOf($this->user);
        app(Settings::class)->update(['send_delay_min' => 0, 'send_delay_max' => 0]);
    }

    private function send(array $peers, ?User $as = null): MessageGroup
    {
        $catalog = Catalog::factory()->for($this->user)->peers($peers)->create();
        $this->actingAs($as ?? $this->user)->postJson(route('send.store'), [
            'phone_id' => $this->phone->id,
            'catalog_ids' => [$catalog->id],
            'message' => '<b>Hello</b>',
        ])->assertCreated();

        return MessageGroup::query()->latest('id')->firstOrFail();
    }

    private function work(): int
    {
        return app(SendWorker::class)->sleepUsing(fn () => null)->run($this->phone->id, 60);
    }

    public function test_peers_are_deduplicated_and_every_one_is_sent_once(): void
    {
        $group = $this->send(['@alice', 'alice', 'https://t.me/Alice', '@bob']);

        $this->assertSame(2, $group->messages()->count());
        $this->assertSame(SendWorker::DONE, $this->work());
        $this->assertSame(SendWorker::DONE, $this->work()); // a second run finds nothing left

        $this->assertSame(['@alice', '@bob'], $this->telegram->sentTo());
        $this->assertSame(GroupStatus::Completed, $group->refresh()->status);
        $this->assertNotNull($group->finished_at);
        $this->assertSame(2, $group->messages()->where('status', MessageStatus::Sent)->whereNotNull('telegram_message_id')->count());
    }

    public function test_a_peer_error_fails_only_that_message(): void
    {
        $this->telegram->failPeer('@ghost', 'USERNAME_NOT_OCCUPIED');
        $group = $this->send(['@ghost', '@bob']);

        $this->work();

        $this->assertSame('peer_not_found', $group->messages()->where('peer', '@ghost')->value('error_key'));
        $this->assertSame(MessageStatus::Sent, $group->messages()->where('peer', '@bob')->first()->status);
        $this->assertSame(GroupStatus::Completed, $group->refresh()->status);
    }

    public function test_a_long_flood_wait_pauses_the_account_and_keeps_the_message(): void
    {
        $this->telegram->failPeer('@alice', 'FLOOD_WAIT_600');
        $group = $this->send(['@alice', '@bob']);

        $this->assertSame(SendWorker::DONE, $this->work());

        $this->assertTrue($this->phone->refresh()->isFlooded());
        $this->assertSame(2, $group->messages()->where('status', MessageStatus::Pending)->count(), 'nothing failed, nothing was sent past the wait');
        $this->assertSame([], app(SendScheduler::class)->dueAccounts(), 'a paused account is not queued');
        $this->assertTrue(SystemEvent::query()->where('error_key', 'flood_wait')->exists());
    }

    public function test_a_dead_session_retires_the_account_and_fails_its_queue(): void
    {
        $this->telegram->failPeer('@alice', 'AUTH_KEY_UNREGISTERED');
        $group = $this->send(['@alice', '@bob']);

        $this->assertSame(SendWorker::NOT_STARTABLE, $this->work());

        $this->assertSame(AccountStatus::Revoked, $this->phone->refresh()->status);
        $this->assertSame(2, $group->messages()->where('error_key', 'session_revoked')->count());

        app(SendScheduler::class)->sweep();
        $this->assertSame(GroupStatus::Failed, $group->refresh()->status);
    }

    public function test_cancel_stops_the_rest(): void
    {
        $group = $this->send(['@a1', '@a2', '@a3']);

        $this->actingAs($this->user)->postJson(route('sends.cancel', $group))->assertOk();
        $this->work();

        $this->assertSame([], $this->telegram->sentTo());
        $this->assertSame(GroupStatus::Canceled, $group->refresh()->status);
        $this->assertSame(3, $group->messages()->where('status', MessageStatus::Canceled)->count());
    }

    public function test_a_stale_claim_goes_back_to_the_queue(): void
    {
        $group = $this->send(['@alice']);
        $group->messages()->update(['status' => MessageStatus::Sending, 'claimed_at' => now()->subHour()]);

        app(SendScheduler::class)->sweep();
        $this->work();

        $this->assertSame(['@alice'], $this->telegram->sentTo());
    }

    public function test_old_pending_rows_are_never_sent(): void
    {
        // e.g. left pending by the previous version months ago
        $group = $this->send(['@alice', '@bob']);
        $group->messages()->where('peer', '@alice')->update(['created_at' => now()->subDays(40)]);

        $this->work();
        $this->assertSame(['@bob'], $this->telegram->sentTo());

        app(SendScheduler::class)->sweep();
        $this->assertSame('expired', $group->messages()->where('peer', '@alice')->value('error_key'));
        $this->assertSame(GroupStatus::Completed, $group->refresh()->status);
    }

    public function test_catalog_timer_blocks_a_second_send(): void
    {
        $catalog = Catalog::factory()->for($this->user)->create(['timer_minutes' => 30]);
        $payload = ['phone_id' => $this->phone->id, 'catalog_ids' => [$catalog->id], 'message' => 'hi'];

        $this->actingAs($this->user)->postJson(route('send.store'), $payload)->assertCreated();
        $this->actingAs($this->user)->postJson(route('send.store'), $payload)
            ->assertStatus(429)->assertJsonPath('reason', 'catalog_cooldown');
    }

    public function test_nobody_sends_from_another_users_phone_or_to_their_catalog(): void
    {
        $stranger = $this->user();
        $theirs = Catalog::factory()->for($stranger)->create();
        $mine = Catalog::factory()->for($this->user)->create();

        $this->actingAs($stranger)->postJson(route('send.store'), [
            'phone_id' => $this->phone->id, 'catalog_ids' => [$theirs->id], 'message' => 'hi',
        ])->assertForbidden();

        $this->actingAs($this->user)->postJson(route('send.store'), [
            'phone_id' => $this->phone->id, 'catalog_ids' => [$theirs->id, $mine->id], 'message' => 'hi',
        ])->assertForbidden()->assertJsonPath('reason', 'catalog_not_allowed');
    }

    public function test_nobody_sees_or_cancels_another_users_send(): void
    {
        $group = $this->send(['@alice']);
        $stranger = $this->user();

        $this->actingAs($stranger)->get(route('sends.show', $group))->assertForbidden();
        $this->actingAs($stranger)->postJson(route('sends.cancel', $group))->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson("/api/groups/{$group->id}")->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->postJson("/api/groups/{$group->id}/cancel")->assertForbidden();
    }

    public function test_failed_peers_can_be_sent_again(): void
    {
        $this->telegram->failPeer('@alice', 'RPC_CALL_FAIL');
        $this->telegram->failPeer('@bob', 'USER_PRIVACY_RESTRICTED');
        config(['telegram.worker.max_attempts' => 1]);
        $group = $this->send(['@alice', '@bob', '@carol']);
        $this->work();

        $this->actingAs($this->user)->postJson(route('sends.retry', $group))->assertOk();
        $retry = MessageGroup::query()->latest('id')->first();

        $this->assertNotSame($group->id, $retry->id);
        $this->assertEqualsCanonicalizing(['@alice', '@bob'], $retry->messages()->pluck('peer')->all());
    }

    public function test_api_send_keeps_the_old_contract(): void
    {
        $catalog = Catalog::factory()->for($this->user)->create();

        $this->actingAs($this->user, 'sanctum')->postJson('/api/tg/send', [
            'phone' => $this->phone->phone,
            'catalog_ids' => [$catalog->id],
            'message' => 'hi',
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->actingAs($this->user, 'sanctum')->getJson('/api/history')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.data.0.messages_status_count.pending', 2)
            ->assertJsonStructure(['data' => ['data' => [['id', 'text', 'creator', 'phone_number', 'messages', 'started_at', 'last_sent_at']]]]);
    }

    public function test_single_message_task_goes_through_the_same_worker(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/telegram/single-messages', [
            'sender_phone_id' => $this->phone->id,
            'target_tg_id' => '12345',
            'message' => 'hi',
        ])->assertStatus(202);

        $this->work();

        $this->actingAs($this->user, 'sanctum')->getJson('/api/telegram/single-messages')
            ->assertOk()->assertJsonPath('data.0.status', 'sent');
        $this->actingAs($this->user(), 'sanctum')->postJson('/api/telegram/single-messages', [
            'sender_phone_id' => $this->phone->id, 'target_tg_id' => '1', 'message' => 'x',
        ])->assertNotFound();
    }
}
