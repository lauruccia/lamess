<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Access\AccountSuspension;
use App\Domain\Career\NetworkOverview;
use App\Domain\Customers\AreaDashboard;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Network\NetworkService;
use App\Domain\Orders\OrderService;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Order;
use App\Models\Profile;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NetworkDb;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** «I miei clienti»: l'incaricato è il primo della lista e ogni cliente è colorato secondo come il piano lo conta nel mese scelto. */
final class CustomersMonthViewTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;
    use ShopDb;

    /** @param  list<array{min_clients:int,pct:int}>|null  $tiers */
    private function setUpScenario(?array $tiers = null): array
    {
        $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);
        $data['policies'] = ['activity_month_rule' => 'current_or_previous', 'kosmos_rank_maintenance' => 'none', 'kosmos_client_count' => 'period_buyers'];
        $data['kosmos'] = array_replace($data['kosmos'], ['client_rule' => 'any_order'] + ($tiers === null ? [] : ['direct_tiers' => $tiers]));
        $plan = CompPlanVersion::createFromArray('Incaricati', $data, CompPlanVersion::ACTIVE);
        $this->openPeriod(now('Europe/Rome')->format('Y-m'), $plan);
        $this->shipping();
        $sponsor = $this->consultant('S', null, null, now('Europe/Rome')->startOfMonth()->addMinutes(5)->format('Y-m-d H:i'));
        $sponsor->update(['user_id' => ($su = User::factory()->create(['name' => 'Incaricato S']))->id]);

        $buyer = $this->buyer($sponsor);
        Profile::where('user_id', $buyer->id)->update(['first_name' => 'Ordina', 'last_name' => 'Spesso']);
        $idle = $this->buyer($sponsor);
        Profile::where('user_id', $idle->id)->update(['first_name' => 'Resta', 'last_name' => 'Fermo']);
        $svc = app(OrderService::class);
        $order = $svc->place($buyer, [$this->product('P1', '200.00')->id => 1], 'IT', 'transfer', $this->billing());
        $svc->markPaid($order);

        return [$su, $buyer, $idle];
    }

    public function test_the_consultant_is_the_first_row_and_clients_are_coloured_for_the_month(): void
    {
        [$su] = $this->setUpScenario();

        $html = $this->actingAs($su)->get('/area/clienti')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Ordina Spesso'), strpos($html, 'Incaricato S'), 'l\'incaricato è il primo');
        $this->assertStringContainsString('cl-self', $html);
        $this->assertMatchesRegularExpression('/<tr\s+class="cl-ordered"/', $html);
        $this->assertStringContainsString('Ordine del mese', $html);
        $this->assertStringContainsString('Nessun ordine · vale 0', $html);
    }

    public function test_changing_the_month_changes_who_counts_and_who_expires(): void
    {
        $this->setUpScenario();
        $su = User::where('name', 'Incaricato S')->firstOrFail();
        $next = now('Europe/Rome')->startOfMonth()->addMonth()->format('Y-m');
        $after = now('Europe/Rome')->startOfMonth()->addMonths(2)->format('Y-m');
        $this->actingAs($su);

        // il mese dopo l'ordine il cliente vale ancora (mese di validità del prodotto) ma poi scade
        $this->get("/area/clienti?mese={$next}")->assertOk()->assertSee('Scade a fine mese');
        $this->get("/area/clienti?mese={$next}&stato=scadono")->assertOk()->assertSee('Ordina Spesso')->assertDontSee('Resta Fermo');
        $this->get("/area/clienti?mese={$after}&stato=zero")->assertOk()->assertSee('Ordina Spesso')->assertSee('Resta Fermo')->assertSee('Scaduto');
        $this->get('/area/clienti?stato=contano')->assertOk()->assertSee('Ordina Spesso')->assertDontSee('Resta Fermo');
    }

    /** I riquadri in alto devono dire le stesse cose della Panoramica e di «Il mio ramo». */
    public function test_the_summary_boxes_are_consistent_with_the_other_pages(): void
    {
        [$su, , $idle] = $this->setUpScenario();
        $sponsor = Consultant::where('user_id', $su->id)->firstOrFail();
        $svc = app(OrderService::class);
        $own = $svc->place($su, [$this->product('P2', '300.00')->id => 1], 'IT', 'transfer', $this->billing());
        $svc->markPaid($own);

        // «Venduto ai clienti» non comprende gli acquisti dell'incaricato, che sono indicati a parte
        $html = $this->actingAs($su)->get('/area/clienti')->assertOk()->assertSee('Clienti iscritti')->assertSee('Clienti personali')->getContent();
        $clients = Money::eur(Order::where('buyer_user_id', '!=', $su->id)->sum('total'));
        $this->assertStringContainsString('Venduto ai clienti</small><strong>€ '.$clients.'<', $html);
        $this->assertStringContainsString('i tuoi acquisti: € '.Money::eur($own->refresh()->total), $html);

        // «Il mio ramo» non ha più il riquadro sugli iscritti senza primo ordine; l'elenco dei clienti resta
        $this->get('/area/rete')->assertOk()->assertDontSee('Iscritti senza primo ordine');
        $this->get('/area/clienti?stato=senza-ordini')->assertOk()->assertSee('Resta Fermo')->assertDontSee('Ordina Spesso');

        // «La mia rete» in Panoramica conta le stesse persone di «Il mio ramo»: gli account sospesi no
        $this->consultant('A1', $sponsor);
        $b = $this->consultant('B1', $sponsor);
        $b->update(['user_id' => $idle->id]);
        $idle->forceFill(['suspended_at' => now(), 'suspension_type' => 'suspended'])->save();
        AccountSuspension::forgetCache();
        app(NetworkService::class)->rebuildClosure();
        $x = app(AreaDashboard::class)->forConsultant($sponsor->id);
        $this->assertSame(1, $x['network']);
        $this->assertSame(1, $x['direct']);
        $this->assertSame(1, app(NetworkOverview::class)->for($sponsor)['total']['persons']);
    }

    public function test_the_next_tier_is_the_first_one_that_pays_more(): void
    {
        // due scaglioni con la stessa percentuale: con 1 cliente (20%) il prossimo è quello da 6 (25%), non quello da 3 (ancora 20%)
        [$su] = $this->setUpScenario([['min_clients' => 0, 'pct' => 10], ['min_clients' => 1, 'pct' => 20], ['min_clients' => 3, 'pct' => 20], ['min_clients' => 6, 'pct' => 25]]);
        $this->artisan('lamess:periodo', ['azione' => 'live', 'codice' => now('Europe/Rome')->format('Y-m')])->assertSuccessful();

        $this->actingAs($su)->get('/area/clienti')->assertOk()->assertSee('Ne mancano 5 per il 25%')->assertDontSee('per il 20%');
    }
}
