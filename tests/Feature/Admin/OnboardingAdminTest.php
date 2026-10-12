<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Onboarding\IvdService;
use App\Domain\Onboarding\KycService;
use App\Filament\Resources\AnnualFees\Pages\ListAnnualFees;
use App\Filament\Resources\IvdContracts\Pages\ListIvdContracts;
use App\Filament\Resources\KycDocuments\Pages\ListKycDocuments;
use App\Models\AnnualFee;
use App\Models\Consultant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

final class OnboardingAdminTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->role()->create();
    }

    public function test_pages_render(): void
    {
        $this->plan();
        $this->actingAs($this->admin());
        foreach (['/ivd-contracts', '/kyc-documents', '/annual-fees', '/customers'] as $p) {
            $this->get('/admin'.$p)->assertOk();
        }
    }

    public function test_accept_and_reject_contract_from_panel(): void
    {
        Notification::fake();
        $this->plan();
        $s = $this->activeSponsor();
        $ok = $this->signedContract($this->fan($s));
        $ko = $this->signedContract($this->fan($s), null, ['tax_code' => 'VRDLGI85M01H501Z']);
        $this->actingAs($this->admin());

        Livewire::test(ListIvdContracts::class)
            ->callTableAction('accept', $ok)->assertNotified()
            ->callTableAction('reject', $ko, ['reason' => 'documento non valido'])->assertNotified();

        $this->assertSame('accepted', $ok->refresh()->status);
        $this->assertSame('rejected', $ko->refresh()->status);
    }

    public function test_kyc_review_and_fee_payment_from_panel(): void
    {
        Notification::fake();
        $this->plan();
        $s = $this->activeSponsor();
        $c = $this->signedContract($this->fan($s));
        app(IvdService::class)->accept($c, 'admin');
        $cons = Consultant::where('user_id', $c->user_id)->firstOrFail();
        $doc = app(KycService::class)->upload($cons, 'photo', UploadedFile::fake()->image('f.jpg'));
        $this->actingAs($this->admin());

        Livewire::test(ListKycDocuments::class)
            ->callTableAction('approve', $doc)->assertNotified();
        $this->assertSame('approved', $doc->refresh()->status);

        $fee = AnnualFee::create([
            'consultant_id' => $cons->id, 'year_index' => 2, 'amount' => '19.00',
            'due_at' => now()->addDays(10), 'status' => 'due',
        ]);
        Livewire::test(ListAnnualFees::class)
            ->callTableAction('paid', $fee, ['method' => 'transfer'])->assertNotified();
        $this->assertSame('paid', $fee->refresh()->status);
    }

    public function test_admin_files_are_admin_only(): void
    {
        $this->plan();
        $c = $this->signedContract($this->fan($this->activeSponsor()));
        $this->actingAs(User::factory()->create())->get("/admin-files/contract/{$c->id}")->assertForbidden();
        $this->actingAs($this->admin())->get("/admin-files/contract/{$c->id}")->assertOk();
    }
}
