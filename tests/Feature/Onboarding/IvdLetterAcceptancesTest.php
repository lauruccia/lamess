<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Onboarding\IvdAcceptances;
use App\Domain\Onboarding\SignatureCertificate;
use App\Models\IvdContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

/** Lettera di Incarico «Definitiva» Kadeo Group: testo, dichiarazioni (art. 12), accettazioni prima della firma e prove nel PDF/certificato. */
final class IvdLetterAcceptancesTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    /** Porta un cliente fino alla schermata di firma e restituisce il codice OTP. */
    private function untilSignatureScreen(): string
    {
        Storage::fake();
        $s = $this->activeSponsor();
        $this->withUnencryptedCookie('lm_ref', $s->referral_code)->post('/registrazione', [
            'type' => 'private', 'first_name' => 'Anna', 'last_name' => 'Bianchi', 'email' => 'anna@example.test',
            'password' => 'una-password-lunga', 'password_confirmation' => 'una-password-lunga', 'privacy' => '1',
        ])->assertRedirect('/area');
        $this->post('/area/diventa-incaricato', ['tax_code' => 'BNCNNA85C41H501Z', 'birth_date' => '1985-03-01', 'birth_place' => 'Roma', 'address' => 'Via Po 2', 'city' => 'Roma', 'zip' => '00100', 'accept' => '1'])
            ->assertRedirect('/area/diventa-incaricato');
        $page = $this->get('/area/diventa-incaricato')->assertOk();
        preg_match('/note-info"><div>[^<]*?(\d{6})/', $page->getContent(), $m);
        $this->assertNotEmpty($m);

        return $m[1];
    }

    private function form(string $otp, array $over = []): array
    {
        $form = ['otp' => $otp, 'income_over_threshold' => 'no', 'other_social_security' => 'yes', 'multi_principal' => 'no'];
        foreach (IvdAcceptances::requiredKeys() as $key) {
            $form['accept_'.$key] = '1';
        }

        return $over + $form;
    }

    public function test_le_pagine_pubbliche_della_lettera_e_della_liberatoria(): void
    {
        $this->get('/lettera-di-incarico')->assertOk()->assertSee('PREMESSO')->assertSee('FORO COMPETENTE')->assertSee('www.kadeogroup.it/support')
            ->assertDontSee('kadeogrou.it')->assertDontSee('kadeogropu')->assertSee('☐');
        $this->get('/liberatoria-foto')->assertOk()->assertSee('HEREBY AUTHORIZES')->assertSee('Privacy notice');
    }

    public function test_la_schermata_di_firma_mostra_testo_dichiarazioni_e_accettazioni(): void
    {
        $this->untilSignatureScreen();
        $this->get('/area/diventa-incaricato')->assertOk()
            ->assertSee('Leggi la Lettera di Incarico')->assertSee('12.1')->assertSee('name="income_over_threshold"', false)
            ->assertSee('name="accept_letter_1341"', false)->assertSee('2.4. (Rinnovo e quota annuale)')->assertSee('3.2. (Utilizzo del sito Web')
            ->assertSee('name="accept_photo"', false)->assertSee('Facoltativo');
    }

    public function test_senza_accettazioni_o_dichiarazioni_non_si_firma(): void
    {
        $otp = $this->untilSignatureScreen();

        $this->post('/area/diventa-incaricato/firma', ['otp' => $otp])->assertSessionHasErrors(['income_over_threshold', 'other_social_security', 'multi_principal', 'accept_letter', 'accept_regolamento_1341']);
        $this->post('/area/diventa-incaricato/firma', $this->form($otp, ['accept_piano' => '0']))->assertSessionHasErrors('accept_piano');
        $this->post('/area/diventa-incaricato/firma', $this->form($otp, ['multi_principal' => 'yes']))->assertSessionHasErrors('multi_principal_names');

        $this->assertSame('awaiting_signature', IvdContract::firstOrFail()->status);
    }

    public function test_la_liberatoria_foto_e_facoltativa_e_le_prove_restano_nel_contratto(): void
    {
        $otp = $this->untilSignatureScreen();
        $this->post('/area/diventa-incaricato/firma', $this->form($otp, ['is_pensioner' => '1', 'multi_principal' => 'yes', 'multi_principal_names' => 'Acme Direct Srl']))->assertRedirect('/area');

        $c = IvdContract::firstOrFail();
        $this->assertSame('signed', $c->status);
        $this->assertSame('2026-2', $c->template_version);
        $e = $c->evidence;
        $this->assertTrue($e['acceptances']['items']['letter_1341']);
        $this->assertTrue($e['acceptances']['items']['privacy']);
        $this->assertFalse($e['acceptances']['items']['photo']);
        $this->assertSame('2.0', $e['acceptances']['documents']['regolamento']);
        $this->assertSame('yes', $e['declarations']['multi_principal']);
        $this->assertSame('Acme Direct Srl', $e['declarations']['multi_principal_names']);
        $this->assertTrue($e['declarations']['is_pensioner']);
        Storage::assertExists($c->pdf_path);
    }

    public function test_il_pdf_riporta_dichiarazioni_e_caselle_spuntate(): void
    {
        $c = $this->signedContract($this->fan());
        $accepted = array_fill_keys(IvdAcceptances::requiredKeys(), true);
        $html = (string) view('pdf.ivd-letter', [
            'contract' => $c, 'profile' => $c->user->profile, 'consultant' => $c->consultant, 'sponsor' => null, 'signedAt' => new \DateTimeImmutable,
            'feeAmount' => '19.90', 'feeEnabled' => true, 'planName' => 'Piano Incaricati',
            'decl' => ['income_over_threshold' => 'yes', 'other_social_security' => 'no', 'multi_principal' => 'no'],
            'acceptances' => IvdAcceptances::evidence($accepted, 'Piano Incaricati', new \DateTimeImmutable),
        ])->render();

        $this->assertStringContainsString('☒ avere', $html);
        $this->assertStringContainsString('☐ non avere superato', $html);
        $this->assertStringContainsString('☒ di non avere altra posizione', $html);
        $this->assertStringContainsString('Euro 19,90 (IVA compresa)', $html);
        $this->assertStringContainsString('☒</span> Dichiaro di aver letto ed accettato la LETTERA DI INCARICO IVD', $html);
        $this->assertStringContainsString('☐</span> Facoltativo', $html);
        $this->assertStringContainsString('9. (Termine decadenziale per comunicare anomalie nei documenti contabili)', $html);
        $this->assertStringContainsString('Presidente del Consiglio Direttivo', $html);
    }

    public function test_il_certificato_elenca_le_accettazioni(): void
    {
        $otp = $this->untilSignatureScreen();
        $this->post('/area/diventa-incaricato/firma', $this->form($otp, ['accept_photo' => '1']))->assertRedirect('/area');
        $c = IvdContract::firstOrFail();
        $html = (string) view('pdf.signature-certificate', ['c' => $c, 'e' => (array) $c->evidence, 'events' => $c->events, 'company' => config('lamess.company')])->render();

        $this->assertStringContainsString('Dichiarazioni e accettazioni prima della firma', $html);
        $this->assertStringContainsString('☒ Accettato', $html);
        $this->assertStringContainsString('Regolamento v2.0', $html);
        $this->assertStringContainsString('Reddito netto oltre 5.000 €: No', $html);
        $this->assertNotEmpty(app(SignatureCertificate::class)->pdf($c));
    }
}
