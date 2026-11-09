<?php

declare(strict_types=1);

namespace Tests\Feature\Pages;

use App\Domain\Access\Roles;
use App\Domain\Pages\PageException;
use App\Domain\Pages\PersonalPageService;
use App\Filament\Resources\ConsultantPages\Pages\ListConsultantPages;
use App\Models\ConsultantPage;
use App\Models\User;
use App\Notifications\PersonalPageActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Pagina personale pubblica dell'incaricato: modifica, revisione del Consorzio, visibilità e privacy. */
final class PersonalPageTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->plan();
        config(['lamess.personal_pages.enabled' => true, 'lamess.personal_pages.approval' => true, 'lamess.personal_pages.activation_required' => false]);
    }

    private function consultantUser(string $code = 'A'): User
    {
        $u = User::factory()->create(['name' => "Maria {$code}"]);
        $this->consultant($code)->update(['user_id' => $u->id, 'referral_code' => "REF{$code}"]);

        return $u->refresh();
    }

    private function form(array $over = []): array
    {
        return $over + ['display_name' => 'Maria Rossi', 'headline' => 'Incaricata Lamèss', 'bio' => 'Ti aiuto a scegliere.', 'slug' => 'maria-rossi', 'whatsapp' => '333 1234567'];
    }

    public function test_page_is_created_in_draft_with_slug_from_name_and_is_not_public(): void
    {
        $u = $this->consultantUser();
        $this->actingAs($u)->get('/area/pagina')->assertOk()->assertSee('La mia pagina')->assertSee('Metti online la pagina');
        $page = ConsultantPage::firstOrFail();
        $this->assertSame('incaricato-a', $page->slug);
        auth()->logout();
        $this->get('/i/incaricato-a')->assertNotFound();
    }

    public function test_with_approval_nothing_is_public_until_the_consortium_approves_and_edits_stay_hidden(): void
    {
        $u = $this->consultantUser();
        $this->actingAs($u)->post('/area/pagina', $this->form())->assertRedirect();
        $this->actingAs($u)->post('/area/pagina/online', ['online' => 1])->assertRedirect();
        $page = ConsultantPage::firstOrFail();
        $this->assertTrue($page->enabled);
        $this->assertSame('pending', $page->review);
        $this->get('/i/maria-rossi')->assertNotFound();           // ancora in revisione

        $admin = User::factory()->role(Roles::SUPER_ADMIN)->create();
        app(PersonalPageService::class)->approve($page);
        auth()->logout();
        $this->get('/i/maria-rossi')->assertOk()->assertSee('Maria Rossi')->assertSee('Ti aiuto a scegliere.')->assertSee('/r/REFA', false)
            ->assertSee('https://wa.me/393331234567', false)->assertSee('Registrati con me');

        // una modifica successiva resta nascosta finché non è approvata
        $this->actingAs($u)->post('/area/pagina', $this->form(['bio' => 'Testo nuovo da approvare']))->assertRedirect();
        auth()->logout();
        $this->get('/i/maria-rossi')->assertOk()->assertSee('Ti aiuto a scegliere.')->assertDontSee('Testo nuovo da approvare');
        $this->actingAs($u)->get('/area/pagina/anteprima')->assertOk()->assertSee('Testo nuovo da approvare')->assertSee('noindex', false);
        $this->assertNotNull($admin);
    }

    public function test_without_approval_edits_go_live_immediately(): void
    {
        config(['lamess.personal_pages.approval' => false]);
        $u = $this->consultantUser();
        $this->actingAs($u)->post('/area/pagina', $this->form())->assertRedirect();
        $this->actingAs($u)->post('/area/pagina/online', ['online' => 1]);
        auth()->logout();
        $this->get('/i/maria-rossi')->assertOk()->assertSee('Maria Rossi');
    }

    public function test_text_is_escaped_and_links_must_be_http(): void
    {
        config(['lamess.personal_pages.approval' => false]);
        $u = $this->consultantUser();
        $this->actingAs($u)->post('/area/pagina', $this->form(['bio' => '<script>alert(1)</script>']))->assertRedirect();
        $this->actingAs($u)->post('/area/pagina/online', ['online' => 1]);
        auth()->logout();
        $this->get('/i/maria-rossi')->assertOk()->assertDontSee('<script>alert(1)</script>', false);

        $this->actingAs($u)->post('/area/pagina', $this->form(['instagram' => 'javascript:alert(1)']))->assertSessionHasErrors('instagram');
        $this->actingAs($u)->post('/area/pagina', $this->form(['media' => [['kind' => 'video', 'title' => 'Solo titolo', 'url' => '']]]))->assertSessionHasErrors('media');
        $this->actingAs($u)->post('/area/pagina', $this->form(['media' => [['kind' => 'video', 'title' => 'Intervista', 'url' => 'https://youtu.be/abc']]]))->assertSessionHasNoErrors();
        auth()->logout();
        $this->get('/i/maria-rossi')->assertSee('Intervista')->assertSee('Guarda il video');
    }

    public function test_slug_rules_reserved_and_unique(): void
    {
        $a = $this->consultantUser('A');
        $b = $this->consultantUser('B');
        $svc = app(PersonalPageService::class);
        $pa = $svc->forConsultant($a->consultant);
        $pb = $svc->forConsultant($b->consultant);
        $svc->setSlug($pa, 'maria-rossi');
        foreach (['maria-rossi', 'admin', 'ab', 'Non valido!', 'doppio--trattino', '-inizio'] as $bad) {
            try {
                $svc->setSlug($pb, $bad);
                $this->fail("Indirizzo accettato: {$bad}");
            } catch (PageException) {
                $this->assertTrue(true);
            }
        }
        $svc->setSlug($pb, 'Maria-Bianchi');
        $this->assertSame('maria-bianchi', $pb->refresh()->slug);
    }

    public function test_photo_upload_is_validated_and_served_only_when_allowed(): void
    {
        config(['lamess.personal_pages.approval' => false]);
        $u = $this->consultantUser();
        $this->actingAs($u)->post('/area/pagina', $this->form(['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]))->assertSessionHasErrors('photo');
        $this->actingAs($u)->post('/area/pagina', $this->form(['photo' => UploadedFile::fake()->image('me.jpg', 400, 500)]))->assertSessionHasNoErrors();
        $page = ConsultantPage::firstOrFail();
        $this->assertNotNull($page->photo_path);
        Storage::assertExists($page->photo_path);
        $url = '/media/pagine/'.$page->id.'/'.basename($page->photo_path);

        auth()->logout();
        $this->get($url)->assertNotFound();                     // pagina non ancora online
        $this->actingAs($u)->get($url)->assertOk();              // il proprietario la vede
        $this->actingAs($u)->post('/area/pagina/online', ['online' => 1]);
        auth()->logout();
        $this->get($url)->assertOk();
        $this->get('/media/pagine/'.$page->id.'/altro.jpg')->assertNotFound();
    }

    public function test_shop_shows_a_way_back_to_the_personal_page_only_while_it_is_public(): void
    {
        config(['lamess.personal_pages.approval' => false]);
        $u = $this->consultantUser();
        $this->actingAs($u)->post('/area/pagina', $this->form());
        $this->actingAs($u)->post('/area/pagina/online', ['online' => 1]);
        auth()->logout();

        $this->get('/negozio')->assertDontSee('Torna alla pagina di', false);

        $this->get('/i/maria-rossi')->assertOk();
        $this->get('/negozio')->assertOk()->assertSee('Torna alla pagina di Maria Rossi', false)->assertSee('href="/i/maria-rossi"', false);

        $u->forceFill(['suspended_at' => now()])->save();
        $this->get('/negozio')->assertDontSee('Torna alla pagina di', false);
    }

    public function test_hidden_suspended_or_terminated_pages_are_not_public(): void
    {
        config(['lamess.personal_pages.approval' => false]);
        $u = $this->consultantUser();
        $this->actingAs($u)->post('/area/pagina', $this->form());
        $this->actingAs($u)->post('/area/pagina/online', ['online' => 1]);
        auth()->logout();
        $page = ConsultantPage::firstOrFail();
        $svc = app(PersonalPageService::class);
        $this->get('/i/maria-rossi')->assertOk();

        $svc->block($page, 'Testo non conforme');
        $this->get('/i/maria-rossi')->assertNotFound();
        $this->actingAs($u)->post('/area/pagina/online', ['online' => 1])->assertSessionHasErrors('online');
        $svc->unblock($page->refresh());
        $this->get('/i/maria-rossi')->assertOk();

        auth()->logout();
        $u->forceFill(['suspended_at' => now()])->save();
        $this->get('/i/maria-rossi')->assertNotFound();
        $u->forceFill(['suspended_at' => null])->save();
        $u->consultant->update(['status' => 'terminated']);
        $this->get('/i/maria-rossi')->assertNotFound();
        $this->actingAs($u->refresh())->get('/area/pagina')->assertNotFound();

        $u->consultant->update(['status' => 'active']);
        config(['lamess.personal_pages.enabled' => false]);
        $this->get('/i/maria-rossi')->assertNotFound();
    }

    public function test_only_active_consultants_have_the_area_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/area/pagina')->assertNotFound();
        auth()->logout();
        $this->get('/area/pagina')->assertRedirect();
    }

    public function test_admin_can_approve_reject_and_hide_from_the_list(): void
    {
        $u = $this->consultantUser();
        $this->actingAs($u)->post('/area/pagina', $this->form())->assertRedirect();
        $page = ConsultantPage::firstOrFail();

        $this->actingAs(User::factory()->create())->get('/admin/consultant-pages')->assertForbidden();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $this->get('/admin/consultant-pages')->assertOk();
        Livewire::test(ListConsultantPages::class)->assertCanSeeTableRecords([$page])
            ->callTableAction('reject', $page, ['note' => 'Togli la frase sul risultato'])->assertHasNoTableActionErrors();
        $this->assertSame('rejected', $page->refresh()->review);
        $this->assertSame('Togli la frase sul risultato', $page->review_note);

        $this->actingAs($u)->get('/area/pagina')->assertSee('Togli la frase sul risultato');
        $this->actingAs($u)->post('/area/pagina', $this->form())->assertRedirect();   // nuovo invio
        $this->assertSame('pending', $page->refresh()->review);

        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        Livewire::test(ListConsultantPages::class)->callTableAction('approve', $page);
        $this->assertSame('none', $page->refresh()->review);
        $this->assertNotNull($page->approved_at);
        $this->assertSame('Ti aiuto a scegliere.', $page->bio);
        Livewire::test(ListConsultantPages::class)->removeTableFilter('review')->callTableAction('block', $page, ['note' => 'Segnalazione'])->callTableAction('unblock', $page);
        $this->assertFalse($page->refresh()->blocked);
        $this->assertDatabaseHas('audit_logs', ['action' => 'page.approved']);
    }

    public function test_whatsapp_digits_get_the_italian_prefix(): void
    {
        $this->assertSame('393331234567', PersonalPageService::whatsappDigits('333 123 4567'));
        $this->assertSame('393331234567', PersonalPageService::whatsappDigits('+39 333 1234567'));
        $this->assertSame('4915112345678', PersonalPageService::whatsappDigits('0049 1511 2345678'));
        $this->assertNull(PersonalPageService::whatsappDigits('abc'));
    }

    public function test_staff_only_mode_hides_the_pages_from_consultants_and_from_the_public(): void
    {
        $u = $this->consultantUser();
        $this->actingAs($u)->post('/area/pagina', $this->form())->assertRedirect();
        $page = ConsultantPage::firstOrFail();
        $page->update(['enabled' => true, 'approved_at' => now()]);
        auth()->logout();
        $this->get('/i/maria-rossi')->assertOk();   // senza fase di prova la pagina approvata è pubblica

        config(['lamess.personal_pages.staff_only' => true]);
        $this->get('/i/maria-rossi')->assertNotFound();
        $this->actingAs($u)->get('/area/pagina')->assertNotFound();
        $this->actingAs($u)->get('/area/pagina/anteprima')->assertNotFound();
        $this->actingAs($u)->get('/area')->assertDontSee('La mia pagina');
        $this->assertTrue(app(PersonalPageService::class)->staffOnly());
    }

    public function test_admin_preview_is_only_for_the_back_office_and_shows_pending_changes(): void
    {
        $u = $this->consultantUser();
        $this->actingAs($u)->post('/area/pagina', $this->form())->assertRedirect();
        $page = ConsultantPage::firstOrFail();
        $this->assertNull($page->approved_at);
        config(['lamess.personal_pages.staff_only' => true]);

        auth()->logout();
        $this->get('/anteprima-pagina/'.$page->id)->assertRedirect();                       // non collegato
        $this->actingAs(User::factory()->create())->get('/anteprima-pagina/'.$page->id)->assertNotFound();
        $this->actingAs($u)->get('/anteprima-pagina/'.$page->id)->assertNotFound();          // nemmeno il proprietario
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create())
            ->get('/anteprima-pagina/'.$page->id)->assertOk()->assertSee('Anteprima')->assertSee('Ti aiuto a scegliere.')->assertSee('noindex', false);

        config(['lamess.personal_pages.staff_only' => false]);
        $this->get('/i/maria-rossi')->assertNotFound();                                       // la pagina non è comunque pubblica
    }

    public function test_shared_preview_link_opens_without_login_expires_and_works_while_the_site_is_closed(): void
    {
        $u = $this->consultantUser();
        $this->actingAs($u)->post('/area/pagina', $this->form(['photo' => UploadedFile::fake()->image('me.jpg', 400, 500)]))->assertRedirect();
        $page = ConsultantPage::firstOrFail();
        $url = URL::temporarySignedRoute('personal-page.shared-preview', now()->addDays(7), ['page' => $page->id]);
        auth()->logout();

        $this->get($url)->assertOk()->assertSee('Ti aiuto a scegliere.')->assertSee('noindex', false)->assertDontSee('Torna alla modifica');
        $this->get('/anteprima-pagina/'.$page->id.'/condivisa')->assertForbidden();                     // senza firma
        $this->get($url.'x')->assertForbidden();                                                         // firma manomessa

        $path = $page->proposal['photo_path'] ?? $page->photo_path;
        $file = basename((string) $path);
        $signedPhoto = URL::temporarySignedRoute('personal-page.photo', now()->addDay(), ['page' => $page->id, 'file' => $file]);
        $this->get($signedPhoto)->assertOk();
        $this->get('/media/pagine/'.$page->id.'/'.$file)->assertNotFound();                              // la foto senza firma resta privata

        config(['site.open' => false]);
        $this->get($url)->assertOk();                                                                    // anche a sito chiuso
        $this->get($signedPhoto)->assertOk();
        $this->get('/i/maria-rossi')->assertStatus(503);                                                 // il resto del sito resta chiuso

        $this->travel(8)->days();
        $this->get($url)->assertStatus(503);                                                             // scaduto a sito chiuso: pagina «apre presto»
        config(['site.open' => true]);
        $this->get($url)->assertForbidden();                                                             // scaduto a sito aperto: 403
    }

    public function test_admin_can_create_a_preview_link_from_the_list_and_it_is_audited(): void
    {
        $u = $this->consultantUser();
        $this->actingAs($u)->post('/area/pagina', $this->form())->assertRedirect();
        $page = ConsultantPage::firstOrFail();

        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        Livewire::test(ListConsultantPages::class)->removeTableFilter('review')->callTableAction('shareLink', $page)->assertHasNoTableActionErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'page.preview_link']);
    }

    // ------------------------------------------------------------ attivazione su richiesta (150 €, annuale)

    public function test_without_activation_the_area_shows_the_payment_instructions_and_blocks_editing(): void
    {
        config(['lamess.personal_pages.activation_required' => true, 'lamess.personal_pages.pay_url' => 'https://kmoney.it/prodotto/pagina-lamess']);
        $u = $this->consultantUser();
        $this->actingAs($u)->get('/area/pagina')->assertOk()->assertSee('150,00 €')->assertSee('Pagina Incaricato Lamèss')->assertSee('kmoney.it/prodotto/pagina-lamess', false)->assertSee('https://kmoney.it/register', false)->assertSee('https://kmoney.it/ricarica', false)
            ->assertSee('Ho pagato, richiedi l\'attivazione', false)->assertDontSee('Metti online la pagina');
        $this->actingAs($u)->post('/area/pagina', $this->form())->assertForbidden();
        $this->actingAs($u)->post('/area/pagina/online', ['online' => 1])->assertForbidden();
        $this->actingAs($u)->get('/area/pagina/anteprima')->assertForbidden();
    }

    public function test_request_then_payment_confirmation_activates_for_twelve_months_and_notifies(): void
    {
        Notification::fake();
        config(['lamess.personal_pages.activation_required' => true]);
        $u = $this->consultantUser();
        $this->actingAs($u)->post('/area/pagina/richiesta', ['kmoney_ref' => 'maria@example.test'])->assertRedirect();
        $page = ConsultantPage::firstOrFail();
        $this->assertNotNull($page->activation_requested_at);
        $this->assertStringContainsString('maria@example.test', (string) $page->activation_note);
        $this->actingAs($u)->post('/area/pagina/richiesta')->assertSessionHasErrors('activation');   // una sola richiesta alla volta
        $this->actingAs($u)->get('/area/pagina')->assertSee('Abbiamo ricevuto la tua richiesta');

        $svc = app(PersonalPageService::class);
        $svc->activate($page, 'paid', 'KMoney ref 123');
        $page->refresh();
        $this->assertSame('active', $page->activation);
        $this->assertSame('paid', $page->activation_source);
        $this->assertNull($page->activation_requested_at);
        $this->assertTrue($page->activation_expires_at->between(now()->addMonths(12)->subMinute(), now()->addMonths(12)->addMinute()));
        Notification::assertSentTo($u, PersonalPageActivation::class);
        $this->actingAs($u)->get('/area/pagina')->assertOk()->assertSee('Metti online la pagina');
        $this->actingAs($u)->post('/area/pagina', $this->form())->assertRedirect();
    }

    public function test_expired_activation_switches_the_public_page_off_and_renewal_extends_from_the_old_expiry(): void
    {
        Notification::fake();
        config(['lamess.personal_pages.activation_required' => true, 'lamess.personal_pages.approval' => false]);
        $u = $this->consultantUser();
        $svc = app(PersonalPageService::class);
        $page = $svc->forConsultant($u->consultant);
        $svc->activate($page, 'paid');
        $this->actingAs($u)->post('/area/pagina', $this->form())->assertRedirect();
        $this->actingAs($u)->post('/area/pagina/online', ['online' => 1])->assertRedirect();
        auth()->logout();
        $this->get('/i/maria-rossi')->assertOk();

        $page->refresh()->forceFill(['activation_expires_at' => now()->subDay()])->save();
        $this->get('/i/maria-rossi')->assertNotFound();
        $this->assertSame('expired', $svc->activationState($page->refresh()));
        $this->actingAs($u)->get('/area/pagina')->assertSee('La tua pagina è scaduta')->assertSee('Rinnova la tua pagina');

        // rinnovo prima della scadenza: i mesi si sommano alla scadenza attuale
        $page->forceFill(['activation_expires_at' => now()->addDays(10)])->save();
        $this->assertSame('expiring', $svc->activationState($page->refresh()));
        $old = $page->activation_expires_at;
        $svc->requestActivation($page);
        $svc->activate($page->refresh(), 'paid');
        $this->assertTrue($page->refresh()->activation_expires_at->between($old->addMonths(12)->subMinute(), $old->addMonths(12)->addMinute()));
    }

    public function test_free_activation_without_expiry_and_only_the_owner_sees_the_action(): void
    {
        config(['lamess.personal_pages.activation_required' => true]);
        $u = $this->consultantUser();
        $page = app(PersonalPageService::class)->forConsultant($u->consultant);
        app(PersonalPageService::class)->activate($page, 'free', null, true);
        $page->refresh();
        $this->assertSame('free', $page->activation_source);
        $this->assertNull($page->activation_expires_at);
        $this->assertTrue(app(PersonalPageService::class)->isActivated($page));
        $this->expectException(PageException::class);
        app(PersonalPageService::class)->activate($page, 'paid', null, true);   // senza scadenza solo se gratis
    }

    public function test_list_actions_confirm_payment_and_free_activation_is_owner_only(): void
    {
        Notification::fake();
        config(['lamess.personal_pages.activation_required' => true]);
        $u = $this->consultantUser();
        $page = app(PersonalPageService::class)->forConsultant($u->consultant);
        $page->forceFill(['activation_requested_at' => now()])->save();

        Roles::sync();
        $staff = User::factory()->create();
        $staff->givePermissionTo('compliance.view', 'compliance.manage');
        $this->actingAs($staff);
        Livewire::test(ListConsultantPages::class)->removeTableFilter('review')->assertTableActionHidden('freeActivate', $page)->assertTableActionHidden('confirmPayment', $page);

        $owner = User::factory()->role(Roles::SUPER_ADMIN)->create();
        $this->actingAs($owner);
        Livewire::test(ListConsultantPages::class)->removeTableFilter('review')->callTableAction('confirmPayment', $page, ['note' => 'ref 1'])->assertHasNoTableActionErrors();
        $this->assertSame('paid', $page->refresh()->activation_source);
        Livewire::test(ListConsultantPages::class)->removeTableFilter('review')->callTableAction('freeActivate', $page, ['no_expiry' => true])->assertHasNoTableActionErrors();
        $this->assertSame('free', $page->refresh()->activation_source);
        $this->assertNull($page->activation_expires_at);
    }

    public function test_expiry_reminder_is_sent_once(): void
    {
        Notification::fake();
        config(['lamess.personal_pages.activation_required' => true]);
        $u = $this->consultantUser();
        $svc = app(PersonalPageService::class);
        $page = $svc->forConsultant($u->consultant);
        $svc->activate($page, 'paid');
        $page->refresh()->forceFill(['activation_expires_at' => now()->addDays(20)])->save();
        $this->assertSame(1, $svc->sendExpiryReminders());
        $this->assertSame(0, $svc->sendExpiryReminders());
        Notification::assertSentToTimes($u, PersonalPageActivation::class, 2);   // attivazione + promemoria
    }
}
