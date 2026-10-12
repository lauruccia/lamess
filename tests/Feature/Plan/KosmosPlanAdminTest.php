<?php

declare(strict_types=1);

namespace Tests\Feature\Plan;

use App\Domain\Access\Roles;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Periods\PeriodService;
use App\Domain\Plan\PlanFormMapper;
use App\Domain\Plan\PlanVersionService;
use App\Filament\Resources\CompPlanVersions\Pages\EditCompPlanVersion;
use App\Filament\Resources\CompPlanVersions\Pages\ListCompPlanVersions;
use App\Models\AuditEntry;
use App\Models\CommissionLine;
use App\Models\CompPlanVersion;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Piano Incaricati: tabelle modificabili dal pannello, bozza non attivata, calcolo da database. */
final class KosmosPlanAdminTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function data(): array
    {
        return json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);
    }

    public function test_form_round_trip_of_the_new_plan_changes_nothing(): void
    {
        $data = $this->data();
        $back = PlanFormMapper::toData(PlanFormMapper::toForm($data), $data);
        $this->assertSame([], PlanVersionService::diff($data, $back));
        $this->assertSame('kosmos_v1', PlanConfig::fromArray($back)->engine());
    }

    public function test_superadmin_edits_tiers_levels_bonus_amounts_and_min_clients_with_audit(): void
    {
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $draft = CompPlanVersion::createFromArray('Incaricati', $this->data());
        $form = PlanFormMapper::toForm($draft->data);
        $form['kosmos']['direct_tiers'][1]['pct'] = '16';
        $form['kosmos']['indirect_levels'][1]['min_pearl'] = 2;
        $form['kosmos']['structure_bonus'][0]['amount'] = '70';
        $form['ranks'][1]['min_clients'] = 4;

        Livewire::test(EditCompPlanVersion::class, ['record' => $draft->getKey()])
            ->fillForm($form + ['reason' => 'Aggiornamento valori'])->call('save')->assertHasNoFormErrors();

        $data = $draft->refresh()->data;
        $this->assertSame(16, $data['kosmos']['direct_tiers'][1]['pct']);
        $this->assertSame(2, $data['kosmos']['indirect_levels'][1]['min_pearl']);
        $this->assertSame(70, $data['kosmos']['structure_bonus']['CRYSTAL']);
        $this->assertSame(4, $data['ranks'][1]['min_clients']);
        $changes = json_decode((string) AuditEntry::where('action', 'plan.updated')->latest('id')->value('changes'), true);
        $this->assertArrayHasKey('ranks.PEARL.min_clients', $changes);
        $this->assertArrayHasKey('kosmos.structure_bonus.CRYSTAL', $changes);
    }

    public function test_admin_chooses_the_client_rule_and_the_date_until_which_ranks_are_kept(): void
    {
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $draft = CompPlanVersion::createFromArray('Incaricati', $this->data());
        $this->assertSame('min_amount', PlanConfig::fromArray($draft->data)->kosmos->clientRule);
        $this->assertNull(PlanConfig::fromArray($draft->data)->kosmos->rankGraceUntil);

        $form = PlanFormMapper::toForm($draft->data);
        $form['kosmos']['client_rule'] = 'any_order';
        $form['kosmos']['rank_grace_until'] = '2026-12-31';
        Livewire::test(EditCompPlanVersion::class, ['record' => $draft->getKey()])
            ->fillForm($form + ['reason' => 'Un ordine basta'])->call('save')->assertHasNoFormErrors();

        $kosmos = PlanConfig::fromArray($draft->refresh()->data)->kosmos;
        $this->assertSame('any_order', $kosmos->clientRule);
        $this->assertSame('2026-12-31', $kosmos->rankGraceUntil);
        $this->assertTrue($kosmos->orderQualifies(BigDecimal::of('10')));
        $this->assertSame('150', (string) $kosmos->clientMinAmount->toScale(0), 'l’importo minimo resta salvato anche se non usato');

        $form = PlanFormMapper::toForm($draft->data);
        $form['kosmos']['client_rule'] = 'min_amount';
        $form['kosmos']['client_min_amount'] = '200';
        $form['kosmos']['rank_grace_until'] = '';
        Livewire::test(EditCompPlanVersion::class, ['record' => $draft->getKey()])
            ->fillForm($form + ['reason' => 'Importo minimo'])->call('save')->assertHasNoFormErrors();

        $kosmos = PlanConfig::fromArray($draft->refresh()->data)->kosmos;
        $this->assertFalse($kosmos->orderQualifies(BigDecimal::of('199.99')));
        $this->assertTrue($kosmos->orderQualifies(BigDecimal::of('200')));
        $this->assertNull($kosmos->rankGraceUntil);
    }

    public function test_admin_chooses_how_many_days_the_current_ranks_are_kept(): void
    {
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $draft = CompPlanVersion::createFromArray('Incaricati', $this->data());
        $this->assertSame(90, PlanConfig::fromArray($draft->data)->kosmos->rankGraceDays);

        $form = PlanFormMapper::toForm($draft->data);
        $this->assertSame('90', $form['kosmos']['rank_grace_days']);
        $form['kosmos']['rank_grace_days'] = '45';
        Livewire::test(EditCompPlanVersion::class, ['record' => $draft->getKey()])
            ->fillForm($form + ['reason' => 'Mantenimento 45 giorni'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(45, $draft->refresh()->data['kosmos']['rank_grace_days']);

        $form = PlanFormMapper::toForm($draft->data);
        $form['kosmos']['rank_grace_days'] = '';
        Livewire::test(EditCompPlanVersion::class, ['record' => $draft->getKey()])
            ->fillForm($form + ['reason' => 'Nessuna protezione'])->call('save')->assertHasNoFormErrors();
        $this->assertNull($draft->refresh()->data['kosmos']['rank_grace_days']);

        // la versione pubblicata passa al motore il giorno da cui il piano vale
        $draft->forceFill(['status' => CompPlanVersion::ACTIVE, 'effective_from' => '2026-11-01', 'data' => array_replace_recursive($draft->data, ['kosmos' => ['rank_grace_days' => 90]])])->save();
        $kosmos = $draft->refresh()->plan()->kosmos;
        $this->assertSame('2026-11-01', $kosmos->activationDate);
        $this->assertSame('2027-01-31', $kosmos->rankGraceEnd(), '90 giorni dal 1° novembre: novembre, dicembre e gennaio');
    }

    public function test_admin_with_plan_permission_can_edit_the_new_tables(): void
    {
        $admin = User::factory()->role('admin_compplan')->create();
        $this->actingAs($admin);
        $draft = CompPlanVersion::createFromArray('Incaricati', $this->data());
        $form = PlanFormMapper::toForm($draft->data);
        $form['kosmos']['indirect_levels'][0]['min_clients'] = 2;
        Livewire::test(EditCompPlanVersion::class, ['record' => $draft->getKey()])
            ->fillForm($form + ['reason' => 'Prova admin'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(2, $draft->refresh()->data['kosmos']['indirect_levels'][0]['min_clients']);
    }

    public function test_incaricati_page_saves_structure_text_and_keeps_fields_it_does_not_show(): void
    {
        $data = $this->data();
        $form = PlanFormMapper::toForm($data);
        $this->assertSame('SAPPHIRE:2, RUBY:2', $form['ranks'][5]['structure_text']);
        // la pagina del Piano Incaricati non mostra PR, Direct %, Rank Bonus, struttura a righe, matching e politiche
        unset($form['matching'], $form['policies']);
        foreach ($form['ranks'] as &$r) {
            unset($r['sequence'], $r['pr_required'], $r['direct_pct'], $r['rank_bonus'], $r['fifty_percent_rule'], $r['structure']);
        }
        unset($r);
        $form['ranks'][3]['structure_text'] = 'crystal x 3, RUBY:1';

        $back = PlanFormMapper::toData($form, $data);

        $this->assertSame([['rank' => 'CRYSTAL', 'count' => 3], ['rank' => 'RUBY', 'count' => 1]], $back['ranks'][3]['structure']);
        $this->assertSame($data['ranks'][3]['sequence'], $back['ranks'][3]['sequence']);
        $this->assertSame($data['matching_pct'], $back['matching_pct']);
        $this->assertSame($data['settings']['payout_day'], $back['settings']['payout_day']);
        $this->expectException(\InvalidArgumentException::class);
        $form['ranks'][3]['structure_text'] = 'boh';
        PlanFormMapper::toData($form, $data);
    }

    public function test_list_action_creates_a_draft_and_activates_nothing(): void
    {
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $active = $this->plan();
        Livewire::test(ListCompPlanVersions::class)->callAction('incaricati');
        $draft = CompPlanVersion::where('name', 'like', 'Piano Incaricati%')->firstOrFail();
        $this->assertSame(CompPlanVersion::DRAFT, $draft->status);
        $this->assertSame($active->id, CompPlanVersion::inForce()->id);
    }

    public function test_period_runs_on_the_database_with_the_new_engine(): void
    {
        $plan = CompPlanVersion::createFromArray('Incaricati', array_replace_recursive($this->data(), ['policies' => ['activity_month_rule' => 'current_or_previous']]), CompPlanVersion::ACTIVE);
        $p = $this->openPeriod('2026-10', $plan);
        $a = $this->consultant('A');
        $b = $this->consultant('B', $a);
        $this->sale($p, $b, '100');

        $run = app(PeriodService::class)->calculate($p);

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, CommissionLine::where('run_id', $run->id)->where('bonus_type', 'direct')->count());
        $this->assertSame(0, CommissionLine::where('run_id', $run->id)->whereIn('bonus_type', ['team', 'matching', 'rank', 'fsb'])->count());
    }
}
