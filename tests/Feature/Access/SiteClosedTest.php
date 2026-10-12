<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Roles;
use App\Domain\Settings\SystemSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SiteClosedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['site.open' => false]);
    }

    public function test_visitors_see_the_coming_soon_page_with_503_and_no_indexing(): void
    {
        foreach (['/', '/negozio', '/accedi', '/registrazione', '/privacy', '/r/LM00001', '/area'] as $url) {
            $this->get($url)->assertStatus(503)->assertSee('Lamèss apre presto')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        $this->post('/registrazione', ['email' => 'a@example.test'])->assertStatus(503);
    }

    public function test_portal_users_are_closed_out_too(): void
    {
        foreach (['consultant', 'customer'] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get('/area')->assertStatus(503);
        }
    }

    public function test_backoffice_stays_reachable_and_staff_see_the_site(): void
    {
        $this->get('/admin/login')->assertOk();
        $this->get('/up')->assertOk();

        $admin = User::factory()->role(Roles::SUPER_ADMIN)->create();
        $this->actingAs($admin)->get('/negozio')->assertOk()->assertDontSee('Lamèss apre presto');
        $this->get('/admin')->assertOk();
    }

    public function test_opening_the_site_from_settings_shows_it_to_everyone(): void
    {
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $this->assertFalse((bool) SystemSettings::value('site_open'));

        SystemSettings::update(['site_open' => true]);
        SystemSettings::applyToConfig();
        auth()->logout();

        $this->get('/negozio')->assertOk();
    }

    public function test_closed_is_the_default_when_nothing_is_configured(): void
    {
        $this->assertStringContainsString("env('LAMESS_SITE_OPEN', false)", (string) file_get_contents(base_path('config/site.php')));
    }
}
