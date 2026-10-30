<?php

declare(strict_types=1);

namespace Tests\Feature\Pages;

use App\Domain\Access\Roles;
use App\Domain\Pages\PageException;
use App\Domain\Pages\PersonalPageService;
use App\Filament\Resources\ConsultantPages\Pages\ListConsultantPages;
use App\Models\ConsultantPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        config(['lamess.personal_pages.enabled' => true, 'lamess.personal_pages.approval' => true]);
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
}
