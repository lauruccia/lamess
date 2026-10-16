<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Access\Roles;
use App\Domain\Audit\AuditLog;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AuditLog::forget();
    }

    private function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
    }

    public function test_records_form_a_valid_chain(): void
    {
        AuditLog::record('test.a', 'primo');
        AuditLog::record('test.b', 'secondo', null, ['x' => 1]);
        AuditLog::record('test.c', 'terzo');

        $rows = DB::table('audit_logs')->orderBy('id')->get();
        $this->assertSame(AuditLog::GENESIS, $rows[0]->prev_hash);
        $this->assertSame($rows[0]->hash, $rows[1]->prev_hash);
        $this->assertSame($rows[1]->hash, $rows[2]->prev_hash);

        $check = AuditLog::verify();
        $this->assertTrue($check->ok);
        $this->assertSame(3, $check->checked);
        $this->assertNotNull($check->anchor());
    }

    public function test_a_log_imported_with_an_older_key_verifies_with_the_previous_key_up_to_the_given_row(): void
    {
        config(['lamess.audit_key' => 'chiave-importata-da-un-altro-ambiente']);
        AuditLog::record('test.a', 'primo');
        $lastOld = AuditLog::record('test.b', 'secondo');

        config(['lamess.audit_key' => 'chiave-del-server']);
        AuditLog::record('test.c', 'terzo');
        AuditLog::record('test.d', 'quarto');

        $this->assertSame(1, AuditLog::verify()->badId);

        config(['lamess.audit_key_previous' => 'chiave-importata-da-un-altro-ambiente', 'lamess.audit_key_previous_until' => $lastOld]);
        $check = AuditLog::verify();
        $this->assertTrue($check->ok);
        $this->assertSame(4, $check->checked);

        config(['lamess.audit_key_previous_until' => $lastOld - 1]);
        $this->assertSame($lastOld, AuditLog::verify()->badId);
    }

    public function test_empty_log_is_valid(): void
    {
        $this->assertTrue(AuditLog::verify()->ok);
    }

    public function test_database_triggers_forbid_update_and_delete(): void
    {
        AuditLog::record('test.a', 'primo');
        $this->assertTrue(AuditLog::dbProtected());

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->update(['description' => 'manomesso']);
    }

    public function test_database_triggers_forbid_delete(): void
    {
        AuditLog::record('test.a', 'primo');
        $this->expectException(QueryException::class);
        DB::table('audit_logs')->delete();
    }

    public function test_modified_row_is_detected(): void
    {
        AuditLog::record('test.a', 'primo');
        AuditLog::record('test.b', 'secondo');
        AuditLog::record('test.c', 'terzo');
        $this->dropTriggers();
        $id = (int) DB::table('audit_logs')->orderBy('id')->skip(1)->value('id');
        DB::table('audit_logs')->where('id', $id)->update(['description' => 'alterata']);

        $check = AuditLog::verify();
        $this->assertFalse($check->ok);
        $this->assertSame($id, $check->badId);
    }

    public function test_deleted_middle_row_is_detected(): void
    {
        AuditLog::record('test.a', 'primo');
        AuditLog::record('test.b', 'secondo');
        AuditLog::record('test.c', 'terzo');
        $this->dropTriggers();
        $id = (int) DB::table('audit_logs')->orderBy('id')->skip(1)->value('id');
        DB::table('audit_logs')->where('id', $id)->delete();

        $check = AuditLog::verify();
        $this->assertFalse($check->ok);
        $this->assertStringContainsString('catena interrotta', (string) $check->problem);
    }

    public function test_recomputing_hash_without_key_is_not_possible(): void
    {
        AuditLog::record('test.a', 'primo');
        $this->dropTriggers();
        $row = DB::table('audit_logs')->first();
        // chi non ha la chiave prova con un semplice sha256: la verifica deve accorgersene
        DB::table('audit_logs')->where('id', $row->id)->update(['description' => 'x', 'hash' => hash('sha256', 'x')]);
        $this->assertFalse(AuditLog::verify()->ok);
    }

    public function test_truncated_tail_is_detected_by_anchor_only(): void
    {
        AuditLog::record('test.a', 'primo');
        AuditLog::record('test.b', 'secondo');
        $anchor = (string) AuditLog::verify()->anchor();
        $this->assertNull(AuditLog::checkAnchor($anchor));

        $this->dropTriggers();
        DB::table('audit_logs')->orderByDesc('id')->limit(1)->delete();

        $this->assertTrue(AuditLog::verify()->ok, 'Limite noto: la coda tagliata non rompe la catena.');
        $this->assertNotNull(AuditLog::checkAnchor($anchor));
        $this->assertNotNull(AuditLog::checkAnchor('non valida'));
    }

    public function test_model_is_immutable(): void
    {
        AuditLog::record('test.a', 'primo');
        $entry = AuditEntry::first();

        foreach ([fn () => $entry->update(['description' => 'x']), fn () => $entry->delete(), fn () => AuditEntry::create(['action' => 'x'])] as $op) {
            try {
                $op();
                $this->fail('Il modello del log deve essere in sola lettura.');
            } catch (\Throwable $e) {
                $this->assertNotInstanceOf(AssertionFailedError::class, $e);
            }
        }
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_secrets_are_never_written(): void
    {
        AuditLog::record('test.secret', 'prova', null, ['password' => 'Segreto123456', 'nested' => ['token' => 'abc', 'ok' => 'visibile']]);
        $raw = (string) DB::table('audit_logs')->value('changes');

        $this->assertStringNotContainsString('Segreto123456', $raw);
        $this->assertStringNotContainsString('abc', $raw);
        $this->assertStringContainsString('visibile', $raw);
        $this->assertTrue(AuditLog::verify()->ok);
    }

    public function test_user_observer_logs_creation_and_changes_without_password(): void
    {
        $u = User::factory()->create(['email' => 'a@example.test']);
        $u->update(['email' => 'b@example.test', 'password' => 'NuovaPassword123']);

        $dump = DB::table('audit_logs')->get()->map(fn ($r) => json_encode($r))->implode('|');
        $this->assertStringContainsString('user.created', $dump);
        $this->assertStringContainsString('user.updated', $dump);
        $this->assertStringContainsString('b@example.test', $dump);
        $this->assertStringNotContainsString('NuovaPassword123', $dump);
        $this->assertTrue(AuditLog::verify()->ok);
    }

    public function test_role_changes_are_logged(): void
    {
        $u = User::factory()->role(Roles::ADMIN)->create();
        $this->assertGreaterThan(0, DB::table('audit_logs')->where('action', 'like', '%role%')->count() + DB::table('audit_logs')->where('action', 'like', '%permission%')->count());
    }

    public function test_actor_is_recorded(): void
    {
        $admin = User::factory()->role(Roles::SUPER_ADMIN)->create(['email' => 'chi@example.test']);
        $this->actingAs($admin);
        AuditLog::record('test.actor', 'fatto da me');

        $row = DB::table('audit_logs')->where('action', 'test.actor')->first();
        $this->assertSame($admin->id, (int) $row->actor_id);
        $this->assertSame('chi@example.test', $row->actor_label);
    }

    public function test_verify_command(): void
    {
        AuditLog::record('test.a', 'primo');
        $this->artisan('lamess:audit-verifica')->assertExitCode(0);

        $this->dropTriggers();
        DB::table('audit_logs')->update(['description' => 'alterata']);
        $this->artisan('lamess:audit-verifica')->assertExitCode(1);
    }
}
