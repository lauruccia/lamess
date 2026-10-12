<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domain\Compliance\ComplianceException;
use App\Domain\Compliance\ComplianceService;
use App\Domain\Compliance\MaterialService;
use App\Domain\Network\NetworkService;
use App\Domain\Payouts\PayoutService;
use App\Filament\Resources\ComplianceCases\Pages\ListComplianceCases;
use App\Filament\Resources\ComplianceCases\Pages\ViewComplianceCase;
use App\Filament\Resources\PromoMaterials\Pages\ListPromoMaterials;
use App\Filament\Resources\Sanctions\Pages\ListSanctions;
use App\Models\ComplianceCase;
use App\Models\Consultant;
use App\Models\PromoMaterial;
use App\Models\Sanction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Casi di conformità, sanzioni con effetti automatici e materiali approvati. */
final class ComplianceTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private const FACTS = 'Pubblica su Instagram promesse di guadagno garantito senza materiale approvato.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan();
    }

    private function svc(): ComplianceService
    {
        return app(ComplianceService::class);
    }

    private function user(Consultant $c): User
    {
        $u = User::factory()->create();
        $c->update(['user_id' => $u->id]);

        return $u;
    }

    public function test_a_case_gets_a_number_and_needs_a_real_description(): void
    {
        $c = $this->consultant('A');
        $case = $this->svc()->openCase($c, 'misleading_claims', self::FACTS);

        $this->assertSame('CC-'.str_pad((string) $case->id, 5, '0', STR_PAD_LEFT), $case->number);
        $this->assertSame('open', $case->status);
        $this->expectException(ComplianceException::class);
        $this->svc()->openCase($c, 'misleading_claims', 'troppo corto');
    }

    public function test_a_warning_is_recorded_and_closes_the_case_without_side_effects(): void
    {
        $c = $this->consultant('A');
        $case = $this->svc()->openCase($c, 'spam', self::FACTS);

        $s = $this->svc()->sanction($c, 'warning', 'Richiamo scritto per messaggi non richiesti.', $case);

        $this->assertSame('sanctioned', $case->refresh()->status);
        $this->assertNotNull($case->closed_at);
        $this->assertSame('active', $s->status);
        $this->assertFalse((bool) $c->refresh()->bonus_hold);
        $this->assertSame('active', $c->status);
    }

    public function test_commission_hold_stops_payouts_and_lifting_restores_it_unless_a_manual_hold_was_there(): void
    {
        $c = $this->consultant('A');
        $s = $this->svc()->sanction($c, 'commission_hold', 'Blocco in attesa di verifiche.');
        $this->assertTrue((bool) $c->refresh()->bonus_hold);
        $sit = app(PayoutService::class)->situation($c, BigDecimal::of('500'), BigDecimal::of('50'));
        $this->assertSame(PayoutService::BLOCKED, $sit['state']);

        $this->svc()->lift($s, 'Verifiche concluse, tutto regolare.');
        $this->assertFalse((bool) $c->refresh()->bonus_hold);
        $this->assertSame('lifted', $s->refresh()->status);

        $d = $this->consultant('B');
        $d->update(['bonus_hold' => true]);
        $s2 = $this->svc()->sanction($d, 'commission_hold', 'Secondo blocco sopra uno manuale.');
        $this->svc()->lift($s2, 'Revoca della sola sanzione.');
        $this->assertTrue((bool) $d->refresh()->bonus_hold, 'il blocco manuale preesistente resta');
    }

    public function test_two_holds_keep_the_flag_until_the_last_one_ends(): void
    {
        $c = $this->consultant('A');
        $a = $this->svc()->sanction($c, 'commission_hold', 'Primo blocco per verifiche.');
        $b = $this->svc()->sanction($c, 'commission_hold', 'Secondo blocco per altro motivo.');

        $this->svc()->lift($a, 'Primo chiuso, resta il secondo.');
        $this->assertTrue((bool) $c->refresh()->bonus_hold);
        $this->svc()->lift($b, 'Anche il secondo chiuso.');
        $this->assertFalse((bool) $c->refresh()->bonus_hold);
    }

    public function test_suspension_changes_status_and_expires_by_itself(): void
    {
        $c = $this->consultant('A');
        $s = $this->svc()->sanction($c, 'suspension', 'Sospeso per quindici giorni.', null, now()->addDays(15));
        $this->assertSame('suspended', $c->refresh()->status);

        $this->assertSame(0, $this->svc()->expireDue());
        $this->travel(16)->days();
        $this->assertSame(1, $this->svc()->expireDue());

        $this->assertSame('active', $c->refresh()->status);
        $this->assertSame('expired', $s->refresh()->status);
        $this->assertSame('sistema', $s->lifted_by);
    }

    public function test_lifting_a_suspension_does_not_reactivate_someone_who_was_meanwhile_terminated(): void
    {
        $c = $this->consultant('A');
        $s = $this->svc()->sanction($c, 'suspension', 'Sospensione senza scadenza.');
        app(NetworkService::class)->terminate($c, 'Recesso', null, 'test');

        $this->svc()->lift($s, 'Fine sospensione.');

        $this->assertSame('terminated', $c->refresh()->status);
    }

    public function test_termination_terminates_the_consultant_and_cannot_be_lifted(): void
    {
        $a = $this->consultant('A');
        $b = $this->consultant('B', $a);
        $case = $this->svc()->openCase($b, 'forbidden_practice', self::FACTS);

        $s = $this->svc()->sanction($b, 'termination', 'Violazione grave e reiterata del regolamento.', $case);

        $this->assertSame('terminated', $b->refresh()->status);
        $this->assertSame('sanctioned', $case->refresh()->status);
        $this->expectException(ComplianceException::class);
        $this->svc()->lift($s, 'Ripensamento.');
    }

    public function test_sanction_rules(): void
    {
        $a = $this->consultant('A');
        $b = $this->consultant('B');
        $caseB = $this->svc()->openCase($b, 'spam', self::FACTS);
        $cases = [
            fn () => $this->svc()->sanction($a, 'warning', 'Motivo valido qui.', $caseB),                          // caso di un altro
            fn () => $this->svc()->sanction($a, 'warning', 'ok'),                                                  // motivo troppo breve
            fn () => $this->svc()->sanction($a, 'warning', 'Richiamo con scadenza.', null, now()->addDay()),       // richiamo a tempo
            fn () => $this->svc()->sanction($a, 'suspension', 'Scadenza nel passato.', null, now()->subDay()),
            fn () => $this->svc()->sanction($a, 'inventata', 'Tipo che non esiste.'),
        ];
        foreach ($cases as $i => $fn) {
            try {
                $fn();
                $this->fail("caso {$i} doveva essere rifiutato");
            } catch (ComplianceException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->svc()->dismiss($caseB, 'Segnalazione infondata dopo verifica.');
        $this->expectException(ComplianceException::class);
        $this->svc()->sanction($b, 'warning', 'Su un caso archiviato.', $caseB);
    }

    public function test_cannot_suspend_someone_who_is_not_active(): void
    {
        $c = $this->consultant('A');
        $c->update(['status' => 'pending']);

        $this->expectException(ComplianceException::class);
        $this->svc()->sanction($c, 'suspension', 'Non è attivo, non si sospende.');
    }

    public function test_material_flow_approval_assigns_a_code_and_rejection_needs_a_reason(): void
    {
        $c = $this->consultant('A');
        $u = $this->user($c);
        $svc = app(MaterialService::class);

        $this->actingAs($u)->post('/area/conformita/materiali', ['title' => 'Post di lancio', 'claims' => 'Scopri Lamèss, il tuo percorso di benessere.'])->assertSessionHas('status');
        $m = PromoMaterial::firstOrFail();
        $this->assertSame('pending', $m->status);

        $svc->approve($m, 'Ok così', true, now()->addMonth());
        $this->assertMatchesRegularExpression('/^MA-\d{4}-0001$/', $m->refresh()->code);
        $this->actingAs($u)->get('/area/conformita')->assertOk()->assertSee($m->code)->assertSee('Post di lancio');

        $m2 = $svc->submit($c, 'Volantino', null, 'Testo del volantino');
        try {
            $svc->reject($m2, 'no');
            $this->fail('serve un motivo');
        } catch (ComplianceException) {
            $this->addToAssertionCount(1);
        }
        $svc->reject($m2, 'Contiene una promessa di guadagno.');
        $this->assertSame('rejected', $m2->refresh()->status);
        $this->expectException(ComplianceException::class);
        $svc->approve($m2);
    }

    public function test_only_shared_usable_materials_reach_the_network_and_expired_ones_disappear(): void
    {
        $c = $this->consultant('A');
        $u = $this->user($c);
        $svc = app(MaterialService::class);
        $svc->publishOfficial('Scheda ufficiale', null, 'Testo ufficiale');
        $svc->publishOfficial('Scheda scaduta', null, 'Vecchio testo', null, now()->subDay());
        $mine = $svc->submit($c, 'Mio post privato', null, 'Testo');
        $svc->approve($mine, '', false);

        $page = $this->actingAs($u)->get('/area/conformita');

        $page->assertSee('Scheda ufficiale')->assertDontSee('Scheda scaduta');
        $this->assertSame(1, PromoMaterial::usable()->where('shared', true)->count());
        $svc->withdraw($mine->refresh(), $c);
        $this->assertSame('withdrawn', $mine->refresh()->status);
    }

    public function test_a_consultant_cannot_withdraw_someone_elses_material_and_sees_own_sanctions(): void
    {
        $a = $this->consultant('A');
        $b = $this->consultant('B');
        $m = app(MaterialService::class)->submit($b, 'Di B', null, 'Testo');
        try {
            app(MaterialService::class)->withdraw($m, $a);
            $this->fail('non è suo');
        } catch (ComplianceException) {
            $this->addToAssertionCount(1);
        }
        $this->svc()->sanction($a, 'warning', 'Richiamo visibile all’incaricato.');

        $this->actingAs($this->user($a))->get('/area/conformita')->assertSee('Provvedimenti in corso')->assertSee('Richiamo visibile all’incaricato.');
        $this->actingAs(User::factory()->create())->get('/area/conformita')->assertNotFound();
    }

    public function test_backoffice_actions_and_permissions(): void
    {
        $c = $this->consultant('A');
        $this->actingAs(User::factory()->role('admin_network')->create());
        Livewire::test(ListComplianceCases::class)->callAction('open', ['consultant_id' => $c->id, 'category' => 'spam', 'description' => self::FACTS])->assertHasNoActionErrors();
        $case = ComplianceCase::firstOrFail();
        Livewire::test(ViewComplianceCase::class, ['record' => $case->id])->callAction('sanction', ['type' => 'commission_hold', 'reason' => 'Blocco in attesa di chiarimenti.'])->assertHasNoActionErrors();
        $this->assertTrue((bool) $c->refresh()->bonus_hold);
        $s = Sanction::firstOrFail();
        Livewire::test(ListSanctions::class)->callTableAction('lift', $s, ['reason' => 'Chiarimenti ricevuti.'])->assertHasNoTableActionErrors();
        $this->assertFalse((bool) $c->refresh()->bonus_hold);
        Livewire::test(ListPromoMaterials::class)->callAction('official', ['title' => 'Kit ufficiale', 'claims' => 'Testo'])->assertHasNoActionErrors();
        $this->assertSame(1, PromoMaterial::whereNotNull('code')->count());

        $this->actingAs(User::factory()->role('support')->create());
        $this->get('/admin/compliance-cases')->assertOk();
        Livewire::test(ListComplianceCases::class)->assertActionHidden('open');
        $this->actingAs(User::factory()->role('board')->create());
        $this->get('/admin/sanctions')->assertOk();
        $this->actingAs(User::factory()->role('admin_compplan')->create());
        $this->get('/admin/sanctions')->assertForbidden();
    }
}
