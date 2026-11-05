<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Domain\Access\Roles;
use App\Domain\Network\ConsultantConversion;
use App\Domain\Network\NetworkException;
use App\Domain\Network\NetworkService;
use App\Filament\Resources\Consultants\Pages\ListConsultants;
use App\Models\Consultant;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** «Fai diventare cliente»: declassa se senza storico, cessa (e lascia cliente) se ha storico. */
final class MakeCustomerTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private Consultant $r;

    private Consultant $a;

    private Consultant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $this->plan();
        $this->r = $this->consultant('R');
        $this->a = $this->consultant('A', $this->r);
        $this->b = $this->consultant('B', $this->a);
        foreach ([$this->r, $this->a, $this->b] as $c) {
            $c->update(['user_id' => User::factory()->create()->id]);
            $c->user->assignRole('consultant');
        }
        app(NetworkService::class)->rebuildClosure();
    }

    public function test_senza_storico_esce_dall_albero_e_resta_cliente(): void
    {
        $mode = app(ConsultantConversion::class)->makeCustomer($this->a, 'Su richiesta');

        $this->assertSame('removed', $mode);
        $this->assertNull(Consultant::find($this->a->id));
        $this->assertSame($this->r->id, $this->b->refresh()->sponsor_id);
        $this->assertNotNull(Customer::where('user_id', $this->a->user_id)->first());
        $this->assertTrue(User::find($this->a->user_id)->hasRole('customer'));
    }

    public function test_con_storico_viene_cessato_e_resta_cliente(): void
    {
        $this->sale($this->openPeriod('2026-10'), $this->a, '100');

        $cl = User::factory()->create();
        Customer::create(['user_id' => $cl->id, 'assigned_consultant_id' => $this->a->id, 'source' => 'manual', 'registered_at' => now()]);

        $mode = app(ConsultantConversion::class)->makeCustomer($this->a, 'Su richiesta');

        $this->assertSame('terminated', $mode);
        $this->assertSame('terminated', $this->a->refresh()->status);
        $this->assertSame($this->r->id, $this->b->refresh()->sponsor_id);
        $user = User::find($this->a->user_id);
        $this->assertFalse($user->hasRole('consultant'));
        $this->assertTrue($user->hasRole('customer'));
        $this->assertSame($this->r->id, Customer::where('user_id', $user->id)->value('assigned_consultant_id'));
        $this->assertSame($this->r->id, Customer::where('user_id', $cl->id)->value('assigned_consultant_id'));
        $this->assertSame(0, DB::table('network_paths')->where('ancestor_id', $this->a->id)->orWhere('descendant_id', $this->a->id)->count());
    }

    public function test_un_cessato_non_si_converte(): void
    {
        app(NetworkService::class)->terminate($this->b, 'x');
        $this->expectException(NetworkException::class);
        app(ConsultantConversion::class)->makeCustomer($this->b->refresh(), 'x');
    }

    public function test_il_pulsante_e_nella_lista(): void
    {
        Livewire::test(ListConsultants::class)
            ->assertTableActionExists('makeCustomer')
            ->callTableAction('makeCustomer', $this->b, ['reason' => 'Prova'])
            ->assertNotified();

        $this->assertNull(Consultant::find($this->b->id));
        $this->assertNotNull(Customer::where('user_id', $this->b->user_id)->first());
    }
}
