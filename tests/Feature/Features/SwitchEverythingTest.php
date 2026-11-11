<?php

declare(strict_types=1);

namespace Tests\Feature\Features;

use App\Domain\Access\Roles;
use App\Domain\Audit\AuditLog;
use App\Domain\Features\Feature;
use App\Domain\Features\FeatureException;
use App\Domain\Features\FeatureRegistry;
use App\Filament\Pages\FeaturesPage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Funzionalità e viste» deve poter accendere e spegnere tutto: pagine pubbliche, candidature, voci di sistema e interi gruppi.
 */
final class SwitchEverythingTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->role('super_admin')->create();
    }

    private function admin(): User
    {
        $u = User::factory()->role('admin')->create();
        $u->givePermissionTo(['network.view', 'orders.view', 'features.manage']);

        return $u;
    }

    /** Guardia per il futuro: una pagina o una lista nuova del back-office senza interruttore non passa i test. */
    public function test_every_backoffice_resource_and_page_has_a_switch_in_the_registry(): void
    {
        $panel = Filament::getPanel('admin');
        $missing = [];
        $skip = ['Dashboard', 'FeaturesPage'];   // il cruscotto è la home; «Funzionalità e viste» è protetta (lock) e ha il suo permesso

        foreach ($panel->getResources() as $resource) {
            $file = (string) (new \ReflectionClass($resource))->getFileName();
            $src = (string) file_get_contents($file);
            $viaTrait = str_contains($src, 'GatedByPermission');
            if ($viaTrait) {
                $key = 'backoffice.resource.'.str_replace('-', '_', $resource::getSlug());
                FeatureRegistry::has($key) || $missing[] = "{$resource}: manca {$key}";

                continue;
            }
            preg_match_all("/Feature::enabled\\('([a-z0-9_.]+)'/", $src, $m);
            $m[1] === [] && $missing[] = "{$resource}: nessun controllo Feature::enabled()";
            foreach ($m[1] as $key) {
                FeatureRegistry::has($key) || $missing[] = "{$resource}: chiave {$key} non nel registro";
            }
        }
        foreach ($panel->getPages() as $page) {
            $base = class_basename($page);
            if (in_array($base, $skip, true)) {
                continue;
            }
            $src = (string) file_get_contents((string) (new \ReflectionClass($page))->getFileName());
            preg_match_all("/Feature::enabled\\('([a-z0-9_.]+)'/", $src, $m);
            $m[1] === [] && $missing[] = "{$base}: nessun controllo Feature::enabled()";
            foreach ($m[1] as $key) {
                FeatureRegistry::has($key) || $missing[] = "{$base}: chiave {$key} non nel registro";
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_each_public_front_page_can_be_switched_off_and_leaves_the_menu(): void
    {
        $owner = $this->owner();
        $pages = ['prodotto' => '/il-prodotto', 'perche' => '/perche-lamess', 'scienza' => '/la-scienza', 'lavora' => '/lavora-con-noi', 'chi_siamo' => '/chi-siamo', 'faq' => '/domande', 'contatti' => '/contatti'];

        foreach ($pages as $slug => $url) {
            $this->get($url)->assertOk();
        }
        foreach ($pages as $slug => $url) {
            Feature::set("site.front.{$slug}", false, by: $owner);
            $this->get($url)->assertRedirect('/');
            $this->get('/privacy')->assertOk()->assertDontSee('href="'.$url.'"', false);
            $this->get('/')->assertOk();
        }
        // spente tutte e sette, la home e le altre pagine continuano a rispondere
        $this->get('/accedi')->assertOk();
        $this->get('/negozio')->assertOk();
    }

    public function test_switching_a_front_page_back_on_restores_it_and_its_links(): void
    {
        $owner = $this->owner();
        Feature::set('site.front.faq', false, by: $owner);
        $this->get('/domande')->assertRedirect('/');
        Feature::set('site.front.faq', true, by: $owner);
        $this->get('/domande')->assertOk();
        $this->get('/privacy')->assertSee('href="/domande"', false);
    }

    public function test_hidden_front_pages_do_not_leave_buttons_pointing_to_them_on_the_home(): void
    {
        $owner = $this->owner();
        $this->get('/')->assertSee('href="/perche-lamess"', false)->assertSee('href="/domande"', false);

        Feature::set('site.front.perche', false, by: $owner);
        Feature::set('site.front.faq', false, by: $owner);
        Feature::set('site.front.contatti', false, by: $owner);

        $this->get('/')->assertOk()->assertDontSee('href="/perche-lamess"', false)->assertDontSee('href="/domande"', false)->assertDontSee('href="/contatti"', false);
    }

    public function test_privacy_and_terms_are_protected_and_the_other_legal_pages_can_be_switched(): void
    {
        $owner = $this->owner();
        foreach (['site.legal.privacy', 'site.legal.termini'] as $key) {
            try {
                Feature::set($key, false, by: $owner);
                $this->fail("{$key} non doveva potersi spegnere");
            } catch (\Throwable) {
                $this->addToAssertionCount(1);
            }
        }
        $this->get('/privacy')->assertOk();
        $this->get('/termini')->assertOk();

        foreach (['politiche-di-reso' => 'politiche_reso', 'regolamento' => 'regolamento', 'lettera-di-incarico' => 'lettera_incarico', 'liberatoria-foto' => 'liberatoria_foto'] as $url => $slug) {
            $this->get("/{$url}")->assertOk();
            Feature::set("site.legal.{$slug}", false, by: $owner);
            $this->get("/{$url}")->assertRedirect('/');
        }
        $this->get('/privacy')->assertOk()->assertDontSee('href="/politiche-di-reso"', false);
    }

    public function test_applications_form_can_be_switched_off(): void
    {
        $owner = $this->owner();
        $this->get('/candidati')->assertOk();
        Feature::set('site.apply', false, by: $owner);
        $this->get('/candidati')->assertRedirect('/');
        $this->post('/candidati', [])->assertRedirect('/');
        $this->get('/candidati/grazie')->assertRedirect('/');
    }

    public function test_area_extras_cessione_and_become_consultant_can_be_switched_off(): void
    {
        $owner = $this->owner();
        $user = User::factory()->create();
        Feature::set('area.menu.cessione', false, by: $owner);
        Feature::set('area.menu.diventa', false, by: $owner);

        $this->actingAs($user)->get('/area/cessione')->assertRedirect('/area');
        $this->actingAs($user)->get('/area/diventa-incaricato')->assertRedirect('/area');
        $this->actingAs($user)->get('/area')->assertOk()->assertDontSee('/area/diventa-incaricato', false);
    }

    public function test_system_pages_are_switchable_by_the_super_admin_only_and_come_back(): void
    {
        $owner = $this->owner();
        $admin = $this->admin();
        $this->actingAs($owner)->get('/admin/backup')->assertOk();

        $this->assertFalse(Feature::enabled('backoffice.page.backup', $admin), 'l\'admin non vede le pagine di sistema nemmeno se accese');
        try {
            Feature::set('backoffice.page.backup', false, by: $admin);
            $this->fail('l\'admin non doveva poter cambiare una voce di sistema');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        Feature::set('backoffice.page.backup', false, by: $owner);
        $this->actingAs($owner)->get('/admin/backup')->assertForbidden();
        Feature::set('backoffice.page.backup', true, by: $owner);
        $this->actingAs($owner)->get('/admin/backup')->assertOk();
    }

    public function test_system_lists_users_and_audit_can_be_switched_off(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->get('/admin/users')->assertOk();
        $this->actingAs($owner)->get('/admin/audit-logs')->assertOk();

        Feature::set('backoffice.resource.users', false, by: $owner);
        Feature::set('backoffice.resource.audit_logs', false, by: $owner);
        $this->actingAs($owner)->get('/admin/users')->assertForbidden();
        $this->actingAs($owner)->get('/admin/audit-logs')->assertForbidden();
    }

    public function test_the_features_page_itself_cannot_be_switched_off(): void
    {
        $owner = $this->owner();
        $this->expectException(FeatureException::class);
        Feature::set('backoffice.page.funzionalita', false, by: $owner);
    }

    public function test_group_button_switches_off_a_whole_group_and_skips_what_is_protected(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test(FeaturesPage::class)->call('setGroup', FeatureRegistry::GROUP_SITE, false)->assertNotified();

        $this->assertFalse(Feature::enabled('site.front.prodotto', $owner));
        $this->assertFalse(Feature::enabled('site.apply', $owner));
        $this->assertTrue(Feature::enabled('site.legal.privacy', $owner), 'le pagine protette restano accese');
        $this->assertTrue(Feature::enabled('site.legal.termini', $owner));
        $this->get('/il-prodotto')->assertRedirect('/');

        Livewire::test(FeaturesPage::class)->call('setGroup', FeatureRegistry::GROUP_SITE, true)->assertNotified();
        $this->assertTrue(Feature::enabled('site.front.prodotto', $owner));
        $this->get('/il-prodotto')->assertOk();
    }

    public function test_group_button_is_one_audit_row_and_keeps_the_chain_valid(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        Livewire::test(FeaturesPage::class)->call('setGroup', FeatureRegistry::GROUP_SITE, false);

        $rows = DB::table('audit_logs')->where('action', 'features.set_many')->get();
        $this->assertCount(1, $rows);
        $this->assertTrue(AuditLog::verify()->ok);
    }

    public function test_group_on_does_not_turn_on_features_that_are_off_by_default(): void
    {
        $admin = $this->admin();   // il super_admin vede comunque tutto: si prova con un admin
        $this->actingAs($admin);
        $this->assertFalse(Feature::enabled('consultant360.action.reveal_iban', $admin));

        Livewire::test(FeaturesPage::class)->call('setGroup', FeatureRegistry::GROUP_CARD, true);

        $this->assertFalse(Feature::enabled('consultant360.action.reveal_iban', $admin), 'l\'IBAN per intero si accende solo a mano');
        $this->assertTrue(Feature::enabled('consultant360.tab.network', $admin));
    }

    public function test_group_button_for_an_admin_skips_super_only_and_locked_rules(): void
    {
        $owner = $this->owner();
        $admin = $this->admin();
        Feature::set('consultant360.tab.volumes', true, lock: true, by: $owner);
        $this->actingAs($admin);

        Livewire::test(FeaturesPage::class)->call('setGroup', FeatureRegistry::GROUP_CARD, false);

        $this->assertFalse(Feature::enabled('consultant360.tab.network', $admin));
        $this->assertTrue(Feature::enabled('consultant360.tab.volumes', $admin), 'regola bloccata dal super_admin');
        $this->assertTrue(Feature::enabled('consultant360.tab.audit', $owner), 'protetta');
        $this->assertTrue(Feature::enabled('consultant360.action.move_sponsor', $owner), 'riservata al super_admin: l\'admin non la tocca');
    }

    public function test_group_button_needs_the_permission(): void
    {
        $noPerm = User::factory()->role('admin')->create();
        $this->actingAs($noPerm);
        $this->get('/admin/funzionalita')->assertForbidden();
        $this->expectException(FeatureException::class);
        Feature::setMany(['consultant360.tab.network'], false, by: $noPerm);
    }

    public function test_filters_show_only_off_or_customised_features(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        Feature::set('site.front.faq', false, by: $owner);

        Livewire::test(FeaturesPage::class)->set('filter', 'off')
            ->assertSee('Pagina «Domande frequenti»')
            ->assertDontSee('Pagina «Contatti»');
        Livewire::test(FeaturesPage::class)->set('filter', 'custom')
            ->assertSee('Pagina «Domande frequenti»')
            ->assertDontSee('Pagina «Chi siamo»');
        Livewire::test(FeaturesPage::class)->assertSee('Pagina «Chi siamo»')->assertSee('Spegni tutto');
    }

    public function test_every_group_has_a_clear_name_and_roles_unchanged(): void
    {
        $groups = array_unique(array_column(FeatureRegistry::all(), 'group'));
        foreach ($groups as $g) {
            $this->assertNotSame('', trim((string) $g));
        }
        $this->assertContains('features.manage', Roles::grantable());
    }
}
