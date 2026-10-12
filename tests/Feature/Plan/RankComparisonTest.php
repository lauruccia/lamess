<?php

declare(strict_types=1);

namespace Tests\Feature\Plan;

use App\Domain\Access\Roles;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Plan\RankComparison;
use App\Filament\Pages\RankComparisonPage;
use App\Models\CommissionLine;
use App\Models\CompPlanVersion;
use App\Models\RankAchievement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Confronto qualifica precedente / piano Incaricati: solo lettura, solo admin con permesso e super_admin. */
final class RankComparisonTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private function incaricati(): CompPlanVersion
    {
        $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);

        return CompPlanVersion::createFromArray('Incaricati', $data);
    }

    public function test_only_super_admin_and_admins_with_the_permission_can_open_the_page(): void
    {
        Roles::sync();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $this->assertTrue(RankComparisonPage::canAccess());
        $this->actingAs(User::factory()->role('admin_compplan')->create());
        $this->assertTrue(RankComparisonPage::canAccess());
        foreach (['board', 'support', 'admin_finance'] as $role) {
            $this->actingAs(User::factory()->role($role)->create());
            $this->assertFalse(RankComparisonPage::canAccess(), $role);
        }
    }

    public function test_freeze_saves_the_current_rank_once_and_never_overwrites(): void
    {
        $this->plan();
        $a = $this->consultant('A', null, 'CRYSTAL');
        $b = $this->consultant('B', $a);
        $svc = app(RankComparison::class);

        $this->assertSame(2, $svc->freezePrevious());
        $this->assertSame('CRYSTAL', $a->refresh()->previous_rank_code);
        $this->assertSame('CONSULTANT', $b->refresh()->previous_rank_code);

        RankAchievement::create(['consultant_id' => $b->id, 'rank_code' => 'DIAMOND', 'achieved_at' => now(), 'effective_from' => '2026-10-01', 'source' => 'manual']);
        $this->assertSame(0, $svc->freezePrevious());
        $this->assertSame('CONSULTANT', $b->refresh()->previous_rank_code);
    }

    public function test_comparison_computes_the_new_rank_from_scratch_and_changes_nothing(): void
    {
        $this->plan();
        $version = $this->incaricati();
        $p = $this->openPeriod('2026-10');
        $a = $this->consultant('A', null, 'DIAMOND');
        foreach (['x1', 'x2', 'x3'] as $i => $c) {
            $this->sale($p, $a, '100', '2026-10-0'.(2 + $i).' 10:00');
        }
        app(RankComparison::class)->freezePrevious();
        $lines = CommissionLine::count();
        $ranks = RankAchievement::count();

        $rows = app(RankComparison::class)->compare($p, $version->plan());

        $this->assertCount(1, $rows);
        $this->assertSame('DIAMOND', $rows[0]['previous']);
        $this->assertSame('CONSULTANT', $rows[0]['new_achieved'], 'Movimenti senza cliente identificato: nessun cliente contato');
        $this->assertTrue($rows[0]['changed']);
        $this->assertSame($lines, CommissionLine::count());
        $this->assertSame($ranks, RankAchievement::count());
    }

    public function test_page_renders_for_super_admin(): void
    {
        $this->plan();
        $this->incaricati();
        $this->openPeriod('2026-10');
        $this->consultant('A', null, 'RUBY');
        app(RankComparison::class)->freezePrevious();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        Livewire::test(RankComparisonPage::class)->assertSee('RUBY')->assertSee('Sola lettura');
    }
}
