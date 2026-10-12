<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Access\Roles;
use App\Domain\Audit\AuditLog;
use App\Domain\Settings\SystemSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_overrides_config_and_is_audited(): void
    {
        $changes = SystemSettings::update(['reenrollment_days' => 90], 'prova');

        $this->assertSame(90, config('lamess.reenrollment_days'));
        $this->assertSame(90, $changes['reenrollment_days']['dopo']);
        $row = DB::table('audit_logs')->where('action', 'settings.updated')->first();
        $this->assertNotNull($row);
        $this->assertSame('prova', $row->reason);
        $this->assertTrue(AuditLog::verify()->ok);
    }

    public function test_unchanged_value_writes_nothing(): void
    {
        SystemSettings::update(['reenrollment_days' => 90]);
        $n = DB::table('audit_logs')->count();
        $this->assertSame([], SystemSettings::update(['reenrollment_days' => 90]));
        $this->assertSame($n, DB::table('audit_logs')->count());
    }

    public function test_invalid_value_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        SystemSettings::update(['annual_fee_amount' => 'abc']);
    }

    public function test_unknown_key_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SystemSettings::update(['app_key' => 'x']);
    }

    public function test_security_settings_are_not_editable(): void
    {
        foreach (['admin_2fa_required', 'app_key', 'queue_connection', 'session_driver'] as $k) {
            $this->assertArrayNotHasKey($k, SystemSettings::DEFINITIONS);
        }
    }

    public function test_apply_to_config_after_reboot(): void
    {
        SystemSettings::update(['seller_name' => 'Lamèss Srl']);
        config(['lamess.seller.name' => 'altro']);
        SystemSettings::applyToConfig();
        $this->assertSame('Lamèss Srl', config('lamess.seller.name'));
    }

    public function test_reset_returns_to_file_value_and_is_audited(): void
    {
        SystemSettings::update(['reenrollment_days' => 90]);
        SystemSettings::reset('reenrollment_days');
        $this->assertFalse(SystemSettings::overridden('reenrollment_days'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'settings.reset')->count());
    }

    public function test_admin_cannot_reach_settings_page(): void
    {
        $admin = User::factory()->role(Roles::ADMIN)->create();
        $this->actingAs($admin)->get('/admin/impostazioni')->assertForbidden();
    }
}
