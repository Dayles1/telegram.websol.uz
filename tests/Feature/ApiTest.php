<?php

namespace Tests\Feature;

use App\Models\Catalog;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The API keeps its old URLs and shapes, and the holes of the old version stay closed. */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_a_token_that_works(): void
    {
        $user = $this->user();

        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonStructure(['user' => ['id', 'email'], 'token'])->json('token');

        $this->withToken($token)->getJson('/api/profile')->assertOk()->assertJsonPath('id', $user->id);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
    }

    public function test_phones_list_has_the_old_shape(): void
    {
        $user = $this->user();
        $this->phoneOf($user);

        $this->actingAs($user, 'sanctum')->getJson('/api/phones')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.my_phones.count', 1)
            ->assertJsonPath('data.others_phones.visible', false)
            ->assertJsonStructure(['data' => ['my_phones' => ['items' => [['id', 'phone', 'telegram_user_id', 'is_owner', 'can_control', 'session_exists', 'owner']]], 'stats']]);
    }

    public function test_catalog_crud_and_ownership(): void
    {
        $user = $this->user();

        $id = $this->actingAs($user, 'sanctum')->postJson('/api/catalogs', ['title' => 'Shops', 'peers' => ['shop_one', '@shop_one', 't.me/shop_two']])
            ->assertCreated()->assertJsonPath('success', true)->json('data.id');
        $this->assertSame(['@shop_one', '@shop_two'], Catalog::query()->find($id)->peers);

        $this->actingAs($user, 'sanctum')->getJson('/api/catalogs')->assertOk()->assertJsonPath('data.my_catalogs.total', 1);
        $this->actingAs($user, 'sanctum')->putJson("/api/catalogs/{$id}", ['title' => 'Shops 2', 'peers' => ['@x']])->assertOk();

        $stranger = $this->user();
        $this->actingAs($stranger, 'sanctum')->getJson("/api/catalogs/{$id}")->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->deleteJson("/api/catalogs/{$id}")->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->postJson('/api/catalogs/duplicate', ['catalog_id' => $id, 'user_ids' => [$stranger->id]])->assertForbidden();

        $this->actingAs($user, 'sanctum')->deleteJson("/api/catalogs/{$id}")->assertOk();
    }

    public function test_only_a_superadmin_manages_users(): void
    {
        $user = $this->user();
        $superRole = Role::query()->firstOrCreate(['name' => 'superadmin']);

        // The old API let any token create a superadmin.
        $this->actingAs($user, 'sanctum')->postJson('/api/users', [
            'name' => 'Evil', 'email' => 'evil@example.com', 'password' => 'password1', 'role_id' => $superRole->id,
        ])->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/users')->assertForbidden();

        $admin = $this->superadmin();
        $this->actingAs($admin, 'sanctum')->getJson('/api/users')->assertOk();
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/users/{$admin->id}")->assertForbidden();
    }

    public function test_statistics_need_a_token_and_reject_injection(): void
    {
        $this->getJson('/api/stats/top-users')->assertForbidden();
        $this->getJson('/api/stats/dashboard-realtime')->assertForbidden();

        config(['telegram.stats_token' => 'dash-secret']);
        $this->withHeader('X-Stats-Token', 'dash-secret')->getJson('/api/stats/top-users?period=week')
            ->assertOk()->assertJsonStructure(['generated_at', 'period', 'type', 'top', 'bottom']);
        $this->withHeader('X-Stats-Token', 'dash-secret')->getJson('/api/stats/dashboard-realtime')->assertOk();

        // The old endpoint put this straight into SQL.
        $this->withHeader('X-Stats-Token', 'dash-secret')
            ->getJson("/api/stats/top-users-by-status?status=failed' OR '1'='1")
            ->assertUnprocessable();
        $this->withHeader('X-Stats-Token', 'dash-secret')->getJson('/api/stats/top-users-by-status?status=sent')
            ->assertOk()->assertJsonPath('status', 'sent');

        $this->actingAs($this->superadmin(), 'sanctum')->getJson('/api/stats/top-users')->assertOk();
    }

    public function test_removed_holes_stay_removed(): void
    {
        $this->postJson('/api/test')->assertNotFound();
        $this->post('/tg/send')->assertNotFound();
        $this->assertFileDoesNotExist(public_path('phpinfo.php'));
    }

    public function test_infinity_endpoints_answer_without_crashing(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->postJson('/api/tg/sendInf', [])->assertStatus(410);
        $this->actingAs($user, 'sanctum')->getJson('/api/index/infinity')->assertOk()->assertJsonPath('data.total', 0);
        $this->actingAs($user, 'sanctum')->getJson('/api/index/mass')->assertOk()->assertJsonPath('type', 'history');
    }

    public function test_every_call_is_counted(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'sanctum')->getJson('/api/phones');
        $this->actingAs($user, 'sanctum')->getJson('/api/phones');

        $this->assertDatabaseHas('api_usage', ['endpoint' => 'api/phones', 'method' => 'GET', 'user_id' => $user->id, 'calls' => 2]);
    }

    public function test_errors_have_one_shape_even_without_an_accept_header(): void
    {
        $user = $this->user();
        $phone = $this->phoneOf($user);
        $catalog = Catalog::factory()->for($user)->create(['timer_minutes' => 30]);
        $send = ['phone_id' => $phone->id, 'catalog_ids' => [$catalog->id], 'message' => 'hi'];

        $this->actingAs($user, 'sanctum')->post('/api/tg/send', $send)->assertOk();
        $this->actingAs($user, 'sanctum')->post('/api/tg/send', $send)
            ->assertStatus(429)->assertJsonPath('status', 'error')->assertJsonPath('reason', 'catalog_cooldown');

        $this->actingAs($user, 'sanctum')->post('/api/tg/send', ['phone_id' => $phone->id])
            ->assertUnprocessable()->assertJsonPath('status', 'validation_error')->assertJsonStructure(['message', 'errors' => ['message']]);
        $this->actingAs($user, 'sanctum')->getJson('/api/groups/999999')
            ->assertNotFound()->assertExactJson(['status' => 'error', 'message' => 'Not found.']);
        $this->actingAs($this->user(), 'sanctum')->getJson('/api/catalogs/'.$catalog->id)
            ->assertForbidden()->assertJsonPath('status', 'error');
    }

    public function test_history_keeps_the_old_field_types(): void
    {
        $user = $this->user();
        $phone = $this->phoneOf($user);
        $this->actingAs($user, 'sanctum')->postJson('/api/tg/send', ['phone_id' => $phone->id, 'peer' => '@alice', 'message' => 'hi'])->assertOk();

        $row = $this->actingAs($user, 'sanctum')->getJson('/api/history')->assertOk()->json('data.data.0');

        $this->assertSame($user->name, $row['creator']);
        $this->assertMatchesRegularExpression('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/', $row['started_at']);
        $this->assertSame('Hali yuborilmagan', $row['last_sent_at']);
    }

    public function test_a_catalog_does_not_show_its_owners_email(): void
    {
        $user = $this->user();
        $catalog = Catalog::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/catalogs/'.$catalog->id)
            ->assertOk()->assertJsonPath('data.user.name', $user->name)->assertJsonMissingPath('data.user.email');
    }
}
