<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Ledger\PointsGrant;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\PointGrants\Pages\ListPointGrants;
use App\Models\CompPlanVersion;
use App\Models\Customer;
use App\Models\PvMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** «Nuovo cliente» e «Assegna punti» dal pannello: admin con il permesso e super admin sì, gli altri no. */
final class PointsAndCustomersAdminTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private function openIncaricati(): void
    {
        $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);
        $data['policies'] = ['activity_month_rule' => 'current_or_previous'];
        $this->openPeriod(now('Europe/Rome')->format('Y-m'), CompPlanVersion::createFromArray('Incaricati', $data, CompPlanVersion::ACTIVE));
    }

    public function test_super_admin_creates_a_private_customer_assigned_to_a_consultant(): void
    {
        $this->plan();
        $sponsor = $this->activeSponsor();
        $this->actingAs(User::factory()->role()->create());

        Livewire::test(ListCustomers::class)
            ->callAction('newCustomer', [
                'type' => 'private', 'first_name' => 'Anna', 'last_name' => 'Verdi', 'email' => 'anna.verdi@example.test',
                'phone' => '3331234567', 'city' => 'Reggio Calabria', 'consultant_id' => $sponsor->id, 'send_link' => false,
            ])->assertHasNoActionErrors()->assertNotified('Cliente creato: Anna Verdi');

        $customer = Customer::whereHas('user', fn ($q) => $q->where('email', 'anna.verdi@example.test'))->firstOrFail();
        $this->assertSame($sponsor->id, $customer->assigned_consultant_id);
        $this->assertSame('manual', $customer->source);
        $this->assertSame('private', $customer->user->profile->type);
        $this->assertSame('Reggio Calabria', $customer->user->profile->city);
        $this->assertNotEmpty($customer->user->password, 'password casuale che nessuno conosce');
    }

    public function test_admin_with_network_manage_creates_a_company_customer(): void
    {
        $this->plan();
        $this->actingAs(User::factory()->role('admin_network')->create());

        Livewire::test(ListCustomers::class)
            ->callAction('newCustomer', [
                'type' => 'company', 'company_name' => 'Acme Srl', 'vat_number' => '01234567890', 'pec' => 'acme@pec.it',
                'email' => 'info@acme.example.test', 'send_link' => false,
            ])->assertHasNoActionErrors();

        $this->assertDatabaseHas('customers', ['source' => 'manual']);
        $this->assertSame('Acme Srl', User::where('email', 'info@acme.example.test')->firstOrFail()->name);
    }

    public function test_company_without_sdi_or_pec_and_duplicate_email_are_refused(): void
    {
        $this->plan();
        $this->fan(null, ['email' => 'gia@example.test']);
        $this->actingAs(User::factory()->role()->create());

        Livewire::test(ListCustomers::class)
            ->callAction('newCustomer', ['type' => 'company', 'company_name' => 'Senza Pec', 'vat_number' => '1', 'email' => 'x@example.test', 'send_link' => false])
            ->assertNotified('Cliente non creato');
        Livewire::test(ListCustomers::class)
            ->callAction('newCustomer', ['type' => 'private', 'first_name' => 'Doppio', 'last_name' => 'Email', 'email' => 'gia@example.test', 'send_link' => false])
            ->assertNotified('Cliente non creato');

        $this->assertSame(1, Customer::count());
    }

    public function test_a_role_without_network_manage_does_not_see_new_customer(): void
    {
        $this->plan();
        $this->actingAs(User::factory()->role('board')->create());

        Livewire::test(ListCustomers::class)->assertActionHidden('newCustomer');
    }

    public function test_super_admin_assigns_points_to_a_consultant_and_to_the_sponsor(): void
    {
        $this->openIncaricati();
        $upline = $this->activeSponsor();
        $socio = $this->consultant('LM00002', $upline);
        $this->actingAs(User::factory()->role()->create());

        Livewire::test(ListPointGrants::class)
            ->callAction('grantPoints', [
                'consultant_id' => $socio->id, 'points' => '50', 'event_at' => now()->toDateTimeString(),
                'reason' => 'Quota consorzio Kadeo', 'effect' => PointsGrant::EFFECT_SCORE,
                'also_sponsor' => true, 'sponsor_points' => '20',
            ])->assertHasNoActionErrors()->assertNotified('Punti assegnati al beneficiario e al suo sponsor');

        $this->assertSame('50.00', (string) PvMovement::where('consultant_id', $socio->id)->firstOrFail()->pv);
        $sponsorRow = PvMovement::where('consultant_id', $upline->id)->firstOrFail();
        $this->assertSame('20.00', (string) $sponsorRow->pv);
        $this->assertSame('Quota consorzio Kadeo', $sponsorRow->note);
    }

    public function test_client_for_a_month_from_the_form_and_the_list_shows_and_cancels_it(): void
    {
        $this->openIncaricati();
        $sponsor = $this->activeSponsor();
        $client = $this->buyer();
        $this->actingAs(User::factory()->role('admin_finance')->create());

        Livewire::test(ListPointGrants::class)
            ->callAction('grantPoints', [
                'consultant_id' => $sponsor->id, 'points' => '10', 'event_at' => now()->toDateTimeString(), 'reason' => 'Cliente Kadeo',
                'effect' => PointsGrant::EFFECT_CLIENT, 'client_user_id' => $client->id, 'months' => 1, 'clients' => 1,
            ])->assertHasNoActionErrors();

        $m = PvMovement::where('type', 'grant')->firstOrFail();
        $this->assertSame($client->id, $m->client_user_id);

        Livewire::test(ListPointGrants::class)
            ->assertCanSeeTableRecords([$m])
            ->callTableAction('cancelGrant', $m, ['reason' => 'doppione'])->assertNotified('Assegnazione annullata');
        $this->assertSame('cancelled', $m->refresh()->status);
    }

    public function test_invalid_grant_from_the_form_is_not_saved(): void
    {
        $this->openIncaricati();
        $sponsor = $this->activeSponsor();
        $this->actingAs(User::factory()->role()->create());

        Livewire::test(ListPointGrants::class)
            ->callAction('grantPoints', [
                'consultant_id' => $sponsor->id, 'points' => '10', 'event_at' => now()->toDateTimeString(), 'reason' => 'Acquisto senza importo',
                'effect' => PointsGrant::EFFECT_PURCHASE, 'client_user_id' => $this->buyer()->id, 'months' => 1, 'clients' => 1,
            ])->assertHasActionErrors(['amount' => 'required']);

        $this->assertSame(0, PvMovement::count());
    }

    public function test_only_roles_that_manage_periods_can_assign_points(): void
    {
        $this->openIncaricati();
        $this->actingAs(User::factory()->role('board')->create());

        Livewire::test(ListPointGrants::class)->assertActionHidden('grantPoints');
    }

    public function test_the_granted_points_show_in_the_admin_card_and_in_the_consultants_area(): void
    {
        $this->openIncaricati();
        $socio = $this->consultant('LM00002', $this->activeSponsor());
        $me = User::factory()->create(['name' => $socio->name]);
        $socio->update(['user_id' => $me->id]);
        $other = $this->buyer();
        app(PointsGrant::class)->grant($socio, '40', 'Quota consorzio Kadeo', PointsGrant::EFFECT_CLIENT, $other, at: now());
        app(PointsGrant::class)->grant($socio, '5', 'Premio in cancelleria', at: now());

        $this->actingAs(User::factory()->role()->create());
        $this->get('/admin/consultants/'.$socio->id)->assertOk()
            ->assertSee('Punti assegnati dall\'azienda', false)->assertSee('Quota consorzio Kadeo')->assertSee('Premio in cancelleria')->assertSee($other->name);

        $this->actingAs($me);
        $this->get('/area')->assertOk()->assertSee('Punti assegnati')->assertSee('Quota consorzio Kadeo')->assertSee('45')
            ->assertDontSee($other->name, false);
    }
}
