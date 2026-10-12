<?php

declare(strict_types=1);

namespace Tests\Feature\Succession;

use App\Domain\Compliance\ComplianceService;
use App\Domain\Onboarding\IvdService;
use App\Domain\Succession\InheritanceService;
use App\Domain\Succession\SuccessionException;
use App\Domain\Succession\TransferService;
use App\Domain\Wallet\WalletService;
use App\Filament\Resources\AccountTransfers\Pages\ListAccountTransfers;
use App\Filament\Resources\InheritanceCases\Pages\ListInheritanceCases;
use App\Filament\Resources\InheritanceCases\Pages\ViewInheritanceCase;
use App\Models\AccountTransfer;
use App\Models\Consultant;
use App\Models\Customer;
use App\Models\InheritanceCase;
use App\Models\IvdContract;
use App\Models\KycDocument;
use App\Models\Payout;
use App\Models\Settlement;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

/** Cessione del codice (Reg. 9.1) e successione agli eredi (Reg. 9.2). */
final class SuccessionTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    private Consultant $sponsor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->plan();
        $this->sponsor = $this->activeSponsor();
    }

    /** Incaricato vero: domanda firmata e accettata, con un documento KYC già approvato. */
    private function holder(string $tax = 'RSSMRA80A01H501U'): Consultant
    {
        $fan = $this->fan($this->sponsor);
        $contract = $this->signedContract($fan, $this->sponsor, ['tax_code' => $tax]);
        app(IvdService::class)->accept($contract, 'admin');
        $c = $contract->consultant->refresh();
        $c->update(['iban' => 'IT60X0542811101000000123456', 'iban_holder' => 'Mario Rossi', 'kyc_status' => 'verified', 'onboarding_complete' => true]);
        KycDocument::create(['consultant_id' => $c->id, 'type' => 'id_document', 'path' => 'kyc/x.pdf', 'original_name' => 'x.pdf', 'status' => 'approved']);

        return $c->refresh();
    }

    /** Persona registrata con anagrafica completa, non ancora incaricato. */
    private function newcomer(string $tax = 'VRDLGU85M01H501Z'): User
    {
        $fan = $this->fan($this->sponsor);
        $fan->user->profile->update($this->ivdData(['tax_code' => $tax, 'birth_date' => '1985-08-01']));

        return $fan->user->refresh();
    }

    private function openBalance(Consultant $c, string $amount): void
    {
        $p = $this->openPeriod('2026-09', null);
        Settlement::create(['period_id' => $p->id, 'consultant_id' => $c->id, 'accrued' => $amount, 'status' => 'open']);
    }

    private function transfers(): TransferService
    {
        return app(TransferService::class);
    }

    private function inheritance(): InheritanceService
    {
        return app(InheritanceService::class);
    }

    public function test_full_transfer_keeps_the_code_and_the_network_and_resets_identity_and_kyc(): void
    {
        $c = $this->holder();
        $oldUser = $c->user;
        $to = $this->newcomer();
        $code = $c->code;
        $ref = $c->referral_code;
        $count = Consultant::count();

        $t = $this->transfers()->request($c, $oldUser, strtoupper($to->email));
        $this->assertSame('50.00', (string) $t->fee_amount);
        $this->assertMatchesRegularExpression('/^CE-\d{5}$/', $t->number);
        try {
            $this->transfers()->approve($t, false);
            $this->fail('serve la fee');
        } catch (SuccessionException $e) {
            $this->assertStringContainsString('fee', $e->getMessage());
        }

        $this->transfers()->markFeePaid($t, 'transfer');
        $t = $this->transfers()->approve($t->refresh(), false, 'Documenti verificati');

        $c->refresh();
        $this->assertSame('approved', $t->status);
        $this->assertSame($count, Consultant::count(), 'nessun nuovo codice');
        $this->assertSame($code, $c->code);
        $this->assertSame($ref, $c->referral_code);
        $this->assertSame($to->id, $c->user_id);
        $this->assertSame('VRDLGU85M01H501Z', $c->person_key);
        $this->assertSame($this->sponsor->id, $c->sponsor_id);
        $this->assertSame('suspended', $c->status);
        $this->assertSame('none', $c->kyc_status);
        $this->assertNull($c->iban);
        $this->assertFalse((bool) $c->onboarding_complete);
        $this->assertSame(0, KycDocument::where('consultant_id', $c->id)->where('status', 'approved')->count());
        $this->assertSame('transferred', IvdContract::where('user_id', $oldUser->id)->first()->status);
        $this->assertInstanceOf(Customer::class, $oldUser->refresh()->customer);
        $new = IvdContract::findOrFail($t->new_contract_id);
        $this->assertSame(['awaiting_signature', 'transfer', $c->id], [$new->status, $new->kind, $new->consultant_id]);

        // il nuovo intestatario firma e il Consorzio accetta: lo stesso codice torna attivo
        $svc = app(IvdService::class);
        $req = $svc->requestSignature($new);
        $svc->sign($new, $this->otpOf((string) $req->hint), '127.0.0.1', 'phpunit');
        $this->assertSame($count, Consultant::count(), 'la firma non crea un secondo incaricato');
        $svc->accept($new->refresh(), 'admin');
        $this->assertSame('active', $c->refresh()->status);
        $this->assertSame($code, $c->code);
    }

    public function test_unpaid_balance_must_be_settled_or_explicitly_follow_the_account(): void
    {
        $c = $this->holder();
        $to = $this->newcomer();
        $this->openBalance($c, '120.00');
        $t = $this->transfers()->request($c, $c->user, $to->email);
        $this->transfers()->markFeePaid($t);

        try {
            $this->transfers()->approve($t->refresh(), false);
            $this->fail('saldo aperto');
        } catch (SuccessionException $e) {
            $this->assertStringContainsString('120.00', $e->getMessage());
        }
        $this->assertSame('paid', $t->refresh()->status);

        $t = $this->transfers()->approve($t, true);
        $this->assertSame('120.00', (string) $t->balance_at_decision);
        $this->assertTrue($t->balance_follows_account);
    }

    public function test_request_rules(): void
    {
        $c = $this->holder();
        $other = $this->holder('BNCLRA75C41H501X');
        $to = $this->newcomer();
        $cases = [
            'non è il suo codice' => fn () => $this->transfers()->request($c, $other->user, $to->email),
            'email sconosciuta' => fn () => $this->transfers()->request($c, $c->user, 'nessuno@example.test'),
            'a se stesso' => fn () => $this->transfers()->request($c, $c->user, $c->user->email),
            'verso un incaricato' => fn () => $this->transfers()->request($c, $c->user, $other->user->email),
        ];
        foreach ($cases as $why => $fn) {
            try {
                $fn();
                $this->fail("doveva rifiutare: {$why}");
            } catch (SuccessionException) {
                $this->addToAssertionCount(1);
            }
        }
        $incomplete = $this->fan($this->sponsor)->user;
        try {
            $this->transfers()->request($c, $c->user, $incomplete->email);
            $this->fail('profilo incompleto');
        } catch (SuccessionException $e) {
            $this->assertStringContainsString('dati anagrafici', $e->getMessage());
        }

        $this->transfers()->request($c, $c->user, $to->email);
        $this->expectException(SuccessionException::class);
        $this->transfers()->request($c, $c->user, $to->email); // già una richiesta aperta
    }

    public function test_a_sanctioned_code_cannot_be_transferred(): void
    {
        $c = $this->holder();
        $to = $this->newcomer();
        app(ComplianceService::class)->sanction($c, 'warning', 'Richiamo in corso di validità.');

        $this->expectException(SuccessionException::class);
        $this->transfers()->request($c, $c->user, $to->email);
    }

    public function test_requester_can_cancel_and_the_consorzio_can_refuse_without_a_reason(): void
    {
        $c = $this->holder();
        $to = $this->newcomer();
        $t = $this->transfers()->request($c, $c->user, $to->email);
        $this->transfers()->cancel($t, $c->user);
        $this->assertSame('cancelled', $t->refresh()->status);

        $t2 = $this->transfers()->request($c, $c->user, $to->email);
        $this->transfers()->reject($t2);
        $this->assertSame('rejected', $t2->refresh()->status);
        $this->assertSame($c->user_id, $c->refresh()->user_id);
        $this->expectException(SuccessionException::class);
        $this->transfers()->approve($t2, true);
    }

    public function test_death_report_ceases_the_letter_suspends_the_code_and_blocks_payments(): void
    {
        $c = $this->holder();
        $case = $this->inheritance()->report($c, now()->subDays(2), 'Comunicato dalla famiglia');

        $this->assertMatchesRegularExpression('/^SU-\d{5}$/', $case->number);
        $c->refresh();
        $this->assertSame('suspended', $c->status);
        $this->assertTrue((bool) $c->bonus_hold);
        $this->assertSame('ceased', IvdContract::where('consultant_id', $c->id)->first()->status);
        $this->expectException(SuccessionException::class);
        $this->inheritance()->report($c, now()->subDay());
    }

    private function documented(InheritanceCase $case): void
    {
        foreach (['death_certificate', 'will'] as $type) {
            Storage::put("inheritance/{$case->id}/{$type}.pdf", 'x');
            $doc = $this->inheritance()->addDocument($case, $type, "inheritance/{$case->id}/{$type}.pdf");
            $this->inheritance()->reviewDocument($doc, true);
        }
    }

    public function test_verification_needs_documents_and_heirs_and_shares_must_make_100(): void
    {
        $c = $this->holder();
        $case = $this->inheritance()->report($c, now()->subDay());
        $heirs = [
            ['full_name' => 'Anna Rossi', 'tax_code' => 'RSSNNA05A41H501X', 'share_percent' => '60', 'iban' => 'IT60X0542811101000000123456', 'iban_holder' => 'Anna Rossi'],
            ['full_name' => 'Luca Rossi', 'tax_code' => 'RSSLCU08A01H501Y', 'share_percent' => '40'],
        ];
        foreach ([[['share_percent' => '90'] + $heirs[0]], [], [['tax_code' => 'corto'] + $heirs[0]], [['iban' => 'IT00'] + $heirs[0]]] as $bad) {
            try {
                $this->inheritance()->setHeirs($case, $bad);
                $this->fail('eredi non validi');
            } catch (SuccessionException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->inheritance()->setHeirs($case, $heirs);
        try {
            $this->inheritance()->verify($case);
            $this->fail('mancano i documenti');
        } catch (SuccessionException $e) {
            $this->assertStringContainsString('certificato di morte', $e->getMessage());
        }
        $this->documented($case);
        $this->inheritance()->verify($case);
        $this->assertSame('verified', $case->refresh()->status);

        $this->inheritance()->setHeirs($case, $heirs);
        $this->assertSame('reported', $case->refresh()->status, 'cambiare gli eredi azzera la verifica');
    }

    public function test_rejected_document_does_not_count_and_needs_a_reason(): void
    {
        $c = $this->holder();
        $case = $this->inheritance()->report($c, now()->subDay());
        Storage::put('inheritance/a.pdf', 'x');
        $doc = $this->inheritance()->addDocument($case, 'death_certificate', 'inheritance/a.pdf');
        try {
            $this->inheritance()->reviewDocument($doc, false, 'no');
            $this->fail('serve un motivo');
        } catch (SuccessionException) {
            $this->addToAssertionCount(1);
        }
        $this->inheritance()->reviewDocument($doc, false, 'Copia illeggibile, da rifare.');
        $this->assertSame('rejected', $doc->refresh()->status);
    }

    private function verifiedCase(Consultant $c): InheritanceCase
    {
        $case = $this->inheritance()->report($c, now()->subDay());
        $this->documented($case);
        $this->inheritance()->setHeirs($case, [
            ['full_name' => 'Anna Rossi', 'tax_code' => 'RSSNNA05A41H501X', 'share_percent' => '60', 'iban' => 'IT60X0542811101000000123456', 'iban_holder' => 'Anna Rossi'],
            ['full_name' => 'Luca Rossi', 'tax_code' => 'RSSLCU08A01H501Y', 'share_percent' => '40', 'iban' => 'IT60X0542811101000000123456', 'iban_holder' => 'Luca Rossi'],
        ]);
        $this->inheritance()->verify($case);

        return $case->refresh();
    }

    public function test_liquidation_freezes_the_estate_and_splits_it_with_cents_to_the_largest_share(): void
    {
        $c = $this->holder();
        $this->openBalance($c, '100.01');
        $this->app->make(WalletService::class)->post($c, 'manual_adjustment', '50.00', 'Saldo wallet di prova');
        $case = $this->verifiedCase($c);

        $this->assertSame('150.01', $this->inheritance()->estate($c));
        $case = $this->inheritance()->liquidate($case);

        $this->assertSame('liquidated', $case->status);
        $this->assertSame('150.01', (string) $case->estate_amount);
        $amounts = $case->heirs()->orderBy('id')->pluck('amount')->map(fn ($a) => (string) $a)->all();
        $this->assertSame(['90.01', '60.00'], $amounts);
        $this->assertSame('150.01', number_format((float) $amounts[0] + (float) $amounts[1], 2, '.', ''));
        $this->assertSame('inherited', Settlement::where('consultant_id', $c->id)->first()->status);
        $this->assertSame('0.00', (string) $this->app->make(WalletService::class)->balance($c->id));
        $this->assertSame('0.00', $this->inheritance()->estate($c), 'non si liquida due volte');
        $this->assertSame(1, WalletTransaction::where('type', 'heir_liquidation')->count());
    }

    public function test_heirs_are_paid_and_the_case_closes_once_the_account_has_a_destiny(): void
    {
        $c = $this->holder();
        $this->openBalance($c, '200.00');
        $case = $this->inheritance()->liquidate($this->verifiedCase($c));
        $heirs = $case->heirs()->orderBy('id')->get();

        $this->inheritance()->markHeirPaid($heirs[0], 'CRO111', now());
        $this->inheritance()->markHeirPaid($heirs[1], 'CRO222', now());
        $this->assertSame('liquidated', $case->refresh()->status, 'manca ancora il destino del codice');

        $this->inheritance()->endAccount($case);
        $this->assertSame('closed', $case->refresh()->status);
        $this->assertSame('termination', $case->outcome);
        $this->assertSame('terminated', $c->refresh()->status);
        $this->expectException(SuccessionException::class);
        $this->inheritance()->markHeirPaid($heirs[0]->refresh(), 'CRO333', now());
    }

    public function test_liquidation_is_blocked_by_open_payouts_and_zero_estates_close_without_payments(): void
    {
        $c = $this->holder();
        $case = $this->verifiedCase($c);
        $p = $this->openPeriod('2026-08', null);
        Payout::create(['consultant_id' => $c->id, 'period_id' => $p->id, 'amount' => '10.00', 'status' => Payout::TO_PAY]);
        try {
            $this->inheritance()->liquidate($case);
            $this->fail('pagamento aperto');
        } catch (SuccessionException $e) {
            $this->assertStringContainsString('da pagare', $e->getMessage());
        }
        Payout::query()->delete();

        $case = $this->inheritance()->liquidate($case);
        $this->assertSame('0.00', (string) $case->estate_amount);
        $this->assertSame(0, $case->heirs()->where('status', 'to_pay')->count());
    }

    public function test_an_heir_can_take_over_the_code_with_a_new_letter(): void
    {
        $c = $this->holder();
        $heir = $this->newcomer('RSSNNA05A41H501X');
        $heir->profile->update(['birth_date' => '1990-01-01']);
        $case = $this->verifiedCase($c);

        $this->inheritance()->succeed($case, $heir->refresh());

        $c->refresh();
        $this->assertSame($heir->id, $c->user_id);
        $this->assertFalse((bool) $c->bonus_hold, 'il blocco da lutto cade con il subentro');
        $new = IvdContract::where('user_id', $heir->id)->firstOrFail();
        $this->assertSame(['succession', 'awaiting_signature'], [$new->kind, $new->status]);
        $this->assertSame('succession', $case->refresh()->outcome);
        $this->assertSame('suspended', $c->status);
    }

    public function test_a_code_left_without_a_decision_ceases_after_the_limit(): void
    {
        $c = $this->holder();
        $case = $this->inheritance()->report($c, now()->subDay());

        $this->assertSame(0, $this->inheritance()->endStale());
        $this->travel(181)->days();
        $this->assertSame(1, $this->inheritance()->endStale());

        $this->assertSame('terminated', $c->refresh()->status);
        $this->assertSame('termination', $case->refresh()->outcome);
    }

    public function test_backoffice_flow_and_permissions(): void
    {
        $c = $this->holder();
        $to = $this->newcomer();
        $this->actingAs(User::factory()->role('admin_finance')->create());
        $t = $this->transfers()->request($c, $c->user, $to->email);

        Livewire::test(ListAccountTransfers::class)->assertCanSeeTableRecords([$t])
            ->callTableAction('fee', $t, ['method' => 'transfer'])->assertHasNoTableActionErrors()
            ->assertTableActionHidden('approve', $t);
        $this->assertSame('paid', $t->refresh()->status);

        $this->actingAs(User::factory()->role('admin_network')->create());
        Livewire::test(ListAccountTransfers::class)
            ->callTableAction('approve', $t, ['follows' => false, 'note' => 'ok'])->assertHasNoTableActionErrors();
        $this->assertSame('approved', $t->refresh()->status);

        $d = $this->holder('BNCLRA75C41H501X');
        Livewire::test(ListInheritanceCases::class)
            ->callAction('report', ['consultant_id' => $d->id, 'death_date' => now()->subDay()->toDateString(), 'note' => 'telefonata'])->assertHasNoActionErrors();
        $case = InheritanceCase::firstOrFail();
        Livewire::test(ViewInheritanceCase::class, ['record' => $case->id])
            ->callAction('setHeirs', ['heirs' => [['full_name' => 'Anna Rossi', 'tax_code' => 'RSSNNA05A41H501X', 'share_percent' => 100, 'iban' => 'IT60X0542811101000000123456', 'iban_holder' => 'Anna Rossi']]])
            ->assertHasNoActionErrors()->assertActionVisible('verify');
        $this->assertSame(1, $case->heirs()->count());

        $this->actingAs(User::factory()->role('support')->create());
        $this->get('/admin/account-transfers')->assertOk();
        Livewire::test(ListAccountTransfers::class)->assertTableActionHidden('approve', $t);
        $this->actingAs(User::factory()->role('admin_compplan')->create());
        $this->get('/admin/inheritance-cases')->assertOk();
    }

    public function test_area_page_lets_the_consultant_request_and_cancel(): void
    {
        $c = $this->holder();
        $to = $this->newcomer();
        $this->actingAs($c->user)->get('/area/cessione')->assertOk()->assertSee('50,00');
        $this->actingAs($c->user)->post('/area/cessione', ['email' => $to->email])->assertSessionHasErrors('confirm');
        $this->actingAs($c->user)->post('/area/cessione', ['email' => $to->email, 'confirm' => 1])->assertSessionHas('status');
        $t = AccountTransfer::firstOrFail();
        $this->actingAs($c->user)->get('/area/cessione')->assertSee($t->number);
        $this->actingAs($c->user)->post("/area/cessione/{$t->id}/annulla")->assertSessionHas('status');
        $this->assertSame('cancelled', $t->refresh()->status);
        $this->actingAs($to)->get('/area/cessione')->assertNotFound();
    }
}
