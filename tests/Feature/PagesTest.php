<?php

namespace Tests\Feature;

use App\Models\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Every page renders, and its JSON endpoint answers, for the roles that may open it. */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('name="email"', false);
    }

    public function test_login_and_logout(): void
    {
        $user = $this->user();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->post('/logout')->assertRedirect('/login');
    }

    /** @return array<string, array{0: string, 1: bool}> route name => superadmin only */
    public static function pages(): array
    {
        return [
            'dashboard' => ['dashboard', false],
            'dashboard data' => ['dashboard.data', false],
            'pulse' => ['dashboard.pulse', false],
            'phones' => ['phones.index', false],
            'phones data' => ['phones.data', false],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_renders(string $route, bool $adminOnly): void
    {
        $user = $this->user();
        $this->phoneOf($user);
        Catalog::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route($route));
        $adminOnly ? $response->assertForbidden() : $response->assertOk();

        $this->actingAs($this->superadmin())->get(route($route))->assertOk();
    }
}
