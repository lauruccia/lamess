<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Onboarding\KycService;
use App\Domain\Onboarding\OnboardingException;
use App\Models\Consultant;
use App\Models\KycDocument;
use App\Notifications\KycReviewed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

/** Flusso di rifiuto dei documenti: motivi predefiniti, email chiara, banner nell'area, promemoria, audit. */
final class KycRejectionTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    private function consultant(): Consultant
    {
        Storage::fake();
        $this->plan();
        $s = $this->activeSponsor();

        return $this->signedContract($this->fan($s))->consultant->refresh();
    }

    private function rejected(Consultant $c, string $type = 'id_document', ?string $reason = 'unreadable', string $notes = ''): KycDocument
    {
        $kyc = app(KycService::class);
        $d = $kyc->upload($c, $type, UploadedFile::fake()->image('doc.jpg'));

        return $kyc->review($d, false, 'admin', $notes, $reason);
    }

    public function test_predefined_reason_is_stored_and_shown_with_the_admin_note(): void
    {
        Notification::fake();
        $c = $this->consultant();
        $d = $this->rejected($c, 'id_document', 'expired', 'scaduto a settembre');

        $this->assertSame('expired', $d->reject_reason);
        $this->assertSame('Documento scaduto: scaduto a settembre', $d->notes);
        Notification::assertSentTo($c->user, KycReviewed::class, fn ($n) => $n->document->is($d) && ! $n->reminder);
    }

    public function test_other_reason_requires_a_note_and_unknown_reason_is_refused(): void
    {
        $c = $this->consultant();
        $kyc = app(KycService::class);
        $d = $kyc->upload($c, 'id_document', UploadedFile::fake()->image('doc.jpg'));
        try {
            $kyc->review($d, false, 'admin', '  ', 'other');
            $this->fail('nota obbligatoria con «altro»');
        } catch (OnboardingException) {
        }
        try {
            $kyc->review($d, false, 'admin', '', 'inventato');
            $this->fail('motivo sconosciuto');
        } catch (OnboardingException) {
        }
        $this->assertSame('pending', $d->refresh()->status);
        $this->assertSame('Fototessera sfocata', $kyc->review($d, false, 'admin', 'Fototessera sfocata', 'other')->notes);
    }

    public function test_email_explains_reason_and_what_to_do(): void
    {
        $c = $this->consultant();
        $d = $this->rejected($c, 'id_document', 'incomplete');
        $mail = (new KycReviewed($d))->toMail($c->user);

        $this->assertStringContainsString('da correggere', $mail->subject);
        $text = implode(' ', array_merge($mail->introLines, [$mail->actionText]));
        $this->assertStringContainsString('Documento incompleto', $text);
        $this->assertStringContainsString('fronte e retro', $text);
        $this->assertStringContainsString('Onboarding', $text);
        $this->assertStringStartsWith('Promemoria:', (new KycReviewed($d, true))->toMail($c->user)->subject);
    }

    public function test_rejection_is_audited(): void
    {
        $c = $this->consultant();
        $this->rejected($c, 'photo', 'photo_not_compliant');

        $log = DB::table('audit_logs')->where('action', 'kyc.document_rejected')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString($c->code, $log->description);
    }

    public function test_area_home_shows_the_rejection_banner_until_the_document_is_replaced(): void
    {
        $c = $this->consultant();
        $this->rejected($c, 'id_document', 'unreadable');

        $this->actingAs($c->user)->get('/area')->assertOk()
            ->assertSee('Un documento è stato rifiutato')->assertSee('Immagine non leggibile o sfocata')->assertSee('Ricarica ora');
        $this->actingAs($c->user)->get('/area/onboarding')->assertOk()->assertSee('Fotografa il documento con buona luce');

        app(KycService::class)->upload($c, 'id_document', UploadedFile::fake()->image('nuovo.jpg'));
        $this->actingAs($c->user)->get('/area')->assertOk()->assertDontSee('Un documento è stato rifiutato');
    }

    public function test_reminders_follow_the_schedule_and_stop_once_replaced(): void
    {
        Notification::fake();
        config(['lamess.kyc_rejected_reminder_days' => [3, 7]]);
        $c = $this->consultant();
        $d = $this->rejected($c);
        $kyc = app(KycService::class);
        Notification::fake(); // azzera l'invio del rifiuto

        $this->assertSame(0, $kyc->sendRejectionReminders(), 'troppo presto');
        $d->forceFill(['reviewed_at' => now()->subDays(4)])->save();
        $this->assertSame(1, $kyc->sendRejectionReminders());
        $this->assertSame(0, $kyc->sendRejectionReminders(), 'nessun doppio invio');
        $d->forceFill(['reviewed_at' => now()->subDays(8)])->save();
        $this->assertSame(1, $kyc->sendRejectionReminders());
        $d->forceFill(['reviewed_at' => now()->subDays(30)])->save();
        $this->assertSame(0, $kyc->sendRejectionReminders(), 'finiti i promemoria');
        Notification::assertSentToTimes($c->user, KycReviewed::class, 2);
        Notification::assertSentTo($c->user, KycReviewed::class, fn ($n) => $n->reminder);
    }

    public function test_no_reminder_when_replaced_or_covered_by_an_open_request(): void
    {
        Notification::fake();
        $c = $this->consultant();
        $kyc = app(KycService::class);
        $old = $this->rejected($c, 'id_document');
        $old->forceFill(['reviewed_at' => now()->subDays(10)])->save();
        $kyc->upload($c, 'id_document', UploadedFile::fake()->image('nuovo.jpg')); // sostituito
        $photo = $this->rejected($c, 'photo');
        $photo->forceFill(['reviewed_at' => now()->subDays(10)])->save();
        $kyc->request($c, ['photo'], 'Admin'); // coperto da una richiesta con promemoria propri
        Notification::fake();

        $this->assertSame(0, $kyc->sendRejectionReminders());
        Notification::assertNothingSent();
    }

    public function test_admin_reject_action_requires_a_reason_and_saves_it(): void
    {
        Notification::fake();
        $c = $this->consultant();
        $d = app(KycService::class)->upload($c, 'id_document', UploadedFile::fake()->image('doc.jpg'));
        $this->actingAs(\App\Models\User::factory()->role()->create());

        \Livewire\Livewire::test(\App\Filament\Resources\KycDocuments\Pages\ListKycDocuments::class)
            ->callTableAction('reject', $d, data: ['reason' => null, 'notes' => ''])
            ->assertHasTableActionErrors(['reason']);
        $this->assertSame('pending', $d->refresh()->status);

        \Livewire\Livewire::test(\App\Filament\Resources\KycDocuments\Pages\ListKycDocuments::class)
            ->callTableAction('reject', $d, data: ['reason' => 'expired', 'notes' => ''])
            ->assertHasNoTableActionErrors();
        $this->assertSame('rejected', $d->refresh()->status);
        $this->assertSame('expired', $d->reject_reason);
    }
}
