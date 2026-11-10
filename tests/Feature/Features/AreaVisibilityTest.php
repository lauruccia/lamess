<?php

declare(strict_types=1);

namespace Tests\Feature\Features;

use App\Domain\Features\AreaMenu;
use App\Domain\Features\Feature;
use App\Domain\Features\FeatureRegistry;
use App\Models\User;
use App\Support\ShopAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AreaVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->role('super_admin')->create();
    }

    public function test_every_area_menu_path_maps_to_a_registered_feature(): void
    {
        foreach (['rete', 'clienti', 'referral', 'compensi', 'statini', 'statini-bonus', 'wallet', 'ricevute', 'ordini', 'abbonamenti', 'novita', 'materiali', 'eventi', 'conformita', 'onboarding', 'assistenza', 'profilo'] as $segment) {
            $key = AreaMenu::keyFor("area/{$segment}/1");
            $this->assertNotNull($key, $segment);
            $this->assertTrue(FeatureRegistry::has($key), $key);
        }
        $this->assertSame('site.piano_compensi', AreaMenu::keyFor('/piano-compensi'));
        $this->assertNull(AreaMenu::keyFor('area/checkout'));
        $this->assertNull(AreaMenu::keyFor('negozio'));
    }

    public function test_a_hidden_area_page_redirects_to_the_overview_and_leaves_the_others_open(): void
    {
        $user = User::factory()->create();
        Feature::set('area.menu.eventi', false, by: $this->owner());

        $this->actingAs($user)->get('/area/eventi')->assertRedirect('/area');
        $this->actingAs($user)->get('/area/novita')->assertOk();
    }

    public function test_the_hidden_page_disappears_from_the_area_menu(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/area')->assertSee('href="/area/eventi"', false);

        Feature::set('area.menu.eventi', false, by: $this->owner());

        $this->actingAs($user)->get('/area')->assertDontSee('href="/area/eventi"', false);
    }

    public function test_my_data_cannot_be_hidden(): void
    {
        $user = User::factory()->create();
        try {
            Feature::set('area.menu.profilo', false, by: $this->owner());
        } catch (\Throwable) {
        }

        $this->actingAs($user)->get('/area/profilo')->assertOk();
    }

    public function test_switching_the_shop_off_redirects_to_home_and_removes_buying(): void
    {
        $this->get('/negozio')->assertOk();
        $this->assertTrue(ShopAccess::canBuy());

        Feature::set('site.shop', false, by: $this->owner());

        $this->get('/negozio')->assertRedirect('/');
        $this->get('/carrello')->assertRedirect('/');
        $this->assertFalse(ShopAccess::canBuy());
    }

    public function test_the_compensation_plan_page_can_be_hidden(): void
    {
        $this->get('/piano-compensi')->assertOk();

        Feature::set('site.piano_compensi', false, by: $this->owner());

        $this->get('/piano-compensi')->assertRedirect('/');
    }
}
