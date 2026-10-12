<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Onboarding\KycService;
use App\Domain\Onboarding\OnboardingException;
use App\Filament\Resources\Consultants\Pages\ListConsultants;
use App\Models\Consultant;
use App\Models\KycRequest;
use App\Models\User;
use App\Notifications\KycDocumentsRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

final class KycRequestTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    private function consultant(): Consultant
    {
        $this->plan();
        $s = $this->activeSponsor();
        $contract = $this->signedContract($this->fan($s));

        return $contract->consultant->refresh();
    }

    public function test_request_notifies_and_is_listed_in_the_area(): void
    {
        Notification::fake();
        $c = $this->consultant();
        $req = app(KycService::class)->request($c, ['id_document', 'photo', 'bogus'], 'Admin Test', 'Foto più nitida, grazie');

        $this->assertSame(['id_document', 'photo'], $req->types);
        $this->assertNotNull($req->due_at);
        Notification::assertSentTo($c->user, KycDocumentsRequested::class);
        $this->actingAs($c->user)->get('/area/onboarding')->assertOk()
            ->assertSee('ti ha chiesto dei documenti')->assertSee('Foto più nitida, grazie');
    }

    public function test_rejects_empty_or_terminated(): void
    {
        $c = $this->consultant();
        $svc = app(KycService::class);
        $this->expectException(OnboardingException::class);
        $svc->request($c, ['nope'], 'Admin');
    }

    public function test_new_request_replaces_the_open_one_and_cancel_closes_it(): void
    {
        Notification::fake();
        $c = $this->consultant();
        $svc = app(KycService::class);
        $first = $svc->request($c, ['photo'], 'A');
        $second = $svc->request($c, ['id_document'], 'A');

        $this->assertNotNull($first->refresh()->canceled_at);
        $this->assertSame($second->id, $svc->openRequest($c)?->id);
        $svc->cancelRequest($second);
        $this->assertNull($svc->openRequest($c));
    }

    public function test_upload_of_all_requested_documents_fulfils_the_request(): void
    {
        Notification::fake();
        $c = $this->consultant();
        $svc = app(KycService::class);
        $req = $svc->request($c, ['photo', 'id_document'], 'A');

        $svc->upload($c, 'photo', UploadedFile::fake()->image('f.jpg'));
        $this->assertNull($req->refresh()->fulfilled_at, 'ne manca ancora uno');
        $svc->upload($c, 'id_document', UploadedFile::fake()->create('id.pdf', 20, 'application/pdf'));
        $this->assertNotNull($req->refresh()->fulfilled_at);
        $this->assertNull($svc->openRequest($c));
    }

    public function test_reminders_follow_the_configured_days_and_stop_when_done(): void
    {
        Notification::fake();
        config(['lamess.kyc_request_reminder_days' => [3, 6]]);
        $c = $this->consultant();
        $svc = app(KycService::class);
        $req = $svc->request($c, ['photo'], 'A');
        Notification::fake(); // azzera l'invio iniziale

        $this->assertSame(0, $svc->sendReminders());
        $req->forceFill(['created_at' => now()->subDays(4)])->save();
        $this->assertSame(1, $svc->sendReminders());
        $this->assertSame(0, $svc->sendReminders(), 'nessun doppio invio');
        $req->forceFill(['created_at' => now()->subDays(7)])->save();
        $this->assertSame(1, $svc->sendReminders());
        $req->forceFill(['created_at' => now()->subDays(20)])->save();
        $this->assertSame(0, $svc->sendReminders(), 'finiti i promemoria');
        Notification::assertSentToTimes($c->user, KycDocumentsRequested::class, 2);
    }

    public function test_admin_can_request_from_the_list_and_in_bulk(): void
    {
        Notification::fake();
        $c = $this->consultant();
        $this->actingAs(User::factory()->role()->create());

        Livewire::test(ListConsultants::class)
            ->callTableAction('requestKyc', $c, ['types' => ['photo'], 'message' => 'Ci serve la foto', 'due_days' => 5])->assertNotified();
        $this->assertSame('Ci serve la foto', KycRequest::firstOrFail()->message);

        Livewire::test(ListConsultants::class)
            ->callTableBulkAction('requestKycBulk', [$c], ['types' => ['id_document'], 'due_days' => 0])->assertNotified();
        $this->assertSame(['id_document'], app(KycService::class)->openRequest($c)->types);
        $this->assertNull(app(KycService::class)->openRequest($c)->due_at);
    }

    public function test_read_only_role_cannot_request(): void
    {
        $c = $this->consultant();
        $this->actingAs(User::factory()->role('board')->create());
        Livewire::test(ListConsultants::class)->assertTableActionHidden('requestKyc', $c)->assertTableBulkActionHidden('requestKycBulk');
    }
}
