<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Onboarding\IvdLetterSigning;
use App\Domain\Onboarding\IvdLetterTemplate;
use App\Domain\Onboarding\IvdService;
use App\Domain\Onboarding\OnboardingException;
use App\Filament\Pages\IvdLetterPage;
use App\Models\Consultant;
use App\Models\IvdContract;
use App\Models\IvdLetterEdit;
use App\Models\IvdLetterSignature;
use App\Models\IvdLetterVersion;
use App\Models\User;
use App\Notifications\IvdLetterToSign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

/** Lettera di Incarico modificabile: correzione di refuso (nessuna firma) o modifica contrattuale (da far firmare) e «Invia da firmare». */
final class IvdLetterVersionsTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    private IvdLetterTemplate $tpl;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->tpl = app(IvdLetterTemplate::class);
    }

    /** Incaricato con utente e contratto accettato. */
    private function incaricato(string $mail): Consultant
    {
        $sponsor = Consultant::first() ?? $this->activeSponsor();
        $fan = $this->fan($sponsor, ['email' => $mail]);
        $c = $this->signedContract($fan, $sponsor, ['tax_code' => strtoupper(substr(md5($mail), 0, 16))]);
        app(IvdService::class)->accept($c, 'test');

        return $c->refresh()->consultant;
    }

    private function body(): string
    {
        return $this->tpl->body();
    }

    public function test_senza_modifiche_vale_il_testo_di_base_con_la_versione_di_config(): void
    {
        $this->assertSame(config('lamess.ivd_template_version'), $this->tpl->versionLabel());
        $this->get('/lettera-di-incarico')->assertOk()->assertSee('PREMESSO')->assertSee('FORO COMPETENTE')->assertDontSee('[[')->assertSee('☐');
    }

    public function test_la_correzione_di_un_refuso_non_cambia_versione_e_non_chiede_firme(): void
    {
        $a = $this->incaricato('a@example.test');
        $before = $this->tpl->versionLabel();
        $fixed = str_replace('Tribunale di Arezzo', 'Tribunale di Arezzoo', $this->body());
        $v = $this->tpl->saveTypoFix($fixed, 'refuso nel Foro', 'admin@test');

        $this->assertSame($before, $v->version);
        $this->assertSame(1, $v->revision);
        $this->assertSame(0, IvdLetterSignature::count());
        $this->assertSame(['initial', 'typo'], IvdLetterEdit::orderBy('id')->pluck('kind')->all());
        $this->assertStringContainsString('Arezzoo', $this->tpl->render());
        $this->assertSame(0, app(IvdLetterSigning::class)->counts($v)['pending']);
        $this->assertNotNull($a);
    }

    public function test_una_correzione_che_tocca_numeri_o_troppe_parole_viene_rifiutata(): void
    {
        $this->expectException(OnboardingException::class);
        $this->expectExceptionMessage('numeri');
        $this->tpl->saveTypoFix(str_replace('5.000,00 Euro netti', '6.000,00 Euro netti', $this->body()), '', 'admin@test');
    }

    public function test_una_correzione_con_troppe_parole_viene_rifiutata(): void
    {
        $this->expectException(OnboardingException::class);
        $this->expectExceptionMessage('modifica contrattuale');
        $this->tpl->saveTypoFix(str_replace('<p><strong>8. DIVIETO DI INCASSARE</strong>', '<p>'.str_repeat('parola nuova ', 40).'</p><p><strong>8. DIVIETO DI INCASSARE</strong>', $this->body()), '', 'admin@test');
    }

    public function test_i_segnaposto_rovinati_bloccano_il_salvataggio(): void
    {
        $this->expectException(OnboardingException::class);
        $this->tpl->saveTypoFix(str_replace('[[CASELLE_REDDITO]]', '', $this->body()), '', 'admin@test');
    }

    public function test_la_modifica_contrattuale_crea_una_nuova_versione_e_i_nuovi_incaricati_firmano_quella(): void
    {
        $old = $this->tpl->versionLabel();
        $new = $this->tpl->saveContractualChange($this->body().'<p>Nuovo articolo 17: le parti si danno atto di quanto sopra.</p>', 'Aggiunto l\'art. 17', null, 'admin@test');

        $this->assertNotSame($old, $new->version);
        $this->assertSame($new->version, $this->tpl->versionLabel());
        $this->assertTrue($new->is_current);
        $this->assertFalse(IvdLetterVersion::where('version', $old)->first()->is_current);
        $this->assertStringContainsString('Nuovo articolo 17', $this->get('/lettera-di-incarico')->getContent());

        $fan = $this->fan($this->activeSponsor());
        $this->assertSame($new->version, $this->signedContract($fan)->template_version);
    }

    public function test_senza_riassunto_la_modifica_contrattuale_non_si_salva(): void
    {
        $this->expectException(OnboardingException::class);
        $this->tpl->saveContractualChange($this->body().'<p>Nuovo articolo con testo aggiunto qui.</p>', '  ', null, 'admin@test');
    }

    public function test_invia_da_firmare_raggiunge_solo_gli_incaricati_registrati_e_non_si_duplica(): void
    {
        config(['lamess.mail_enabled' => true]);
        Notification::fake();
        $a = $this->incaricato('a@example.test');
        $b = $this->incaricato('b@example.test');
        $gone = $this->incaricato('gone@example.test');
        $gone->update(['status' => 'terminated']);
        $this->fan(Consultant::first()); // cliente: non è incaricato

        $v = $this->tpl->saveContractualChange($this->body().'<p>Art. 17 nuovo: obbligo di aggiornare i propri recapiti.</p>', 'Nuovo art. 17', null, 'admin@test');
        $signing = app(IvdLetterSigning::class);
        $eligible = $signing->eligible($v)->pluck('id')->all();
        $this->assertContains($a->id, $eligible);
        $this->assertContains($b->id, $eligible);
        $this->assertNotContains($gone->id, $eligible);

        $r = $signing->send($v, 'admin@test');
        $this->assertGreaterThanOrEqual(2, $r['created']);
        $this->assertSame($r['created'], $r['emailed']);
        Notification::assertSentTo($a->user, IvdLetterToSign::class);
        $this->assertSame(0, $signing->send($v, 'admin@test')['created']);
        $this->assertDatabaseMissing('ivd_letter_signatures', ['consultant_id' => $gone->id]);
    }

    public function test_la_firma_dell_incaricato_non_tocca_il_suo_contratto(): void
    {
        $a = $this->incaricato('a@example.test');
        $contractBefore = IvdContract::where('consultant_id', $a->id)->latest('id')->first();
        $v = $this->tpl->saveContractualChange($this->body().'<p>Art. 17 nuovo: obbligo di aggiornare i propri recapiti.</p>', 'Nuovo art. 17', null, 'admin@test');
        app(IvdLetterSigning::class)->send($v, 'admin@test');

        $this->actingAs($a->user)->get('/area')->assertOk()->assertSee('Lettera di Incarico da firmare');
        $this->get('/area/lettera')->assertOk()->assertSee('Nuovo art. 17')->assertSee('obbligo di aggiornare i propri recapiti');
        $hint = $this->followingRedirects()->post('/area/lettera/codice')->assertOk()->getContent();
        preg_match('/OTP: (\d{6})/', $hint, $m);
        $this->assertNotEmpty($m);

        $form = ['otp' => $m[1], 'income_over_threshold' => 'no', 'other_social_security' => 'no', 'multi_principal' => 'no'];
        $this->post('/area/lettera/firma', $form)->assertSessionHasErrors(); // senza accettazioni non si firma
        $this->post('/area/lettera/firma', $form + ['accept_letter' => '1', 'accept_letter_1341' => '1', 'accept_regolamento' => '1', 'accept_regolamento_1341' => '1'])->assertRedirect('/area');

        $sig = IvdLetterSignature::where('consultant_id', $a->id)->firstOrFail();
        $this->assertSame('signed', $sig->status);
        Storage::assertExists($sig->pdf_path);
        $this->assertSame($v->version, $sig->evidence['version']);
        $this->get("/area/lettera/{$sig->id}/pdf")->assertOk();
        $this->get('/area/lettera')->assertRedirect('/area');
        $this->assertSame($contractBefore->id, IvdContract::where('consultant_id', $a->id)->latest('id')->first()->id);
        $this->assertSame('accepted', $contractBefore->refresh()->status);
        $this->assertSame(0, app(IvdLetterSigning::class)->counts($v)['pending']);
    }

    public function test_una_nuova_versione_annulla_le_richieste_ancora_aperte(): void
    {
        $this->incaricato('a@example.test');
        $v1 = $this->tpl->saveContractualChange($this->body().'<p>Art. 17 nuovo: obbligo di aggiornare i propri recapiti.</p>', 'Prima', null, 'admin@test');
        app(IvdLetterSigning::class)->send($v1, 'admin@test');
        $this->assertSame(1, IvdLetterSignature::where('status', 'pending')->count());
        $this->tpl->saveContractualChange($v1->body.'<p>Art. 18 nuovo: i dati vanno comunicati entro dieci giorni.</p>', 'Seconda', null, 'admin@test');
        $this->assertSame(0, IvdLetterSignature::where('status', 'pending')->count());
        $this->assertSame(1, IvdLetterSignature::where('status', 'cancelled')->count());
    }

    public function test_un_incaricato_non_puo_firmare_la_lettera_di_un_altro(): void
    {
        $a = $this->incaricato('a@example.test');
        $b = $this->incaricato('b@example.test');
        $v = $this->tpl->saveContractualChange($this->body().'<p>Art. 17 nuovo: obbligo di aggiornare i propri recapiti.</p>', 'Nuovo', null, 'admin@test');
        app(IvdLetterSigning::class)->send($v, 'admin@test');
        $sigA = IvdLetterSignature::where('consultant_id', $a->id)->firstOrFail();
        $sigA->update(['status' => 'signed', 'pdf_path' => 'contracts/x.pdf']);
        Storage::put('contracts/x.pdf', 'pdf');
        $this->actingAs($b->user)->get("/area/lettera/{$sigA->id}/pdf")->assertNotFound();
    }

    public function test_la_pagina_admin_si_apre_e_salva_correzioni_e_modifiche(): void
    {
        $this->incaricato('a@example.test');
        $this->actingAs(User::factory()->role()->create());
        $this->get('/admin/lettera-di-incarico')->assertOk()->assertSee('Invia da firmare')->assertSee('Salva correzione');
        $this->actingAs(User::factory()->create())->get('/admin/lettera-di-incarico')->assertForbidden();
    }

    public function test_dalla_pagina_admin_si_invia_da_firmare(): void
    {
        $storico = $this->incaricato('a@example.test'); // arrivato dall'import Odoo: non ha mai firmato il testo attuale
        IvdContract::where('consultant_id', $storico->id)->update(['template_version' => 'odoo-storico']);
        $gia = $this->incaricato('b@example.test'); // ha già firmato proprio questa versione sul portale
        $this->actingAs(User::factory()->role()->create());
        Livewire::test(IvdLetterPage::class)->callAction('send')->assertNotified();
        $this->assertSame([$storico->id], IvdLetterSignature::where('status', 'pending')->pluck('consultant_id')->all());
        $this->assertNotContains($gia->id, IvdLetterSignature::pluck('consultant_id')->all());
    }

    public function test_dalla_pagina_admin_si_sceglie_a_chi_inviare(): void
    {
        $a = $this->incaricato('a@example.test');
        $b = $this->incaricato('b@example.test');
        $c = $this->incaricato('c@example.test');
        $c->update(['status' => 'suspended']);
        foreach ([$a, $b, $c] as $x) {
            IvdContract::where('consultant_id', $x->id)->update(['template_version' => 'odoo-storico']);
        }
        $this->actingAs(User::factory()->role()->create());

        Livewire::test(IvdLetterPage::class)->callAction('send', ['mode' => 'pick', 'ids' => [$b->id]])->assertNotified();
        $this->assertSame([$b->id], IvdLetterSignature::pluck('consultant_id')->all());

        Livewire::test(IvdLetterPage::class)->callAction('send', ['mode' => 'active'])->assertNotified();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], IvdLetterSignature::pluck('consultant_id')->all()); // il sospeso non c'è

        Livewire::test(IvdLetterPage::class)->callAction('send', ['mode' => 'all'])->assertNotified();
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], IvdLetterSignature::pluck('consultant_id')->all());
    }

    public function test_il_regolamento_e_modificabile_e_va_accettato_prima_di_firmare(): void
    {
        $c = $this->incaricato('r@example.test');
        IvdContract::where('consultant_id', $c->id)->update(['template_version' => 'odoo-storico']);
        $reg = app(\App\Domain\Onboarding\RegolamentoDocument::class);
        $this->assertSame('2.0', $reg->version());
        $this->get('/regolamento')->assertOk()->assertSee('Incaricato');

        $this->actingAs(User::factory()->role()->create());
        Livewire::test(\App\Filament\Pages\RegolamentoPage::class)->assertOk();
        $html = $reg->html();
        $d = $reg->saveTypoFix($html.'<p>Nota di prova.</p>', 'prova', 'test');
        $this->assertSame(['2.0', 1], [$d->version, $d->revision]);
        $reg->saveNewVersion($d->body.'<p>Nuovo articolo 10 del regolamento.</p>', '2.1', 'Aggiunto articolo 10', null, 'test');
        $this->assertSame('2.1', $reg->version());
        $this->get('/regolamento')->assertSee('Nuovo articolo 10');

        $v = $this->tpl->ensureCurrent('test');
        app(IvdLetterSigning::class)->send($v, 'test', [$c->id]);
        $this->assertStringContainsString('versione 2.1', app(IvdLetterSigning::class)->items($v)['regolamento']['label']);

        $this->actingAs($c->user)->get('/area/lettera')->assertOk()->assertSee('Principi generali e Regolamento')->assertSee('Nuovo articolo 10');
        $hint = $this->followingRedirects()->post('/area/lettera/codice')->assertOk()->getContent();
        preg_match('/OTP: (\d{6})/', $hint, $m);
        $form = ['otp' => $m[1], 'income_over_threshold' => 'no', 'other_social_security' => 'no', 'multi_principal' => 'no', 'accept_letter' => '1', 'accept_letter_1341' => '1'];
        $this->post('/area/lettera/firma', $form)->assertSessionHasErrors('accept_regolamento'); // senza il Regolamento non si firma
        $this->post('/area/lettera/firma', $form + ['accept_regolamento' => '1', 'accept_regolamento_1341' => '1'])->assertRedirect('/area');

        $sig = IvdLetterSignature::firstOrFail();
        $this->assertSame('2.1', $sig->evidence['regolamento_version']);
        $this->assertSame($reg->hash(), $sig->evidence['regolamento_sha256']);
    }
}
