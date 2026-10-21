<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Domain\Periods\PeriodService;
use App\Models\Consultant;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Tasca (ex wallet): pagina con i documenti e prelievo con documento fiscale facoltativo, da aggiungere anche dopo. */
final class TascaTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private function incaricato(): array
    {
        $p = $this->openPeriod('2026-10', $this->plan(['payout_mode' => 'on_request']));
        $a = $this->consultant('A', null, 'DIAMOND');
        $b = $this->consultant('B', $a, 'CRYSTAL');
        $user = User::factory()->create();
        $b->update(['user_id' => $user->id, 'kyc_status' => 'verified', 'iban' => 'IT60X0542811101000000123456', 'iban_holder' => 'B']);
        $this->sale($p, $b, '8000');
        app(PeriodService::class)->calculate($p, 'official');
        app(PeriodService::class)->close($p);

        return [$user, $b->refresh()];
    }

    public function test_the_page_is_called_tasca_and_links_the_documents(): void
    {
        [$user] = $this->incaricato();

        $this->actingAs($user)->get('/area/wallet')->assertOk()
            ->assertSee('Tasca')->assertSee('I tuoi documenti')->assertSee('/area/statini-bonus', false)->assertSee('/area/ricevute', false);
    }

    public function test_a_withdrawal_needs_no_document_and_one_can_be_added_later(): void
    {
        Storage::fake('local');
        [$user, $b] = $this->incaricato();

        $this->actingAs($user)->post('/area/wallet/prelievo', ['amount' => '100'])->assertSessionHasNoErrors();
        $r = WithdrawalRequest::where('consultant_id', $b->id)->firstOrFail();
        $this->assertNull($r->document_path);

        $this->actingAs($user)->post("/area/wallet/prelievo/{$r->id}/documento", ['document' => UploadedFile::fake()->create('ricevuta.pdf', 50, 'application/pdf')])->assertSessionHasNoErrors();
        $r->refresh();
        $this->assertSame('ricevuta.pdf', $r->document_name);
        Storage::disk('local')->assertExists($r->document_path);
        $this->actingAs($user)->get("/area/wallet/prelievo/{$r->id}/documento")->assertOk();
    }

    public function test_a_document_can_be_attached_to_the_request_form_and_is_private(): void
    {
        Storage::fake('local');
        [$user, $b] = $this->incaricato();

        $this->actingAs($user)->post('/area/wallet/prelievo', ['amount' => '100', 'document' => UploadedFile::fake()->image('fattura.png')])->assertSessionHasNoErrors();
        $r = WithdrawalRequest::where('consultant_id', $b->id)->firstOrFail();
        $this->assertSame('fattura.png', $r->document_name);

        $this->actingAs(User::factory()->create())->get("/area/wallet/prelievo/{$r->id}/documento")->assertNotFound();
        $this->actingAs($user)->post('/area/wallet/prelievo', ['amount' => '100', 'document' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('document');
    }
}
