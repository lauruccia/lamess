<?php

declare(strict_types=1);

namespace Tests\Feature\Features;

use App\Domain\Consultants\Consultant360;
use App\Domain\Features\Feature;
use App\Domain\Network\NetworkService;
use App\Domain\Periods\PeriodService;
use App\Filament\Resources\Consultants\Pages\ListConsultants;
use App\Filament\Resources\Consultants\Pages\ViewConsultant;
use App\Filament\Resources\KycDocuments\Pages\ListKycDocuments;
use App\Filament\Resources\PvMovements\Pages\ListPvMovements;
use App\Models\Consultant;
use App\Models\ConsultantNote;
use App\Models\Customer;
use App\Models\KycDocument;
use App\Models\Period;
use App\Models\PvMovement;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LogicException;
use Tests\Support\NetworkDb;
use Tests\TestCase;

final class Consultant360Test extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private Consultant $a;

    private Consultant $b;

    private User $super;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $p = $this->openPeriod('2026-10');
        $this->a = $this->consultant('A', null, 'DIAMOND');
        $this->b = $this->consultant('B', $this->a, 'CRYSTAL');
        $this->sale($p, $this->b, '8000');
        app(PeriodService::class)->calculate($p);
        $this->b->update(['iban' => 'IT60X0542811101000000123456', 'iban_holder' => 'Incaricato B']);

        $this->super = User::factory()->role()->create();
        $this->admin = User::factory()->role('admin')->create();
        $this->admin->givePermissionTo(['network.view', 'network.manage', 'periods.view', 'orders.view', 'payouts.view', 'kyc.manage', 'features.manage']);
    }

    private function open(User $u, Consultant $c)
    {
        return $this->actingAs($u)->get('/admin/consultants/'.$c->id);
    }

    public function test_card_shows_header_counters_tabs_and_timeline(): void
    {
        $this->open($this->super, $this->b)->assertOk()
            ->assertSee('Incaricato B')->assertSee('Crystal')
            ->assertSee('Riepilogo')->assertSee('Anagrafica')->assertSee('Rete')->assertSee('Volumi e rank')
            ->assertSee('Commissioni')->assertSee('Pagamenti')->assertSee('Ordini e PV')->assertSee('Documenti')
            ->assertSee('Storico')->assertSee('Movimenti PV')->assertSee('Qualifica raggiunta');
    }

    public function test_tabs_have_real_data(): void
    {
        $c = new Consultant360($this->b);
        $vol = $c->tab('volumes');
        $this->assertSame('2026-10', $vol['tables'][0]['rows'][0][0]);
        $this->assertSame('8.000', $vol['tables'][0]['rows'][0][1], 'PVF del mese');

        $com = $c->tab('commissions');
        $this->assertNotEmpty($com['tables'][0]['rows'], 'commissioni di B (Direct)');
        $this->assertStringContainsString('€', $com['facts'][0][1]);

        $net = (new Consultant360($this->a))->tab('network');
        $this->assertSame('B', $net['tables'][1]['rows'][0][0], 'B è un diretto di A');
        $this->assertStringContainsString('A —', (new Consultant360($this->b))->tab('network')['facts'][0][1]);

        $this->assertSame(['facts' => [], 'tables' => []], $c->tab('inesistente'));
    }

    public function test_iban_is_masked_everywhere_in_the_card(): void
    {
        $c = new Consultant360($this->b);
        $this->assertSame('IT60•••••••••••••••••••3456', $c->maskIban('IT60X0542811101000000123456'));
        $this->open($this->super, $this->b)->assertOk()->assertDontSee('IT60X0542811101000000123456');
    }

    public function test_the_rank_of_one_incaricato_can_be_held_until_a_date_and_removed(): void
    {
        $this->actingAs($this->super);
        Livewire::test(ViewConsultant::class, ['record' => $this->b->id])
            ->callAction('holdRank', ['rank_hold_until' => '2026-12-31', 'reason' => 'Già registrato'])->assertNotified();
        $this->assertSame('2026-12-31', $this->b->refresh()->rank_hold_until->format('Y-m-d'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'rank.hold')->where('subject_id', (string) $this->b->id)->count());

        Livewire::test(ViewConsultant::class, ['record' => $this->b->id])->callAction('holdRank', ['rank_hold_until' => null, 'reason' => 'Fine']);
        $this->assertNull($this->b->refresh()->rank_hold_until);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'rank.hold_removed')->count());
    }

    public function test_the_rank_can_be_held_for_the_selected_incaricati_and_for_all_the_registered_ones(): void
    {
        $gone = $this->consultant('Z', $this->a, 'PEARL');
        $gone->update(['status' => 'terminated']);
        $this->actingAs($this->super);

        Livewire::test(ListConsultants::class)->callTableBulkAction('holdRankBulk', [$this->b], ['rank_hold_until' => '2026-11-30', 'reason' => 'Selezione']);
        $this->assertSame('2026-11-30', $this->b->refresh()->rank_hold_until->format('Y-m-d'));
        $this->assertNull($this->a->refresh()->rank_hold_until);

        Livewire::test(ListConsultants::class)->callAction('holdRankAll', ['rank_hold_until' => '2026-12-31', 'reason' => 'Tutti']);
        $this->assertSame('2026-12-31', $this->a->refresh()->rank_hold_until->format('Y-m-d'));
        $this->assertSame('2026-12-31', $this->b->refresh()->rank_hold_until->format('Y-m-d'));
        $this->assertNull($gone->refresh()->rank_hold_until, 'i cessati sono esclusi');
    }

    public function test_reveal_iban_is_off_by_default_and_logged_when_on(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(ViewConsultant::class, ['record' => $this->b->id])->assertActionHidden('revealIban');

        Feature::set('consultant360.action.reveal_iban', true, by: $this->super);
        $this->actingAs($this->admin);
        Livewire::test(ViewConsultant::class, ['record' => $this->b->id])->assertActionVisible('revealIban')->callAction('revealIban')->assertNotified('IBAN');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'consultant.reveal_iban')->count());
    }

    public function test_switched_off_tab_is_hidden_and_its_data_is_not_rendered(): void
    {
        $this->open($this->admin, $this->b)->assertSee('Ordini e PV');
        Feature::set('consultant360.tab.sales', false, by: $this->super);

        $this->open($this->admin, $this->b)->assertOk()->assertDontSee('Ordini e PV')->assertSee('Documenti');
        $this->open($this->super, $this->b)->assertOk()->assertSee('Ordini e PV');
    }

    public function test_audit_tab_only_for_super_admin(): void
    {
        $this->open($this->admin, $this->b)->assertOk()->assertDontSee('Ultime 50 operazioni sull’incaricato');
        $this->open($this->super, $this->b)->assertOk()->assertSee('Ultime 50 operazioni sull’incaricato');
    }

    public function test_counters_link_to_filtered_lists_and_obey_the_registry(): void
    {
        $this->actingAs($this->super);
        $counters = collect((new Consultant360($this->a))->counters($this->super))->keyBy('key');
        $this->assertSame(1, $counters['directs']['count']);
        $this->assertStringContainsString('filters', $counters['movements']['url']);
        $this->assertStringContainsString((string) $this->a->id, $counters['directs']['url']);

        Feature::set('consultant360.counter.movements', false, by: $this->super);
        $this->actingAs($this->admin);
        $keys = collect((new Consultant360($this->a))->counters($this->admin))->pluck('key');
        $this->assertNotContains('movements', $keys->all());
        $this->assertContains('settlements', $keys->all());

        Feature::set('consultant360.section.counters', false, by: $this->super);
        $this->assertSame([], (new Consultant360($this->a))->counters($this->admin));
    }

    /** Ogni pulsante-contatore apre una lista che mostra davvero tanti record quanti ne dichiara il numero (nessun filtro o ricerca residuo). */
    public function test_every_counter_opens_a_list_with_the_same_number_of_rows(): void
    {
        $this->actingAs($this->super);
        $checked = 0;
        foreach ([$this->a, $this->b] as $consultant) {
            foreach ((new Consultant360($consultant))->counters($this->super) as $k) {
                parse_str((string) parse_url($k['url'], PHP_URL_QUERY), $query);
                $resource = collect(Filament::getCurrentOrDefaultPanel()->getResources())->first(fn ($r) => str_starts_with($k['url'], $r::getUrl('index')));
                $page = $resource::getPages()['index']->getPage();
                $total = Livewire::withQueryParams($query)->test($page)->instance()->getTableRecords()->total();
                $this->assertSame($k['count'], $total, "{$consultant->code} · {$k['label']}: il numero e la lista non coincidono");
                $checked++;
            }
        }
        $this->assertGreaterThan(10, $checked);
    }

    public function test_network_tab_hides_open_list_link_when_empty(): void
    {
        $this->actingAs($this->super);
        $t = (new Consultant360($this->b))->tab('network');
        $this->assertNull(collect($t['tables'])->firstWhere('title', 'Diretti')['more']);
        $t = (new Consultant360($this->a))->tab('network');
        $this->assertNotNull(collect($t['tables'])->firstWhere('title', 'Diretti')['more']);
    }

    public function test_counter_without_permission_is_not_offered(): void
    {
        $viewer = User::factory()->role('admin')->create();
        $viewer->givePermissionTo(['network.view']); // niente periods.view / orders.view / payouts.view
        $this->actingAs($viewer);
        $keys = collect((new Consultant360($this->a))->counters($viewer))->pluck('key')->all();
        $this->assertContains('directs', $keys);
        $this->assertNotContains('movements', $keys);
        $this->assertNotContains('orders', $keys);
    }

    public function test_the_filtered_lists_really_filter(): void
    {
        $this->actingAs($this->super);
        $movB = PvMovement::where('consultant_id', $this->b->id)->firstOrFail();
        $movA = $this->sale(Period::firstOrFail(), $this->a, '100');

        Livewire::withQueryParams(['filters' => ['consultant' => ['value' => $this->b->id]]])->test(ListPvMovements::class)
            ->assertCanSeeTableRecords([$movB])->assertCanNotSeeTableRecords([$movA]);
    }

    public function test_customers_card_opens_the_list_with_only_the_clients_of_that_incaricato(): void
    {
        $this->actingAs($this->super);
        $mine = User::factory()->create(['name' => 'Cliente di Beta', 'email' => 'beta@x.test']);
        $other = User::factory()->create(['name' => 'Cliente di Alfa', 'email' => 'alfa@x.test']);
        Customer::create(['user_id' => $mine->id, 'assigned_consultant_id' => $this->b->id, 'registered_at' => now()]);
        Customer::create(['user_id' => $other->id, 'assigned_consultant_id' => $this->a->id, 'registered_at' => now()]);

        $card = collect((new Consultant360($this->b))->counters($this->super))->keyBy('key')['customers'];
        $this->assertSame(1, $card['count']);

        $this->get($card['url'])->assertOk()->assertSee('Cliente di Beta')->assertDontSee('Cliente di Alfa');
        $this->get('/admin/customers')->assertOk()->assertSee('Cliente di Beta')->assertSee('Cliente di Alfa');
        $this->get('/admin/customers')->assertSee('Assegnazione');
    }

    public function test_kyc_list_opened_from_the_card_shows_every_status(): void
    {
        $this->actingAs($this->super);
        $pending = KycDocument::create(['consultant_id' => $this->b->id, 'type' => 'identity', 'path' => 'x', 'original_name' => 'a.pdf', 'status' => 'pending']);
        $approved = KycDocument::create(['consultant_id' => $this->b->id, 'type' => 'iban', 'path' => 'y', 'original_name' => 'b.pdf', 'status' => 'approved']);

        Livewire::withQueryParams(['filters' => ['consultant' => ['value' => $this->b->id], 'status' => ['value' => '']]])->test(ListKycDocuments::class)
            ->assertCanSeeTableRecords([$pending, $approved]);
    }

    public function test_notes_are_added_pinned_first_append_only_and_audited(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(ViewConsultant::class, ['record' => $this->b->id])
            ->callAction('addNote', ['body' => 'Prima nota', 'pinned' => false])->assertNotified('Nota aggiunta');
        Livewire::test(ViewConsultant::class, ['record' => $this->b->id])
            ->callAction('addNote', ['body' => 'Da ricordare', 'pinned' => true]);

        $tl = (new Consultant360($this->b))->timeline();
        $this->assertSame('Da ricordare', $tl[0]['text'], 'la nota fissata sta in cima');
        $this->assertTrue($tl[0]['pinned']);
        $this->assertContains('Prima nota', array_column($tl, 'text'));
        $this->assertSame($this->admin->email, ConsultantNote::first()->author_label);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'consultant.note')->count());

        try {
            ConsultantNote::first()->update(['body' => 'cambiata']);
            $this->fail('una nota non si modifica');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        ConsultantNote::first()->delete();
    }

    public function test_super_admin_note_does_not_show_the_identity(): void
    {
        $this->actingAs($this->super);
        Livewire::test(ViewConsultant::class, ['record' => $this->b->id])->callAction('addNote', ['body' => 'x', 'pinned' => false]);
        $this->assertSame('Amministrazione', ConsultantNote::firstOrFail()->author_label);
    }

    public function test_notes_and_timeline_can_be_switched_off(): void
    {
        $this->actingAs($this->super);
        Livewire::test(ViewConsultant::class, ['record' => $this->b->id])->callAction('addNote', ['body' => 'Segreta', 'pinned' => false]);
        $this->open($this->admin, $this->b)->assertSee('Segreta');

        Feature::set('consultant360.section.notes', false, by: $this->super);
        $this->actingAs($this->admin);
        Livewire::test(ViewConsultant::class, ['record' => $this->b->id])->assertActionHidden('addNote');
        $this->open($this->admin, $this->b)->assertDontSee('Segreta');

        Feature::set('consultant360.section.timeline', false, by: $this->super);
        $this->open($this->admin, $this->b)->assertOk()->assertDontSee('Qualifica raggiunta');
    }

    public function test_timeline_contains_status_changes_and_ranks(): void
    {
        app(NetworkService::class)->changeStatus($this->b, 'suspended', 'Verifica');
        $texts = implode("\n", array_column((new Consultant360($this->b))->timeline(), 'text'));
        $this->assertStringContainsString('Stato: Attivo → Sospeso (Verifica)', $texts);
        $this->assertStringContainsString('Qualifica raggiunta: ', $texts);
        $this->assertStringContainsString('Incaricato', $texts);
    }

    public function test_opening_the_card_is_logged_once_per_ten_minutes(): void
    {
        $this->open($this->admin, $this->b);
        $this->open($this->admin, $this->b);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'consultant.view')->where('subject_id', (string) $this->b->id)->count());

        $this->open($this->admin, $this->a);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'consultant.view')->count());
    }

    public function test_switching_off_the_consultants_list_blocks_the_card_too(): void
    {
        Feature::set('backoffice.resource.consultants', false, by: $this->super);
        $this->open($this->admin, $this->b)->assertForbidden();
        $this->open($this->super, $this->b)->assertOk();
    }

    public function test_admin_cannot_see_the_super_admin_only_buttons(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(ViewConsultant::class, ['record' => $this->b->id])->assertActionHidden('move');
        Feature::set('consultant360.action.suspend', false, by: $this->super);
        $this->actingAs($this->admin);
        Livewire::test(ViewConsultant::class, ['record' => $this->b->id])->assertActionHidden('suspend')->assertActionVisible('terminate');
    }
}
