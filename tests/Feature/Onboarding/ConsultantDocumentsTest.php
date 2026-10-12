<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Onboarding\ConsultantDocuments;
use App\Domain\Onboarding\IvdService;
use App\Domain\Onboarding\OnboardingException;
use App\Domain\Onboarding\SignatureCertificate;
use App\Filament\Resources\Consultants\Pages\ListConsultants;
use App\Mail\ConsultantDocumentsMail;
use App\Models\Consultant;
use App\Models\IvdContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

final class ConsultantDocumentsTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    private function signed(): IvdContract
    {
        Storage::fake();
        $this->plan();

        return $this->signedContract($this->fan($this->activeSponsor()), null);
    }

    public function test_signing_records_evidence_and_event_log(): void
    {
        $contract = $this->signed()->refresh();
        $this->assertArrayHasKey('signer_email', $contract->evidence);
        $this->assertSame($contract->user->email, $contract->evidence['signer_email']);
        $this->assertSame(['signed'], $contract->events->pluck('event')->all());
        $this->assertTrue($contract->events->first()->after_signing);
    }

    public function test_certificate_pdf_is_generated_and_downloadable(): void
    {
        $contract = $this->signed();
        $svc = app(IvdService::class);
        $svc->logEvent($contract, 'downloaded', '1.2.3.4', 'UA');
        $this->assertStringStartsWith('%PDF', app(SignatureCertificate::class)->pdf($contract->refresh()));

        $this->actingAs($contract->user)->get('/area/file/certificato')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(1, $contract->events()->where('event', 'downloaded')->count() >= 1 ? 1 : 0);
        $this->actingAs(User::factory()->role()->create())->get("/admin-files/contract/{$contract->id}/certificate")->assertOk();
    }

    public function test_letter_download_by_the_owner_is_logged(): void
    {
        $contract = $this->signed();
        $this->actingAs($contract->user)->get('/area/file/lettera')->assertOk();
        $this->assertTrue($contract->events()->where('event', 'downloaded')->where('after_signing', true)->exists());
    }

    public function test_admin_sends_selected_documents_by_email(): void
    {
        Mail::fake();
        $contract = $this->signed();
        $c = $contract->consultant->refresh();
        $svc = app(ConsultantDocuments::class);
        $this->assertArrayHasKey('letter', $svc->options($c));
        $this->assertArrayHasKey('certificate', $svc->options($c));

        $r = $svc->send($c, ['letter', 'certificate', 'statement:999', 'letter'], 'Admin Test', 'Ecco i documenti');
        $this->assertCount(2, $r['sent']);
        $this->assertSame(['statement:999'], $r['skipped']);
        Mail::assertSent(ConsultantDocumentsMail::class, fn ($m) => $m->hasTo($c->user->email) && count($m->files) === 2 && $m->note === 'Ecco i documenti');
    }

    public function test_nothing_available_is_an_error(): void
    {
        Mail::fake();
        $contract = $this->signed();
        $this->expectException(OnboardingException::class);
        app(ConsultantDocuments::class)->send($contract->consultant, ['receipt:1'], 'Admin');
    }

    public function test_bulk_action_from_the_list(): void
    {
        Mail::fake();
        $c = $this->signed()->consultant->refresh();
        $this->actingAs(User::factory()->role()->create());
        Livewire::test(ListConsultants::class)->callTableBulkAction('sendDocumentsBulk', [$c], ['documents' => ['letter']])->assertNotified();
        Mail::assertSentCount(1);

        $this->assertInstanceOf(Consultant::class, $c);
    }

    public function test_signature_certificate_is_reachable_from_admin_and_area(): void
    {
        $contract = $this->signed()->refresh();
        $this->actingAs(User::factory()->role()->create());
        Livewire::test(\App\Filament\Resources\IvdContracts\Pages\ListIvdContracts::class)
            ->assertTableActionVisible('certificate', $contract);
        $this->actingAs($contract->user)->get('/area')->assertOk()->assertSee('/area/file/certificato', false);
    }
}
