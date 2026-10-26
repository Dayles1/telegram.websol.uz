<?php

namespace Tests\Feature;

use App\Accounts\AccountLogin;
use App\Enums\AccountStatus;
use App\Models\UserPhone;
use App\Telegram\ProcessLock;
use App\Telegram\SessionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Phone -> code -> (2FA password) -> authorized, through the web endpoints and the queued jobs. */
class AccountLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_phone_logs_in_with_a_code(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson(route('phones.store'), ['phone' => '+998 90 111 22 33'])
            ->assertCreated()
            ->assertJsonPath('data.phone', '+998901112233');

        $account = UserPhone::query()->sole();
        // The queue is sync in tests, so the code request already ran.
        $this->assertSame(AccountStatus::CodeSent, $account->status);
        $this->assertSame('requestCode', $this->telegram->calls[0][0]);

        $this->actingAs($user)->postJson(route('phones.code', $account), ['code' => '12345'])->assertOk();

        $account->refresh();
        $this->assertSame(AccountStatus::Authorized, $account->status);
        $this->assertSame('777', $account->telegram_user_id);
        $this->assertSame('test_account', $account->username);
        $this->assertNotNull($account->authorized_at);
    }

    public function test_two_factor_password_never_reaches_the_job_payload(): void
    {
        $user = $this->user();
        $this->telegram->passwordRequired = true;

        $this->actingAs($user)->postJson(route('phones.store'), ['phone' => '+998901112233']);
        $account = UserPhone::query()->sole();
        $this->actingAs($user)->postJson(route('phones.code', $account), ['code' => '12345']);

        $account->refresh();
        $this->assertSame(AccountStatus::NeedPassword, $account->status);
        $this->assertSame('my hint', $account->password_hint);

        $this->actingAs($user)->postJson(route('phones.password', $account), ['password' => 's3cret'])->assertOk();

        $this->assertSame(AccountStatus::Authorized, $account->refresh()->status);
        $this->assertSame(['password' => 's3cret'], collect($this->telegram->calls)->firstWhere(0, 'submitPassword')[1]);
        $this->assertNull(Cache::get(AccountLogin::passwordCacheKey($account->id)), 'the password is removed from the cache once used');
    }

    public function test_a_wrong_code_can_be_typed_again(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson(route('phones.store'), ['phone' => '+998901112233']);
        $account = UserPhone::query()->sole();

        $this->telegram->failNext('submitCode', 'PHONE_CODE_INVALID');
        $this->actingAs($user)->postJson(route('phones.code', $account), ['code' => '00000']);

        $this->assertSame(AccountStatus::CodeInvalid, $account->refresh()->status);
        $this->assertSame('code_invalid', $account->last_error);

        $this->actingAs($user)->postJson(route('phones.code', $account), ['code' => '12345']);
        $this->assertSame(AccountStatus::Authorized, $account->refresh()->status);
    }

    public function test_a_second_login_never_deletes_a_session_another_process_still_holds(): void
    {
        config(['telegram.session_busy_wait' => 0]);
        $user = $this->user();
        $path = app(SessionStore::class)->pathFor('+998901112299', $user->id);
        File::put($path, 'session');
        $previous = ProcessLock::for('session-'.sha1($path)); // the first click's process, still saving on exit
        $this->assertTrue($previous->acquire());

        try {
            $this->actingAs($user)->postJson(route('phones.store'), ['phone' => '+998901112299'])->assertCreated();

            $account = UserPhone::query()->sole();
            $this->assertSame(AccountStatus::Failed, $account->status);
            $this->assertSame('session_busy', $account->last_error);
            $this->assertFileExists($path);
            $this->assertSame([], $this->telegram->calls);
        } finally {
            $previous->release();
            File::delete($path);
        }
    }

    public function test_a_code_is_refused_when_none_is_expected(): void
    {
        $user = $this->user();
        $account = $this->phoneOf($user);

        $this->actingAs($user)->postJson(route('phones.code', $account), ['code' => '12345'])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'code_not_expected');
    }

    public function test_a_phone_connected_by_someone_else_is_refused(): void
    {
        $this->phoneOf($this->user(), ['phone' => '+998901112233']);

        $this->actingAs($this->user())->postJson(route('phones.store'), ['phone' => '998901112233'])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'phone_taken');
    }

    public function test_nobody_drives_another_users_login(): void
    {
        $account = $this->phoneOf($this->user(), ['status' => AccountStatus::CodeSent]);

        $this->actingAs($this->user())->postJson(route('phones.code', $account), ['code' => '12345'])->assertForbidden();
    }

    public function test_logout_closes_the_session_and_fails_its_queue(): void
    {
        $user = $this->user();
        $account = $this->phoneOf($user);

        $this->actingAs($user)->postJson(route('phones.logout', $account))->assertOk();

        $this->assertSame(AccountStatus::LoggedOut, $account->refresh()->status);
        $this->assertNull($account->session_path);
        $this->assertSame('logout', collect($this->telegram->calls)->last()[0]);
    }

    public function test_the_api_login_keeps_the_old_answers_and_adds_the_password_step(): void
    {
        $user = $this->user();
        $this->telegram->passwordRequired = true;

        $this->actingAs($user, 'sanctum')->postJson('/api/tg/auth', ['phone' => '+998901112233'])
            ->assertOk()->assertJsonPath('status', 'sms_sent');
        $this->actingAs($user, 'sanctum')->postJson('/api/tg/code', ['phone' => '+998901112233', 'code' => '12345'])
            ->assertOk()->assertJsonPath('status', 'processing');
        $this->actingAs($user, 'sanctum')->getJson('/api/tg/status?phone=%2B998901112233')
            ->assertOk()->assertJsonPath('awaits', 'password');
        $this->actingAs($user, 'sanctum')->postJson('/api/tg/password', ['phone' => '+998901112233', 'password' => 'x'])
            ->assertOk();

        $this->assertSame(AccountStatus::Authorized, UserPhone::query()->sole()->status);
    }
}
