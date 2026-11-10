<?php

declare(strict_types=1);

namespace Tests\Feature\I18n;

use App\Domain\I18n\Locales;
use App\Models\Language;
use App\Models\TranslationString;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LocaleRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function activate(string ...$codes): void
    {
        Language::whereIn('code', $codes)->update(['status' => Language::ACTIVE]);
        Locales::forgetLanguages();
    }

    public function test_the_seeded_languages_are_the_six_of_the_selector_and_only_italian_is_active(): void
    {
        $this->assertSame(['it', 'en', 'nl', 'fr', 'de', 'es'], Locales::allCodes());
        $this->assertSame(['it'], Locales::activeCodes());
        $this->get('/domande')->assertOk()->assertSee('<html lang="it">', false)->assertDontSee('lang-sw', false);
    }

    public function test_a_prefixed_page_opens_in_that_language_and_links_keep_the_prefix(): void
    {
        $this->activate('en');

        $this->get('/en/domande')->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('href="/en/negozio"', false)
            ->assertSee('href="/en/accedi"', false)
            ->assertSee('hreflang="x-default"', false)
            ->assertCookie('lm_lang', 'en', false);
    }

    public function test_the_unprefixed_page_follows_the_cookie_and_stays_italian_without_it(): void
    {
        $this->activate('en');

        $this->get('/domande')->assertSee('<html lang="it">', false)->assertSee('href="/negozio"', false);
        $this->withUnencryptedCookie('lm_lang', 'en')->get('/domande')->assertSee('<html lang="en">', false);
    }

    public function test_the_italian_prefix_redirects_to_the_canonical_address_and_remembers_the_choice(): void
    {
        $this->activate('en');

        $this->get('/it/domande')->assertRedirect('/domande')->assertCookie('lm_lang', 'it', false);
    }

    public function test_a_draft_language_is_a_404_for_visitors_and_a_preview_for_the_backoffice(): void
    {
        $this->get('/nl/domande')->assertNotFound();

        $this->actingAs(User::factory()->role('super_admin')->create())->get('/nl/domande')->assertOk()->assertSee('<html lang="nl">', false);
    }

    public function test_the_prefix_only_works_in_front_of_public_pages(): void
    {
        $this->activate('en');

        $this->get('/en/area')->assertNotFound();
        $this->get('/en/admin')->assertNotFound();
    }

    public function test_forms_and_redirects_keep_the_language(): void
    {
        $this->activate('en');

        $this->post('/en/accedi', ['email' => 'nessuno@example.com', 'password' => 'sbagliata'])->assertRedirect();
        $this->get('/en/carrello')->assertStatus(200);
    }

    public function test_switching_language_from_the_area_sets_the_cookie_and_refuses_foreign_addresses(): void
    {
        $this->activate('en');
        $user = User::factory()->role('customer')->create();

        $this->actingAs($user)->get('/lingua/en?to=/area/ordini')->assertRedirect('/area/ordini')->assertCookie('lm_lang', 'en', false);
        $this->assertSame('en', $user->fresh()->locale);

        $this->get('/lingua/en?to=//evil.example')->assertRedirect('/');
        $this->get('/lingua/nl')->assertNotFound();
    }

    public function test_the_backoffice_always_stays_in_italian(): void
    {
        $this->activate('en');
        $this->actingAs(User::factory()->role('super_admin')->create());

        $this->withUnencryptedCookie('lm_lang', 'en')->get('/admin')->assertOk();
        $this->assertSame('it', app()->getLocale());
    }

    public function test_stored_translations_replace_the_italian_text_and_fall_back_to_it(): void
    {
        $this->activate('en');
        TranslationString::create(['locale' => 'en', 'source' => 'Frase solo di prova', 'text' => 'Free shipping', 'status' => TranslationString::APPROVED]);
        TranslationString::create(['locale' => 'it', 'source' => 'Frase solo di prova', 'text' => 'Frase di prova sempre uguale', 'status' => TranslationString::APPROVED]);

        app()->setLocale('en');
        $this->assertSame('Free shipping', __('Frase solo di prova'));
        $this->assertSame('Frase mai vista', __('Frase mai vista'));

        app()->setLocale('de');
        $this->assertSame('Frase di prova sempre uguale', __('Frase solo di prova'));
    }

    public function test_unreviewed_translations_can_be_hidden(): void
    {
        TranslationString::create(['locale' => 'en', 'source' => 'Ciao', 'text' => 'Hello', 'status' => TranslationString::AUTO]);
        TranslationString::create(['locale' => 'en', 'source' => 'Grazie', 'text' => 'Thanks', 'status' => TranslationString::APPROVED]);
        app()->setLocale('en');

        $this->assertSame('Hello', __('Ciao'));

        config(['i18n.show_unreviewed' => false]);
        Locales::forgetStrings();
        app('translator')->setLoaded([]);
        $this->assertSame('Ciao', __('Ciao'));
        $this->assertSame('Thanks', __('Grazie'));
    }

    public function test_without_the_language_tables_the_site_stays_italian(): void
    {
        Schema::drop('translation_strings');
        Schema::drop('languages');
        Locales::forgetLanguages();
        Locales::forgetStrings('en');

        $this->assertSame(['it'], Locales::allCodes());
        $this->get('/domande')->assertOk();
    }

    public function test_the_reserved_consultant_area_and_personal_pages_stay_italian_but_shop_pages_follow_the_language(): void
    {
        $this->activate('de');
        $user = \App\Models\User::factory()->create();
        $cookie = [config('i18n.cookie') => 'de'];

        // area incaricati: sempre italiano, nessun selettore
        $this->actingAs($user)->withUnencryptedCookies($cookie)->get('/area/profilo');
        $this->assertSame('it', app()->getLocale());
        // pagina del negozio dentro /area: segue la lingua scelta
        $this->actingAs($user)->withUnencryptedCookies($cookie)->get('/area/ordini');
        $this->assertSame('de', app()->getLocale());
        // pagina personale
        $this->withUnencryptedCookies($cookie)->get('/i/nessuno');
        $this->assertSame('it', app()->getLocale());
    }
}
