<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Orders\OrderService;
use App\Models\CompPlanVersion;
use App\Models\Profile;
use App\Models\User;
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

    private function setUpScenario(): array
    {
        $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);
        $data['policies'] = ['activity_month_rule' => 'current_or_previous', 'kosmos_rank_maintenance' => 'none', 'kosmos_client_count' => 'period_buyers'];
        $data['kosmos'] = array_replace($data['kosmos'], ['client_rule' => 'any_order']);
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
}
