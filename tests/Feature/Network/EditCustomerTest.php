<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Domain\Access\Roles;
use App\Domain\Customers\CustomerService;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Pagina «Modifica cliente»: admin e super_admin cambiano i dati; l'incaricato si cambia solo con «Riassegna». */
final class EditCustomerTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private function actingAsRole(string $role): void
    {
        Roles::sync();
        $this->actingAs(User::factory()->role($role)->create());
    }

    private function customer(): Customer
    {
        return app(CustomerService::class)->createByAdmin(['email' => 'cliente@example.com', 'first_name' => 'Mario', 'last_name' => 'Rossi'], $this->consultant('LM00001'));
    }

    public function test_an_admin_edits_the_customer_and_the_change_is_logged(): void
    {
        $this->actingAsRole('admin_network');
        $c = $this->customer();
        $consultantId = $c->assigned_consultant_id;

        Livewire::test(EditCustomer::class, ['record' => $c->getKey()])
            ->assertFormSet(['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'cliente@example.com'])
            ->fillForm(['first_name' => 'Maria', 'email' => ' Nuova@Example.com ', 'city' => 'Roma', 'tax_code' => 'rssmra80a01h501u'])
            ->call('save')->assertHasNoFormErrors();

        $user = User::findOrFail($c->user_id);
        $this->assertSame(['Maria Rossi', 'nuova@example.com'], [$user->name, $user->email]);
        $this->assertSame(['Roma', 'RSSMRA80A01H501U'], [$user->profile->city, $user->profile->tax_code]);
        $this->assertSame($consultantId, $c->fresh()->assigned_consultant_id);
        $this->assertNotNull(DB::table('audit_logs')->where('action', 'customer.updated')->first());
    }

    public function test_a_company_requires_name_and_vat_number(): void
    {
        $this->actingAsRole('admin_network');
        $c = $this->customer();

        Livewire::test(EditCustomer::class, ['record' => $c->getKey()])
            ->fillForm(['type' => 'company', 'company_name' => null, 'vat_number' => null])->call('save')->assertHasFormErrors(['company_name', 'vat_number']);
        Livewire::test(EditCustomer::class, ['record' => $c->getKey()])
            ->fillForm(['type' => 'company', 'company_name' => 'Acme Srl', 'vat_number' => '01234567890'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('Acme Srl', User::findOrFail($c->user_id)->name);
    }

    public function test_an_email_used_by_another_user_is_refused(): void
    {
        $this->actingAsRole('admin_network');
        $c = $this->customer();
        User::factory()->create(['email' => 'occupata@example.com']);

        Livewire::test(EditCustomer::class, ['record' => $c->getKey()])->fillForm(['email' => 'occupata@example.com'])->call('save')->assertHasFormErrors(['email']);
    }

    public function test_who_cannot_manage_the_network_cannot_open_the_page(): void
    {
        $this->actingAsRole('support');
        $c = $this->customer();

        Livewire::test(EditCustomer::class, ['record' => $c->getKey()])->assertForbidden();
    }
}
