<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Career\RankPalette;
use App\Domain\Network\NetworkService;
use App\Models\Consultant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NetworkDb;
use Tests\TestCase;

final class AreaPagesTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private function withUser(Consultant $c): User
    {
        $u = User::factory()->create(['name' => $c->name]);
        $c->update(['user_id' => $u->id]);

        return $u;
    }

    public function test_branch_shows_only_the_own_downline(): void
    {
        $this->plan();
        $this->openPeriod('2026-10');
        $a = $this->consultant('A');
        $b = $this->consultant('B', $a);
        $c = $this->consultant('C', $b);
        $x = $this->consultant('X');
        app(NetworkService::class)->rebuildClosure();
        $this->actingAs($this->withUser($a));

        $this->get('/area/rete')->assertOk()->assertSee('Incaricato B')->assertSee('Incaricato C')->assertDontSee('Incaricato X')->assertDontSee('Incaricato A</span>', false);
        $this->actingAs($this->withUser($x))->get('/area/rete')->assertOk()->assertSee('Il tuo ramo è ancora vuoto');
    }

    public function test_branch_tree_shows_the_stone_colour_of_each_rank(): void
    {
        $this->plan();
        $this->openPeriod('2026-10');
        $a = $this->consultant('A');
        $this->consultant('B', $a);
        app(NetworkService::class)->rebuildClosure();

        $html = $this->actingAs($this->withUser($a))->get('/area/rete')->assertOk()->getContent();
        $this->assertStringContainsString('class="rank-legend"', $html);
        $this->assertStringContainsString('--stone: #', $html);
        $this->assertSame('#0F52BA', RankPalette::color('sapphire'));
        $this->assertSame('Blue diamond', RankPalette::label('BLUE_DIAMOND'));
    }

    public function test_branch_tree_data_has_the_whole_downline_rooted_in_me_and_no_points(): void
    {
        $this->plan();
        $this->openPeriod('2026-10');
        $a = $this->consultant('A');
        $b = $this->consultant('B', $a);
        $c = $this->consultant('C', $b);
        $x = $this->consultant('X');
        app(NetworkService::class)->rebuildClosure();

        $html = $this->actingAs($this->withUser($a))->get('/area/rete')->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<script type="application/json" id="lt-data">(.*?)</script>#s', $html, $m));
        $tree = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($a->id, $tree['root']);
        $this->assertSame([$a->id, $b->id, $c->id], array_column($tree['nodes'], 'id'));
        $this->assertNull($tree['nodes'][0]['parent']);
        $this->assertSame($a->id, $tree['nodes'][1]['parent']);
        $this->assertSame($b->id, $tree['nodes'][2]['parent']);
        $this->assertNotContains($x->id, array_column($tree['nodes'], 'id'));
        foreach (['pr', 'pvf', 'pv', 'hide_pr'] as $oldKey) {
            $this->assertArrayNotHasKey($oldKey, $tree['nodes'][1]);
        }
    }

    public function test_branch_detail_is_only_for_me_and_my_downline(): void
    {
        $this->plan();
        $this->openPeriod('2026-10');
        $a = $this->consultant('A');
        $b = $this->consultant('B', $a);
        $c = $this->consultant('C', $b);
        $x = $this->consultant('X');
        app(NetworkService::class)->rebuildClosure();
        $this->actingAs($this->withUser($a));

        $this->getJson('/area/rete/'.$b->id.'/dettaglio')->assertOk()->assertJsonStructure(['known']);
        $this->getJson('/area/rete/'.$c->id.'/dettaglio')->assertOk()->assertJsonStructure(['known']);
        $this->getJson('/area/rete/'.$a->id.'/dettaglio')->assertOk()->assertJsonStructure(['known']);
        $this->getJson('/area/rete/'.$x->id.'/dettaglio')->assertNotFound();
        $this->actingAs($this->withUser($b))->getJson('/area/rete/'.$a->id.'/dettaglio')->assertNotFound();
    }

    public function test_branch_and_customers_are_for_consultants_only(): void
    {
        $fan = User::factory()->create();
        $this->actingAs($fan);
        $this->get('/area/rete')->assertNotFound();
        $this->get('/area/clienti')->assertNotFound();
    }

    public function test_customers_list_shows_only_assigned_customers(): void
    {
        $a = $this->consultant('A');
        $b = $this->consultant('B');
        foreach ([[$a, 'Mia Cliente'], [$b, 'Altrui Cliente']] as [$owner, $name]) {
            $u = User::factory()->create(['name' => $name]);
            Profile::create(['user_id' => $u->id, 'type' => 'private', 'first_name' => $name, 'last_name' => 'Test']);
            Customer::create(['user_id' => $u->id, 'assigned_consultant_id' => $owner->id, 'source' => 'referral', 'registered_at' => now()]);
        }
        $this->actingAs($this->withUser($a))->get('/area/clienti')->assertOk()->assertSee('Mia Cliente')->assertDontSee('Altrui Cliente');
    }

    public function test_customers_list_can_be_searched_and_filtered(): void
    {
        $a = $this->consultant('A');
        $with = User::factory()->create(['name' => 'Ordina Spesso', 'email' => 'ordina@example.test']);
        $without = User::factory()->create(['name' => 'Mai Ordinato', 'email' => 'mai@example.test']);
        foreach ([$with, $without] as $u) {
            Profile::create(['user_id' => $u->id, 'type' => 'private', 'first_name' => explode(' ', $u->name)[0], 'last_name' => explode(' ', $u->name)[1]]);
            Customer::create(['user_id' => $u->id, 'assigned_consultant_id' => $a->id, 'source' => 'referral', 'registered_at' => now()]);
        }
        Order::create([
            'number' => 'ORD-FILTER-1', 'buyer_user_id' => $with->id, 'buyer_type' => 'customer', 'status' => 'paid', 'payment_method' => 'card',
            'subtotal_net' => '163.93', 'vat_total' => '36.07', 'total' => '200.00', 'paid_at' => now(), 'billing' => [],
        ]);
        $this->actingAs($this->withUser($a));

        $this->get('/area/clienti')->assertOk()->assertSee('Ordina Spesso')->assertSee('Mai Ordinato');
        $this->get('/area/clienti?stato=senza-ordini')->assertOk()->assertSee('Mai Ordinato')->assertDontSee('Ordina Spesso');
        $this->get('/area/clienti?stato=mese')->assertOk()->assertSee('Ordina Spesso')->assertDontSee('Mai Ordinato');
        $this->get('/area/clienti?q=mai%40')->assertOk()->assertSee('Mai Ordinato')->assertDontSee('Ordina Spesso');
        $this->get('/area/clienti?stato=inesistente')->assertOk()->assertSee('Ordina Spesso')->assertSee('Mai Ordinato');
    }

    public function test_customers_list_is_paginated_keeping_the_filters(): void
    {
        $a = $this->consultant('A');
        foreach (range(1, 55) as $i) {
            $u = User::factory()->create(['name' => sprintf('Cliente%02d Prova', $i), 'email' => sprintf('c%02d@example.test', $i)]);
            Profile::create(['user_id' => $u->id, 'type' => 'private', 'first_name' => sprintf('Cliente%02d', $i), 'last_name' => 'Prova']);
            Customer::create(['user_id' => $u->id, 'assigned_consultant_id' => $a->id, 'source' => 'referral', 'registered_at' => now()->subMinutes($i)]);
        }
        $this->actingAs($this->withUser($a));

        $this->get('/area/clienti')->assertOk()->assertSee('Cliente01')->assertDontSee('Cliente55')->assertSee('Pagina 1 di 2')->assertSee('55 clienti');
        $this->get('/area/clienti?pagina=2')->assertOk()->assertSee('Cliente55')->assertDontSee('Cliente01')->assertSee('Precedenti');
        $this->get('/area/clienti?stato=senza-ordini&pagina=2')->assertOk()->assertSee('Cliente55')->assertSee('stato=senza-ordini', false);
    }

    public function test_profile_can_be_updated_and_password_changed(): void
    {
        $u = User::factory()->create(['password' => 'vecchia-password-lunga']);
        Profile::create(['user_id' => $u->id, 'type' => 'private', 'first_name' => 'Anna', 'last_name' => 'Verdi', 'address' => 'Via Roma 1', 'city' => 'Roma', 'zip' => '00100']);
        $this->actingAs($u);

        $this->post('/area/profilo', ['first_name' => 'Anna', 'last_name' => 'Bianchi', 'phone' => '333', 'address' => 'Via Milano 2', 'city' => 'Milano', 'zip' => '20100', 'province' => 'MI'])->assertSessionHasNoErrors();
        $this->assertSame('Anna Bianchi', $u->refresh()->name);
        $this->assertSame('Via Milano 2', $u->profile->address);

        $this->post('/area/profilo/password', ['current_password' => 'sbagliata', 'password' => 'nuova-password-lunga', 'password_confirmation' => 'nuova-password-lunga'])->assertSessionHasErrors('current_password');
        $this->post('/area/profilo/password', ['current_password' => 'vecchia-password-lunga', 'password' => 'nuova-password-lunga', 'password_confirmation' => 'nuova-password-lunga'])->assertSessionHasNoErrors();
        $this->assertTrue(\Hash::check('nuova-password-lunga', $u->refresh()->password));
    }

    public function test_profile_requires_address(): void
    {
        $u = User::factory()->create();
        Profile::create(['user_id' => $u->id, 'type' => 'private', 'first_name' => 'Anna', 'last_name' => 'Verdi']);
        $this->actingAs($u)->post('/area/profilo', ['first_name' => 'Anna', 'last_name' => 'Verdi'])->assertSessionHasErrors(['address', 'city', 'zip']);
    }

    public function test_error_pages_are_in_italian(): void
    {
        $this->get('/pagina-che-non-esiste')->assertNotFound()->assertSee('Pagina non trovata')->assertSee('Torna alla pagina iniziale');
    }
}
