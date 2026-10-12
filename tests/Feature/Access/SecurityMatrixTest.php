<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Roles;
use App\Domain\System\BackupService;
use App\Domain\System\GoLiveCheck;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SecurityMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Pagine solo per il super_admin. */
    private const SYSTEM = ['/admin/users', '/admin/impostazioni', '/admin/audit-logs', '/admin/verifica-audit', '/admin/backup', '/admin/controllo-messa-online'];

    /** Pagina → permesso richiesto. */
    private const BY_PERMISSION = [
        '/admin/consultants' => 'network.view', '/admin/customers' => 'network.view', '/admin/periods' => 'periods.view',
        '/admin/orders' => 'orders.view', '/admin/payouts' => 'payouts.view', '/admin/comp-plan-versions' => 'plan.view',
        '/admin/settlements' => 'payouts.view', '/admin/products' => 'catalog.manage', '/admin/negozio-pagamenti' => 'catalog.manage',
    ];

    public function test_guests_are_sent_to_login(): void
    {
        foreach (['/admin', '/admin/consultants', '/admin/backup'] as $url) {
            $this->get($url)->assertRedirect();
        }
        $this->get('/admin-files/backup/lamess-20260101-000000.sql.gz')->assertRedirect('/accedi');
    }

    public function test_portal_users_never_enter_the_backoffice(): void
    {
        foreach (['consultant', 'customer'] as $role) {
            $u = User::factory()->role($role)->create();
            foreach (['/admin', ...self::SYSTEM, ...array_keys(self::BY_PERMISSION)] as $url) {
                $this->actingAs($u)->get($url)->assertForbidden();
            }
        }
    }

    public function test_super_admin_opens_every_admin_page_without_errors(): void
    {
        $this->actingAs(User::factory()->role('super_admin')->create());
        foreach (['/admin', ...self::SYSTEM, ...array_keys(self::BY_PERMISSION), '/admin/albero-rete', '/admin/invoices', '/admin/credit-notes', '/admin/subscriptions', '/admin/pv-movements', '/admin/shipping-rates', '/admin/bundle-rules', '/admin/annual-fees', '/admin/kyc-documents', '/admin/ivd-contracts', '/admin/import-reconciliations'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    #[DataProvider('profiles')]
    public function test_each_admin_profile_sees_exactly_what_its_permissions_allow(string $profile): void
    {
        $perms = Roles::MATRIX[$profile];
        $this->actingAs(User::factory()->role($profile)->create());
        foreach (self::SYSTEM as $url) {
            $this->get($url)->assertForbidden();
        }
        foreach (self::BY_PERMISSION as $url => $perm) {
            in_array($perm, $perms, true) ? $this->get($url)->assertOk() : $this->get($url)->assertForbidden();
        }
    }

    /** @return array<string, array{string}> */
    public static function profiles(): array
    {
        return array_combine(array_keys(Roles::MATRIX), array_map(fn ($k) => [$k], array_keys(Roles::MATRIX)));
    }

    public function test_backup_download_is_for_super_admin_only_and_rejects_odd_names(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->role('admin_finance')->create())->get('/admin-files/backup/lamess-20260101-000000.sqlite')->assertForbidden();
        $this->actingAs(User::factory()->role('super_admin')->create());
        $this->get('/admin-files/backup/..%2F.env')->assertNotFound();
        $this->get('/admin-files/backup/lamess-20260101-000000.sqlite')->assertNotFound();
    }

    public function test_backup_lists_and_serves_files(): void
    {
        Storage::fake('local');
        $svc = app(BackupService::class);
        for ($i = 1; $i <= BackupService::KEEP + 3; $i++) {
            Storage::put(BackupService::DIR.sprintf('/lamess-202601%02d-020000.sql.gz', $i), 'dati'.$i);
            touch(Storage::path(BackupService::DIR.sprintf('/lamess-202601%02d-020000.sql.gz', $i)), 1_700_000_000 + $i * 86400);
        }
        $this->assertCount(BackupService::KEEP + 3, $svc->list());
        $this->assertSame('lamess-20260117-020000.sql.gz', $svc->list()[0]['name']);

        $this->actingAs(User::factory()->role('super_admin')->create())->get('/admin-files/backup/lamess-20260117-020000.sql.gz')->assertOk();
        $this->assertTrue(AuditEntry::where('action', 'backup.downloaded')->exists());
    }

    public function test_backup_of_an_unreachable_sqlite_fails_with_a_clear_message(): void
    {
        if (\DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Solo per SQLite.');
        }
        Storage::fake('local');
        $this->expectException(\RuntimeException::class);
        app(BackupService::class)->create();
    }

    public function test_mysql_dump_values_are_quoted_safely(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $this->assertSame('NULL', BackupService::sqlValue($pdo, null));
        $this->assertSame('12', BackupService::sqlValue($pdo, 12));
        $this->assertSame("'l''amore'", BackupService::sqlValue($pdo, "l'amore"));
    }

    public function test_mysql_backup_contains_schema_data_and_triggers(): void
    {
        if (! in_array(\DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Solo per MySQL/MariaDB.');
        }
        Storage::fake('local');
        User::factory()->create(['name' => "Mario l'Oste"]);
        $r = app(BackupService::class)->create('prova');
        $sql = gzdecode((string) file_get_contents(app(BackupService::class)->path($r['name'])));
        $this->assertStringContainsString('CREATE TABLE `users`', $sql);
        $this->assertStringContainsString("Mario l\\'Oste", $sql);
        $this->assertStringContainsString('CREATE TRIGGER', $sql);
        $this->assertStringContainsString('DELIMITER $$', $sql);
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/accedi')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Referrer-Policy');
    }

    public function test_site_and_admin_login_are_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/accedi', ['email' => 'x@example.test', 'password' => 'sbagliata']);
        }
        $this->post('/accedi', ['email' => 'x@example.test', 'password' => 'sbagliata'])->assertStatus(429);
    }

    public function test_go_live_check_flags_blockers_in_this_environment(): void
    {
        config(['app.debug' => true, 'lamess.payment_driver' => 'fake', 'lamess.seller.name' => 'Lamèss — ragione sociale da configurare']);
        $checks = collect(app(GoLiveCheck::class)->run());
        $this->assertSame('fail', $checks->firstWhere('label', 'Modalità debug spenta')['status']);
        $this->assertSame('fail', $checks->firstWhere('label', 'Pagamento con carta reale')['status']);
        $this->assertSame('fail', $checks->firstWhere('label', 'Dati del venditore completi')['status']);
        $this->assertStringStartsWith('NON PRONTO', GoLiveCheck::summary($checks->all()));
        $this->artisan('lamess:controlla')->assertFailed();
    }

    public function test_go_live_check_page_is_for_super_admin_only(): void
    {
        $this->actingAs(User::factory()->role('admin_finance')->create())->get('/admin/controllo-messa-online')->assertForbidden();
    }
}
