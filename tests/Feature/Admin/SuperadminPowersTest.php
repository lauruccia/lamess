<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\System\CascadeDeleter;
use App\Filament\Resources\AccountTransfers\AccountTransferResource;
use App\Filament\Resources\Payouts\PayoutResource;
use App\Filament\Resources\Sanctions\SanctionResource;
use App\Filament\Resources\Tickets\Pages\EditTicket;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Il super_admin può modificare ed eliminare ogni dato del back-office; l'admin del cliente no. */
final class SuperadminPowersTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private function ticketWithMessages(): Ticket
    {
        $user = User::factory()->create();
        $t = Ticket::create(['user_id' => $user->id, 'category' => 'general', 'subject' => 'Aiuto', 'status' => 'open']);
        TicketMessage::create(['ticket_id' => $t->id, 'user_id' => $user->id, 'body' => 'Ciao']);
        TicketMessage::create(['ticket_id' => $t->id, 'body' => 'Risposta', 'is_staff' => true]);

        return $t;
    }

    public function test_resources_locked_for_everyone_open_up_for_the_superadmin_only(): void
    {
        $this->actingAs(User::factory()->role('super_admin')->create());
        foreach ([TicketResource::class, PayoutResource::class, SanctionResource::class, AccountTransferResource::class] as $resource) {
            $this->assertTrue($resource::canEdit(new ($resource::getModel())), $resource.' edit');
            $this->assertTrue($resource::canDelete(new ($resource::getModel())), $resource.' delete');
        }

        $this->actingAs(User::factory()->role('admin_finance')->create());
        foreach ([TicketResource::class, PayoutResource::class, SanctionResource::class, AccountTransferResource::class] as $resource) {
            $this->assertFalse($resource::canEdit(new ($resource::getModel())), $resource.' edit');
            $this->assertFalse($resource::canDelete(new ($resource::getModel())), $resource.' delete');
        }
    }

    public function test_superadmin_deletes_a_ticket_with_its_messages_after_confirming(): void
    {
        $t = $this->ticketWithMessages();
        $this->actingAs(User::factory()->role('super_admin')->create());

        Livewire::test(ListTickets::class)
            ->callTableAction('superadminDelete', $t, ['conferma' => 'no'])
            ->assertHasTableActionErrors(['conferma']);
        $this->assertTrue(Ticket::whereKey($t->id)->exists());

        Livewire::test(ListTickets::class)
            ->callTableAction('superadminDelete', $t, ['conferma' => 'ELIMINA'])
            ->assertHasNoTableActionErrors();
        $this->assertFalse(Ticket::whereKey($t->id)->exists());
        $this->assertSame(0, TicketMessage::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.deleted']);
    }

    public function test_superadmin_can_delete_in_bulk(): void
    {
        $a = $this->ticketWithMessages();
        $b = $this->ticketWithMessages();
        $this->actingAs(User::factory()->role('super_admin')->create());

        Livewire::test(ListTickets::class)
            ->callTableBulkAction('superadminBulkDelete', [$a, $b], ['conferma' => 'ELIMINA']);
        $this->assertSame(0, Ticket::count());
    }

    public function test_admin_does_not_get_the_superadmin_actions_nor_the_edit_page(): void
    {
        $t = $this->ticketWithMessages();
        $this->actingAs(User::factory()->role('admin_finance')->create());

        Livewire::test(ListTickets::class)
            ->assertTableActionDoesNotExist('superadminDelete')
            ->assertTableActionDoesNotExist('superadminEdit');
        $this->get(TicketResource::getUrl('edit', ['record' => $t]))->assertForbidden();
    }

    public function test_generic_edit_page_saves_and_writes_the_audit_log(): void
    {
        $t = $this->ticketWithMessages();
        $this->actingAs(User::factory()->role('super_admin')->create());

        $this->get(TicketResource::getUrl('edit', ['record' => $t]))->assertOk();
        Livewire::test(EditTicket::class, ['record' => $t->getKey()])
            ->fillForm(['subject' => 'Oggetto corretto', 'status' => 'closed'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('Oggetto corretto', $t->fresh()->subject);
        $this->assertSame('closed', $t->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.updated']);
    }

    public function test_deletion_plan_lists_what_goes_with_the_record(): void
    {
        $t = $this->ticketWithMessages();
        $plan = CascadeDeleter::plan($t);
        $this->assertSame(['ticket_messages' => 2], $plan['delete']);
        $this->assertTrue(CascadeDeleter::hasDependents($t));
    }

    public function test_optional_links_are_detached_not_deleted(): void
    {
        $this->plan();
        $c = $this->consultant('LM00001');
        $t = $this->ticketWithMessages();
        $t->update(['consultant_id' => $c->id]);

        $plan = CascadeDeleter::plan($c);
        $this->assertArrayHasKey('tickets', $plan['detach']);
        CascadeDeleter::delete($c);
        $this->assertNull($t->fresh()->consultant_id);
        $this->assertFalse(Consultant::whereKey($c->id)->exists());
    }

    public function test_guards_protect_accounts_the_tree_and_the_active_plan(): void
    {
        $plan = $this->plan();
        $root = $this->consultant('LM00001');
        $this->consultant('LM00002', $root);

        $super = User::factory()->role('super_admin')->create();
        $this->actingAs($super);

        foreach ([[$super, 'stesso account'], [User::factory()->role('super_admin')->create(), 'super_admin'], [$root, 'diretti'], [$plan, 'attiva']] as [$record, $why]) {
            try {
                CascadeDeleter::delete($record);
                $this->fail("Doveva rifiutare: {$why}");
            } catch (DomainException) {
                $this->assertTrue($record->fresh() !== null, $why);
            }
        }
        $this->assertSame(CompPlanVersion::ACTIVE, $plan->fresh()->status);
    }

    public function test_user_rules_for_superadmin(): void
    {
        $super = User::factory()->role('super_admin')->create();
        $other = User::factory()->role('super_admin')->create();
        $admin = User::factory()->role('admin')->create();
        $this->actingAs($super);

        $this->assertTrue(UserResource::canEdit($other), 'può modificare anche altri super_admin e se stesso');
        $this->assertTrue(UserResource::canEdit($super));
        $this->assertTrue(UserResource::canDelete($admin));
        $this->assertFalse(UserResource::canDelete($super), 'non elimina se stesso');
        $this->assertFalse(UserResource::canDelete($other), 'un super_admin si gestisce da riga di comando');

        CascadeDeleter::delete($admin);
        $this->assertFalse(User::withoutGlobalScopes()->whereKey($admin->id)->exists());
    }

    public function test_the_audit_log_stays_append_only(): void
    {
        $this->actingAs(User::factory()->role('super_admin')->create());
        $this->assertFalse(\App\Filament\Resources\AuditLogs\AuditLogResource::canDelete(new \App\Models\AuditEntry));
        $this->assertFalse(\App\Filament\Resources\AuditLogs\AuditLogResource::canEdit(new \App\Models\AuditEntry));
        $this->expectException(\Throwable::class);
        DB::table('audit_logs')->delete();
    }

    public function test_every_list_renders_for_the_superadmin_with_the_new_actions(): void
    {
        $this->actingAs(User::factory()->role('super_admin')->create());
        foreach (\Filament\Facades\Filament::getResources() as $resource) {
            if (! $resource::hasPage('index')) {
                continue;
            }
            $this->get($resource::getUrl('index'))->assertOk();
        }
    }

    /** Per ogni pagina di modifica diretta: apre una riga qualunque, mostra il modulo e salva senza cambiare nulla. */
    public function test_every_generated_edit_page_opens_and_saves(): void
    {
        $this->actingAs(User::factory()->role('super_admin')->create());
        $checked = 0;
        foreach (\Filament\Facades\Filament::getResources() as $resource) {
            $page = $resource::getPages()['edit'] ?? null;
            if ($page === null || ! is_subclass_of($page->getPage(), \App\Filament\Pages\Base\SuperadminEditRecord::class)) {
                continue;
            }
            $model = new ($resource::getModel());
            $id = $this->insertFilledRow($model->getTable(), 0, $model->getCasts());
            if ($id === null) {
                $this->fail('Impossibile creare una riga di prova per '.$model->getTable());
            }
            Livewire::test($page->getPage(), ['record' => $id])->assertOk()->call('save')->assertHasNoFormErrors();
            $checked++;
        }
        $this->assertGreaterThan(20, $checked);
    }

    private function insertFilledRow(string $table, int $depth = 0, array $casts = []): int|string|null
    {
        $row = [];
        $fks = [];
        foreach (\Illuminate\Support\Facades\Schema::getForeignKeys($table) as $fk) {
            $fks[$fk['columns'][0]] = $fk['foreign_table'];
        }
        foreach (\Illuminate\Support\Facades\Schema::getColumns($table) as $c) {
            if ($c['auto_increment'] || $c['name'] === 'id') {
                continue;
            }
            if ($c['nullable'] || $c['default'] !== null) {
                continue;
            }
            if (isset($fks[$c['name']]) && $depth < 6) {
                $row[$c['name']] = $fks[$c['name']] === $table ? 1 : (DB::table($fks[$c['name']])->value('id') ?? $this->insertFilledRow($fks[$c['name']], $depth + 1));
                continue;
            }
            $t = strtolower((string) $c['type_name']);
            $row[$c['name']] = match (true) {
                in_array($t, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint', 'bool', 'boolean'], true) => 1,
                in_array($t, ['decimal', 'float', 'double', 'real', 'numeric'], true) => '1.00',
                $t === 'date' => '2026-10-01',
                in_array($t, ['datetime', 'timestamp'], true) => '2026-10-01 10:00:00',
                $t === 'json', in_array($casts[$c['name']] ?? '', ['array', 'json', 'collection', 'object'], true) => '{}',
                default => 'x'.substr(uniqid(), -5),
            };
        }

        return DB::table($table)->insertGetId($row);
    }

    public function test_superadmin_edits_another_superadmin_and_deletes_an_archived_plan(): void
    {
        $plan = $this->plan();
        $old = CompPlanVersion::createFromArray('Vecchio piano', $plan->data, CompPlanVersion::ARCHIVED);
        $super = User::factory()->role('super_admin')->create();
        $other = User::factory()->role('super_admin')->create(['name' => 'Prima']);
        $this->actingAs($super);

        Livewire::test(\App\Filament\Resources\Users\Pages\EditUser::class, ['record' => $other->getKey()])
            ->fillForm(['name' => 'Dopo'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Dopo', $other->fresh()->name);

        Livewire::test(\App\Filament\Resources\CompPlanVersions\Pages\ListCompPlanVersions::class)
            ->callTableAction('superadminDelete', $old);
        $this->assertFalse(CompPlanVersion::whereKey($old->id)->exists());
        $this->assertTrue(CompPlanVersion::whereKey($plan->id)->exists());
    }
}
