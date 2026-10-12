<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Domain\Access\Roles;
use App\Domain\Payouts\PayoutService;
use App\Domain\Payouts\StatementLifecycle;
use App\Domain\Periods\PeriodService;
use App\Domain\Support\SupportException;
use App\Domain\Support\TicketService;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\CommissionStatement;
use App\Models\Consultant;
use App\Models\Period;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Ticket di assistenza ed estratti conto: pubblicazione, reclamo circostanziato entro 10 giorni, accettazione tacita, decisione. */
final class TicketsAndDisputesTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    /** @return array{0: Period, 1: Consultant, 2: User, 3: Consultant, 4: User} */
    private function closedMonth(): array
    {
        $p = $this->openPeriod('2026-10', $this->plan());
        $a = $this->consultant('A', null, 'DIAMOND');
        $b = $this->consultant('B', $a, 'CRYSTAL');
        $ua = User::factory()->create();
        $ub = User::factory()->create();
        $a->update(['user_id' => $ua->id]);
        $b->update(['user_id' => $ub->id]);
        $this->sale($p, $b, '8000');
        app(PeriodService::class)->calculate($p, 'official');
        app(PeriodService::class)->close($p);

        return [$p->refresh(), $a, $ua, $b, $ub];
    }

    private const TEXT = 'La riga del Team Bonus di ottobre mi sembra calcolata su una base sbagliata.';

    public function test_closing_publishes_one_statement_per_settlement_with_a_ten_day_deadline(): void
    {
        [$p] = $this->closedMonth();

        $this->assertSame(2, CommissionStatement::where('period_id', $p->id)->count());
        $s = CommissionStatement::first();
        $this->assertSame('published', $s->status);
        $this->assertSame(10, (int) $s->published_at->setTimezone('Europe/Rome')->startOfDay()->diffInDays($s->dispute_deadline_at->setTimezone('Europe/Rome')->startOfDay()));
        $this->assertTrue($s->dispute_deadline_at->setTimezone('Europe/Rome')->isEndOfDay());
    }

    public function test_publishing_twice_does_not_restart_the_clock(): void
    {
        [$p] = $this->closedMonth();
        $first = CommissionStatement::first()->published_at;
        $this->travel(3)->days();

        $this->assertSame(0, app(StatementLifecycle::class)->publish($p));
        $this->assertTrue(CommissionStatement::first()->published_at->equalTo($first));
    }

    public function test_consultant_disputes_within_the_deadline_and_a_ticket_is_opened(): void
    {
        [$p, $a, $ua] = $this->closedMonth();
        $doc = CommissionStatement::where('consultant_id', $a->id)->firstOrFail();

        $ticket = app(StatementLifecycle::class)->dispute($ua, $doc, self::TEXT);

        $this->assertSame('statement_dispute', $ticket->category);
        $this->assertSame('disputed', $doc->refresh()->status);
        $this->assertSame($ticket->id, $doc->ticket_id);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertStringStartsWith('TK-', $ticket->number);
    }

    public function test_dispute_must_be_detailed_and_belong_to_the_consultant(): void
    {
        [, $a, $ua, $b, $ub] = $this->closedMonth();
        $doc = CommissionStatement::where('consultant_id', $a->id)->firstOrFail();
        $svc = app(StatementLifecycle::class);

        try {
            $svc->dispute($ua, $doc, 'Sbagliato');
            $this->fail('Un reclamo generico non è accettato.');
        } catch (SupportException $e) {
            $this->assertStringContainsString('circostanziato', $e->getMessage());
        }
        $this->expectException(SupportException::class);
        $svc->dispute($ub, $doc, self::TEXT);
    }

    public function test_no_dispute_after_the_deadline_and_silence_means_tacit_acceptance(): void
    {
        [, $a, $ua] = $this->closedMonth();
        $svc = app(StatementLifecycle::class);
        $this->travel(9)->days();
        $this->assertSame(0, $svc->acceptDue(), 'Entro il termine non si accetta nulla.');

        $this->travel(2)->days();
        $doc = CommissionStatement::where('consultant_id', $a->id)->firstOrFail();
        try {
            $svc->dispute($ua, $doc, self::TEXT);
            $this->fail('Dopo il termine il reclamo non è più possibile.');
        } catch (SupportException $e) {
            $this->assertStringContainsString('scaduto', $e->getMessage());
        }
        $this->assertSame(2, $svc->acceptDue());
        $this->assertSame('accepted', $doc->refresh()->status);
        $this->assertNotNull($doc->accepted_at);
    }

    public function test_a_disputed_statement_is_not_accepted_tacitly(): void
    {
        [, $a, $ua] = $this->closedMonth();
        $doc = CommissionStatement::where('consultant_id', $a->id)->firstOrFail();
        app(StatementLifecycle::class)->dispute($ua, $doc, self::TEXT);
        $this->travel(15)->days();

        app(StatementLifecycle::class)->acceptDue();

        $this->assertSame('disputed', $doc->refresh()->status);
    }

    public function test_staff_decides_the_dispute_and_the_ticket_closes(): void
    {
        [, $a, $ua] = $this->closedMonth();
        $doc = CommissionStatement::where('consultant_id', $a->id)->firstOrFail();
        $svc = app(StatementLifecycle::class);
        $svc->dispute($ua, $doc, self::TEXT);
        $staff = User::factory()->role(Roles::SUPER_ADMIN)->create();

        $doc = $svc->resolve($doc->refresh(), 'rejected', 'Il calcolo è corretto: base 8.000 PV, differenziale 9%.', $staff);

        $this->assertSame('resolved', $doc->status);
        $this->assertSame('rejected', $doc->outcome);
        $this->assertSame(Ticket::CLOSED, $doc->ticket->status);
        $this->assertStringContainsString('respinto', $doc->ticket->messages()->reorder('id', 'desc')->first()->body);
        $this->expectException(SupportException::class);
        app(TicketService::class)->reopen($doc->ticket);
    }

    public function test_reopening_a_period_removes_its_statements_and_a_historical_month_is_accepted_at_once(): void
    {
        [$p] = $this->closedMonth();
        app(PayoutService::class)->markHistorical($p);
        $this->assertSame(['accepted'], CommissionStatement::pluck('status')->unique()->values()->all());

        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $p2 = $this->openPeriod('2026-11', $this->plan());
        $this->assertNotNull($p2);
    }

    public function test_area_pages_for_tickets_are_private_to_the_owner(): void
    {
        [, , $ua, , $ub] = $this->closedMonth();
        $svc = app(TicketService::class);
        $t = $svc->open($ua, 'general', 'Come cambio l\'IBAN?', 'Vorrei aggiornare i miei dati bancari.');

        $this->actingAs($ua)->get('/area/assistenza')->assertOk()->assertSee($t->number);
        $this->actingAs($ua)->get("/area/assistenza/{$t->id}")->assertOk()->assertSee('Vorrei aggiornare');
        $this->actingAs($ub)->get("/area/assistenza/{$t->id}")->assertNotFound();
        $this->actingAs($ub)->post("/area/assistenza/{$t->id}/rispondi", ['body' => 'intruso'])->assertNotFound();
    }

    public function test_area_creates_a_ticket_and_internal_notes_stay_hidden(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->post('/area/assistenza', ['category' => 'technical', 'subject' => 'Non vedo il carrello', 'body' => 'Dopo il login la pagina è vuota.'])->assertRedirect();
        $t = Ticket::firstOrFail();
        $staff = User::factory()->role(Roles::SUPER_ADMIN)->create();
        app(TicketService::class)->reply($t, $staff, 'NOTA RISERVATA al team', true, true);
        app(TicketService::class)->reply($t, $staff, 'Prova a svuotare la cache del browser.', true);

        $this->assertSame(Ticket::ANSWERED, $t->refresh()->status);
        $this->actingAs($u)->get("/area/assistenza/{$t->id}")->assertOk()->assertSee('svuotare la cache')->assertDontSee('NOTA RISERVATA');
        $this->post('/area/assistenza', ['category' => 'statement_dispute', 'subject' => 'x', 'body' => 'y'])->assertSessionHasErrors('category');
    }

    public function test_user_cannot_close_a_statement_dispute_but_can_close_a_general_ticket(): void
    {
        [, $a, $ua] = $this->closedMonth();
        $doc = CommissionStatement::where('consultant_id', $a->id)->firstOrFail();
        $dispute = app(StatementLifecycle::class)->dispute($ua, $doc, self::TEXT);
        $general = app(TicketService::class)->open($ua, 'general', 'Info', 'Una domanda semplice.');

        $this->actingAs($ua)->post("/area/assistenza/{$dispute->id}/chiudi")->assertForbidden();
        $this->actingAs($ua)->post("/area/assistenza/{$general->id}/chiudi")->assertRedirect();
        $this->assertSame(Ticket::CLOSED, $general->refresh()->status);
    }

    public function test_area_dispute_flow_from_the_statement_page(): void
    {
        [, $a, $ua] = $this->closedMonth();

        $this->actingAs($ua)->get('/area/statini/2026-10')->assertOk()->assertSee('Contesta questo estratto');
        $this->actingAs($ua)->post('/area/statini/2026-10/reclamo', ['text' => 'corto'])->assertSessionHasErrors('text');
        $this->actingAs($ua)->post('/area/statini/2026-10/reclamo', ['text' => self::TEXT])->assertRedirect();

        $this->actingAs($ua)->get('/area/statini/2026-10')->assertOk()->assertSee('Reclamo aperto')->assertDontSee('Contesta questo estratto');
        $this->assertSame(1, Ticket::where('category', 'statement_dispute')->count());
    }

    public function test_back_office_reply_and_decision_need_the_support_permission(): void
    {
        [, $a, $ua] = $this->closedMonth();
        $doc = CommissionStatement::where('consultant_id', $a->id)->firstOrFail();
        $ticket = app(StatementLifecycle::class)->dispute($ua, $doc, self::TEXT);

        $viewer = User::factory()->role(Roles::ADMIN)->create();
        $viewer->givePermissionTo('support.view');
        Livewire::actingAs($viewer)->test(ViewTicket::class, ['record' => $ticket->getKey()])->assertActionHidden('reply')->assertActionHidden('decide');

        $agent = User::factory()->role('support')->create();
        Livewire::actingAs($agent)->test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->callAction('reply', ['body' => 'Stiamo verificando.'])
            ->callAction('decide', ['outcome' => 'upheld', 'note' => 'Hai ragione: rettifica nel mese aperto.']);

        $this->assertSame('resolved', $doc->refresh()->status);
        $this->assertSame('upheld', $doc->outcome);
        $this->assertSame(CarbonImmutable::class, get_class($doc->resolved_at));
    }

    public function test_artisan_command_accepts_overdue_statements(): void
    {
        $this->closedMonth();
        $this->travel(12)->days();

        $this->artisan('lamess:estratti')->expectsOutputToContain('2')->assertSuccessful();
    }
}
