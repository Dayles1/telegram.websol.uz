<?php

namespace Tests\Feature\Pages;

use App\Models\Attachment;
use App\Models\Catalog;
use App\Models\MessageGroup;
use App\Models\SendLoop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The send page and the catalog pages render and their endpoints answer per role. */
class SendAndCatalogPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_page_renders_with_phones_and_catalogs(): void
    {
        $user = $this->user();
        $phone = $this->phoneOf($user);
        Catalog::factory()->for($user)->create(['title' => 'My shops']);
        Catalog::factory()->create(['title' => 'Shared list', 'is_global' => true]);
        Catalog::factory()->create(['title' => 'Someone private']);

        $this->actingAs($user)->get(route('send.create', ['phone' => $phone->id]))
            ->assertOk()
            ->assertSee('sendPage(', false)
            ->assertSee('My shops')
            ->assertSee('Shared list')
            ->assertDontSee('Someone private')
            ->assertSee(__('send.step_message'));
    }

    public function test_send_page_offers_a_file_and_repetition(): void
    {
        $user = $this->user();
        $this->phoneOf($user);

        $this->actingAs($user)->get(route('send.create'))
            ->assertOk()
            ->assertSee('type="file"', false)
            ->assertSee(__('send.file_drop'))
            ->assertSee(__('send.repeat_toggle'))
            ->assertSee(__('send.repeat_units.day'));
    }

    public function test_send_with_a_file_is_posted_as_multipart_and_keeps_the_file(): void
    {
        Storage::fake('local');
        $user = $this->user();
        $phone = $this->phoneOf($user);
        $catalog = Catalog::factory()->for($user)->peers(['@one', '@two'])->create();

        $response = $this->actingAs($user)->post(route('send.store'), [
            'phone_id' => $phone->id,
            'catalog_ids' => [$catalog->id],
            'message' => '',
            'file' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('ok', true);

        $group = MessageGroup::query()->latest('id')->firstOrFail();
        $response->assertJsonPath('redirect', route('sends.show', $group));
        $this->assertNotNull($group->attachment_id);
        $this->assertNull($group->send_loop_id);
        $this->assertSame(2, $group->messages()->count());

        $attachment = Attachment::query()->findOrFail($group->attachment_id);
        $this->assertSame('a.pdf', $attachment->original_name);
        $this->assertSame(Attachment::KIND_DOCUMENT, $attachment->kind);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_send_needs_a_text_or_a_file_and_a_file_within_the_limit(): void
    {
        Storage::fake('local');
        $user = $this->user();
        $phone = $this->phoneOf($user);
        $catalog = Catalog::factory()->for($user)->peers(['@one'])->create();
        $base = ['phone_id' => $phone->id, 'catalog_ids' => [$catalog->id]];

        $this->actingAs($user)->post(route('send.store'), $base + ['message' => ''], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('message');

        $tooBig = UploadedFile::fake()->create('big.zip', (int) config('telegram.attachments.max_kb') + 1);
        $this->post(route('send.store'), $base + ['message' => 'Hi', 'file' => $tooBig], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->post(route('send.store'), $base + ['message' => 'Hi', 'repeat_every_minutes' => 0], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('repeat_every_minutes');

        $this->assertSame(0, MessageGroup::query()->count());
    }

    public function test_a_repeating_send_creates_its_first_round(): void
    {
        $user = $this->user();
        $phone = $this->phoneOf($user);
        $catalog = Catalog::factory()->for($user)->peers(['@one', '@two'])->create();

        $this->actingAs($user)->post(route('send.store'), [
            'phone_id' => $phone->id,
            'catalog_ids' => [$catalog->id],
            'message' => 'Every two hours',
            'repeat_every_minutes' => 120,
            'max_rounds' => 3,
        ], ['Accept' => 'application/json'])->assertCreated();

        $group = MessageGroup::query()->latest('id')->firstOrFail();
        $loop = SendLoop::query()->findOrFail($group->send_loop_id);
        $this->assertSame(120, $loop->every_minutes);
        $this->assertSame(3, $loop->max_rounds);
        $this->assertSame(1, $loop->rounds_done);
        $this->assertTrue($loop->isActive());
    }

    public function test_send_page_without_phones_links_to_connect(): void
    {
        $user = $this->user();
        $this->phoneOf($this->user()); // someone else's account is not offered

        $this->actingAs($user)->get(route('send.create'))
            ->assertOk()
            ->assertSee(__('send.no_phones_title'))
            ->assertSee(route('phones.index').'#connect', false)
            ->assertDontSee('sendPage(', false);
    }

    public function test_superadmin_send_page_renders(): void
    {
        $admin = $this->superadmin();
        $this->phoneOf($this->user());
        Catalog::factory()->create(['title' => 'Foreign catalog']);

        $this->actingAs($admin)->get(route('send.create'))->assertOk()->assertSee('Foreign catalog');
    }

    public function test_send_preview_counts_unique_peers(): void
    {
        $user = $this->user();
        $phone = $this->phoneOf($user);
        $a = Catalog::factory()->for($user)->peers(['@one', '@two', '@three'])->create();
        $b = Catalog::factory()->for($user)->peers(['@TWO', '@four'])->create();

        $this->actingAs($user)->getJson(route('send.preview', ['phone_id' => $phone->id, 'catalog_ids' => [$a->id, $b->id]]))
            ->assertOk()
            ->assertJson(['unique_peers' => 4, 'total_peers' => 5, 'sent_today' => 0]);
    }

    public function test_catalog_index_renders(): void
    {
        $this->actingAs($this->user())->get(route('catalogs.index'))
            ->assertOk()->assertSee('catalogsPage(', false)->assertSee('data-page-search', false);

        $this->actingAs($this->superadmin())->get(route('catalogs.index'))->assertOk();
    }

    public function test_catalog_data_tabs_for_a_user(): void
    {
        $user = $this->user();
        Catalog::factory()->for($user)->count(2)->create();
        Catalog::factory()->create(['is_global' => true]);
        Catalog::factory()->create();

        $this->actingAs($user)->getJson(route('catalogs.data', ['tab' => 'mine']))
            ->assertOk()->assertJsonCount(2, 'catalogs')
            ->assertJsonPath('counts.mine', 2)->assertJsonPath('counts.global', 1)->assertJsonPath('counts.others', null);
        $this->actingAs($user)->getJson(route('catalogs.data', ['tab' => 'global']))
            ->assertOk()->assertJsonCount(1, 'catalogs')->assertJsonPath('catalogs.0.is_mine', false);
        $this->actingAs($user)->getJson(route('catalogs.data', ['tab' => 'others']))->assertForbidden();
    }

    public function test_catalog_data_tabs_for_a_superadmin(): void
    {
        $admin = $this->superadmin();
        Catalog::factory()->for($admin)->create(['title' => 'Alpha']);
        Catalog::factory()->count(3)->create();

        $this->actingAs($admin)->getJson(route('catalogs.data', ['tab' => 'others']))
            ->assertOk()->assertJsonCount(3, 'catalogs')->assertJsonPath('counts.others', 3);
        $this->actingAs($admin)->getJson(route('catalogs.data', ['tab' => 'mine', 'search' => 'alp']))
            ->assertOk()->assertJsonCount(1, 'catalogs')->assertJsonPath('catalogs.0.title', 'Alpha');
        $this->actingAs($admin)->getJson(route('catalogs.data', ['tab' => 'global']))->assertOk();
    }

    public function test_catalog_create_and_edit_pages(): void
    {
        $user = $this->user();
        $mine = Catalog::factory()->for($user)->peers(['@visible_peer'])->create(['title' => 'Editable']);
        $foreign = Catalog::factory()->create(['is_global' => true]);

        $this->actingAs($user)->get(route('catalogs.create'))
            ->assertOk()->assertSee('catalogFormPage(', false)->assertDontSee('name="is_global"', false);
        $this->actingAs($user)->get(route('catalogs.edit', $mine))
            ->assertOk()->assertSee('Editable')->assertSee('@visible_peer')->assertSee('name="_method" value="PUT"', false);
        $this->actingAs($user)->get(route('catalogs.edit', $foreign))->assertForbidden();

        $admin = $this->superadmin();
        $this->actingAs($admin)->get(route('catalogs.create'))->assertOk()->assertSee('name="is_global"', false);
        $this->actingAs($admin)->get(route('catalogs.edit', $mine))->assertOk();
    }

    public function test_catalog_is_created_and_updated_through_the_form(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post(route('catalogs.store'), [
            'title' => '  Sellers  ',
            'peers_text' => "https://t.me/Shop_one/\nshop_one\n@shop_two, -1001234567890\n\nt.me/+Invite123",
            'timer_minutes' => '30',
            'is_global' => '1', // ignored for a normal user
        ])->assertRedirect(route('catalogs.index'))->assertSessionHas('success');

        $catalog = Catalog::query()->where('user_id', $user->id)->sole();
        $this->assertSame('Sellers', $catalog->title);
        $this->assertSame(['@Shop_one', '@shop_two', '-1001234567890', 't.me/+Invite123'], $catalog->peers);
        $this->assertSame(30, $catalog->timer_minutes);
        $this->assertFalse($catalog->is_global);

        $this->actingAs($user)->put(route('catalogs.update', $catalog), [
            'title' => 'Sellers 2',
            'peers_text' => "@a_new_peer\n@A_NEW_PEER",
            'timer_minutes' => '',
        ])->assertRedirect(route('catalogs.index'));

        $catalog->refresh();
        $this->assertSame('Sellers 2', $catalog->title);
        $this->assertSame(['@a_new_peer'], $catalog->peers);
        $this->assertNull($catalog->timer_minutes);
    }

    public function test_form_validation_errors_come_back_to_the_form(): void
    {
        $user = $this->user();

        $this->actingAs($user)->from(route('catalogs.create'))->post(route('catalogs.store'), ['title' => '', 'timer_minutes' => '99999'])
            ->assertRedirect(route('catalogs.create'))
            ->assertSessionHasErrors(['title', 'timer_minutes']);
    }

    public function test_superadmin_makes_a_global_catalog_and_a_user_cannot_update_foreign(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->post(route('catalogs.store'), [
            'title' => 'For all',
            'peers_text' => 'everyone_here',
            'is_global' => '1',
        ])->assertRedirect(route('catalogs.index'));

        $catalog = Catalog::query()->where('title', 'For all')->sole();
        $this->assertTrue($catalog->is_global);
        $this->assertSame(['@everyone_here'], $catalog->peers);

        $this->actingAs($this->user())->put(route('catalogs.update', $catalog), ['title' => 'Hijack', 'peers_text' => '@x_peer'])->assertForbidden();
    }

    public function test_copy_and_distribute(): void
    {
        $user = $this->user();
        $global = Catalog::factory()->create(['is_global' => true]);

        $this->actingAs($user)->postJson(route('catalogs.copy', $global))->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, Catalog::query()->where('user_id', $user->id)->count());

        $this->actingAs($user)->getJson(route('catalogs.users'))->assertForbidden();

        $admin = $this->superadmin();
        $other = $this->user();
        $this->actingAs($admin)->getJson(route('catalogs.users'))->assertOk()->assertJsonFragment(['id' => $other->id]);
        $this->actingAs($admin)->postJson(route('catalogs.distribute', $global), ['user_ids' => [$user->id, $other->id]])->assertOk();
        $this->assertSame(1, Catalog::query()->where('user_id', $other->id)->count());
    }
}
