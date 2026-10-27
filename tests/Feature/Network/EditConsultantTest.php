<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Domain\Access\Roles;
use App\Domain\Network\ConsultantAccess;
use App\Filament\Resources\Consultants\Pages\EditConsultant;
use App\Models\Consultant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Pagina «Modifica incaricato»: admin e super_admin cambiano tutti i dati; sponsor, stato e IBAN in chiaro restano protetti. */
final class EditConsultantTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lamess.mail_enabled' => true]);
        Notification::fake();
    }

    private function actingAsRole(string $role): User
    {
        Roles::sync();
        $user = User::factory()->role($role)->create();
        $this->actingAs($user);

        return $user;
    }

    /** Incaricato con accesso al portale e profilo, sotto uno sponsor. */
    private function consultantWithAccess(): Consultant
    {
        $sponsor = $this->consultant('LM00001');
        $c = $this->consultant('LM00002', $sponsor);
        app(ConsultantAccess::class)->grant($c, 'vecchia@example.com', false);

        return $c->refresh();
    }

    public function test_an_admin_edits_name_contacts_and_flags_and_the_change_is_logged(): void
    {
        $this->actingAsRole('admin_network');
        $c = $this->consultantWithAccess();
        $sponsorId = $c->sponsor_id;

        Livewire::test(EditConsultant::class, ['record' => $c->getKey()])
            ->assertFormSet(['first_name' => 'Incaricato', 'last_name' => 'LM00002', 'email' => 'vecchia@example.com'])
            ->fillForm([
                'first_name' => 'Linda', 'last_name' => 'Gean', 'person_key' => 'gnelnd80a41h501x', 'email' => ' Nuova@Example.com ',
                'city' => 'Reggio Calabria', 'phone' => '+39 347 000 0000', 'birth_date' => '1980-01-01', 'bonus_hold' => true, 'is_founder' => true,
                'code' => 'LM99999',
            ])
            ->call('save')->assertHasNoFormErrors();

        $c->refresh();
        $user = User::findOrFail($c->user_id);
        $this->assertSame('Linda Gean', $c->name);
        $this->assertSame('GNELND80A41H501X', $c->person_key);
        $this->assertTrue($c->bonus_hold && $c->is_founder);
        $this->assertSame('LM00002', $c->code, 'il codice attività lo cambia solo il super admin');
        $this->assertSame($sponsorId, $c->sponsor_id);
        $this->assertSame(['Linda Gean', 'nuova@example.com'], [$user->name, $user->email]);
        $this->assertSame(['Linda', 'Gean', 'Reggio Calabria', '+39 347 000 0000', 'GNELND80A41H501X'], [$user->profile->first_name, $user->profile->last_name, $user->profile->city, $user->profile->phone, $user->profile->tax_code]);
        $this->assertSame('1980-01-01', $user->profile->birth_date->format('Y-m-d'));

        $log = DB::table('audit_logs')->where('action', 'consultant.updated')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Compensi bloccati', (string) $log->changes);
    }

    public function test_the_super_admin_can_change_the_code_but_not_to_a_duplicate(): void
    {
        $this->actingAsRole(Roles::SUPER_ADMIN);
        $c = $this->consultantWithAccess();

        Livewire::test(EditConsultant::class, ['record' => $c->getKey()])->fillForm(['code' => 'LM00001'])->call('save')->assertHasFormErrors(['code']);
        Livewire::test(EditConsultant::class, ['record' => $c->getKey()])->fillForm(['code' => 'LM00777'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('LM00777', $c->fresh()->code);
    }

    public function test_the_iban_is_never_sent_to_the_form_and_a_new_one_replaces_it(): void
    {
        $this->actingAsRole('admin_network');
        $c = $this->consultantWithAccess();
        $c->update(['iban' => 'IT28W8000000292100645211', 'iban_holder' => 'Vecchio Titolare']);

        $page = Livewire::test(EditConsultant::class, ['record' => $c->getKey()]);
        $this->assertArrayNotHasKey('iban', $page->get('data'));
        $this->assertStringNotContainsString('IT28W8000000292100645211', json_encode($page->get('data'), JSON_THROW_ON_ERROR));

        $page->fillForm(['iban_new' => 'IT00'])->call('save')->assertHasFormErrors(['iban_new']);
        $page->fillForm(['iban_new' => 'IT60 X054 2811 1010 0000 0123 456', 'iban_holder' => ''])->call('save')->assertHasNoFormErrors();

        $c->refresh();
        $this->assertSame('IT60X0542811101000000123456', $c->iban);
        $this->assertSame($c->name, $c->iban_holder, 'intestatario vuoto = nome e cognome');
    }

    public function test_a_foreign_iban_needs_the_bic_and_the_bic_can_be_changed_alone(): void
    {
        $this->actingAsRole('admin_network');
        $c = $this->consultantWithAccess();
        $c->update(['iban' => 'IT28W8000000292100645211', 'iban_holder' => 'Vecchio Titolare']);

        $page = Livewire::test(EditConsultant::class, ['record' => $c->getKey()]);
        $page->fillForm(['iban_new' => 'TN59 1000 6035 1835 9847 8831', 'bic' => ''])->call('save');
        $this->assertSame('IT28W8000000292100645211', $c->fresh()->iban, 'IBAN tunisino senza BIC: non si salva');

        $page->fillForm(['iban_new' => 'TN59 1000 6035 1835 9847 8831', 'bic' => 'BIAT'])->call('save')->assertHasFormErrors(['bic']);
        $page->fillForm(['iban_new' => 'TN59 1000 6035 1835 9847 8831', 'bic' => 'atbk tntt'])->call('save')->assertHasNoFormErrors();
        $c->refresh();
        $this->assertSame('TN5910006035183598478831', $c->iban);
        $this->assertSame('ATBKTNTT', $c->bic);

        // Solo il BIC: cambia senza toccare l'IBAN.
        $page = Livewire::test(EditConsultant::class, ['record' => $c->getKey()]);
        $page->fillForm(['iban_new' => null, 'bic' => 'BIATTNTT'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('BIATTNTT', $c->fresh()->bic);
        $this->assertSame('TN5910006035183598478831', $c->fresh()->iban);
    }

    public function test_without_an_access_the_email_creates_it_and_personal_data_requires_the_email(): void
    {
        $this->actingAsRole('admin_network');
        $c = $this->consultant('LM00005');

        Livewire::test(EditConsultant::class, ['record' => $c->getKey()])
            ->fillForm(['city' => 'Roma', 'email' => null])->call('save')->assertHasFormErrors(['email']);

        Livewire::test(EditConsultant::class, ['record' => $c->getKey()])
            ->fillForm(['first_name' => 'Anna', 'last_name' => 'Bianchi', 'city' => 'Roma', 'email' => 'anna@example.com', 'send_invite' => false])
            ->call('save')->assertHasNoFormErrors();

        $c->refresh();
        $user = User::where('email', 'anna@example.com')->firstOrFail();
        $this->assertSame($user->id, $c->user_id);
        $this->assertSame('Anna Bianchi', $c->name);
        $this->assertSame('Roma', $user->profile->city);
    }

    public function test_an_email_already_used_by_another_user_is_refused(): void
    {
        $this->actingAsRole('admin_network');
        $c = $this->consultantWithAccess();
        User::factory()->create(['email' => 'occupata@example.com']);

        Livewire::test(EditConsultant::class, ['record' => $c->getKey()])->fillForm(['email' => 'occupata@example.com'])->call('save')->assertHasFormErrors(['email']);
    }

    public function test_saving_creates_the_badge_for_who_has_none_yet(): void
    {
        $this->actingAsRole('admin_network');
        $c = $this->consultantWithAccess();
        $this->assertNull($c->badge_number);

        Livewire::test(EditConsultant::class, ['record' => $c->getKey()])->call('save')->assertHasNoFormErrors();

        $c->refresh();
        $this->assertSame($c->code, $c->badge_number);
        $this->assertNotNull($c->badge_valid_until);
    }

    public function test_who_cannot_manage_the_network_cannot_open_the_page(): void
    {
        $this->actingAsRole('support');
        $c = $this->consultantWithAccess();

        Livewire::test(EditConsultant::class, ['record' => $c->getKey()])->assertForbidden();
    }
}
