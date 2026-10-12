<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Access\Roles;
use App\Domain\Career\CareerProgress;
use App\Domain\Network\NetworkService;
use App\Filament\Pages\NetworkTreePage;
use App\Filament\Resources\Consultants\Pages\ViewConsultant;
use App\Models\Consultant;
use App\Models\Period;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** «Cosa manca per il livello successivo»: PR, struttura, attività e bonus, per incaricato. */
final class CareerProgressTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    /** @var array<string, Consultant> */
    private array $c = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $this->plan();
        $p = $this->openPeriod('2026-10');
        // R ─ A ─ B ; R ─ X
        $this->c['R'] = $this->consultant('R');
        $this->c['A'] = $this->consultant('A', $this->c['R']);
        $this->c['B'] = $this->consultant('B', $this->c['A']);
        $this->c['X'] = $this->consultant('X', $this->c['R'], null, '2026-08-01 08:00');
        app(NetworkService::class)->rebuildClosure();
        $this->sale($p, $this->c['A'], '200');
        $this->sale($p, $this->c['B'], '150', '2026-10-11 10:00');
    }

    private function progress(string $k): array
    {
        return app(CareerProgress::class)->forConsultant($this->c[$k]->id);
    }

    public function test_missing_pr_is_required_minus_counted(): void
    {
        $r = $this->progress('A'); // PR di gruppo: 200 + 150
        $this->assertSame('CONSULTANT', $r['rank']['code']);
        $this->assertSame('PEARL', $r['next']['code']);
        $this->assertEquals(600, $r['pr']['required']);
        $this->assertEquals(350, $r['pr']['counted']);
        $this->assertEquals(250, $r['pr']['missing']);
        $this->assertSame(58, $r['pr']['percent']);
        $this->assertStringContainsString('ti mancano 250 PR di gruppo', implode(' ', $r['todo']));
        $this->assertTrue($r['activity']['active']);
    }

    public function test_leaf_with_no_sales_is_inactive_and_told_how_many_pr_are_missing(): void
    {
        $r = $this->progress('X');
        $this->assertFalse($r['activity']['active']);
        $this->assertEquals(100, $r['activity']['missing']);
        $this->assertStringContainsString('ti mancano 100 PR', implode(' ', $r['todo']));
    }

    public function test_pearl_unlocks_indirect_and_next_rank_bonus_is_announced(): void
    {
        $r = $this->progress('R');
        $this->assertFalse($r['gates']['indirect']['reached']);
        $this->assertStringContainsString('Con Pearl si sblocca: Bonus Indiretti', implode(' ', $r['todo']));
        $this->assertSame(0.0, (float) $r['next']['rank_bonus']);
    }

    public function test_structure_requirement_is_listed_when_missing(): void
    {
        $this->consultant('P', null, 'PEARL'); // un Pearl esiste altrove nella rete, non nella linea di A
        $this->sale($this->openedPeriod(), $this->c['A'], '2300', '2026-10-12 10:00'); // PR ok per Crystal ma niente linea Pearl
        $r = app(CareerProgress::class);
        $fresh = new CareerProgress;
        $a = $fresh->forConsultant($this->c['A']->id);
        $this->assertSame('PEARL', $a['rank']['code'], 'A ha già i PR per Pearl');
        $this->assertSame('CRYSTAL', $a['next']['code']);
        $this->assertSame(['Pearl'], $a['structure']['required']);
        $this->assertSame(['Pearl'], $a['structure']['missing']);
        $this->assertStringContainsString('mancano 1 linea Pearl', implode(' ', $a['todo']));
        $this->assertNotNull($r);
    }

    private function openedPeriod(): Period
    {
        return Period::query()->where('code', '2026-10')->firstOrFail();
    }

    public function test_admin_consultant_page_and_tree_show_the_progress(): void
    {
        Livewire::test(ViewConsultant::class, ['record' => $this->c['A']->getKey()])
            ->assertSee('Riepilogo')->assertSee('Pearl')->assertSee('Mancano');

        $html = Livewire::test(NetworkTreePage::class)->instance()->progressHtml($this->c['A']->id);
        $this->assertStringContainsString('Cosa fare', $html);
        $nodes = collect(Livewire::test(NetworkTreePage::class)->instance()->treeData()['nodes'])->keyBy('id');
        $this->assertSame(58, $nodes[$this->c['A']->id]['pct']);
        $this->assertSame('Pearl', $nodes[$this->c['A']->id]['next']);
    }

    public function test_progress_detail_requires_network_permission(): void
    {
        $nobody = User::factory()->role(Roles::ADMIN)->create(); // admin senza permessi concessi
        $this->actingAs($nobody);
        $this->expectException(HttpException::class);
        (new NetworkTreePage)->progressHtml($this->c['A']->id);
    }

    public function test_consultant_sees_own_progress_in_area(): void
    {
        $u = User::factory()->create();
        $this->c['A']->update(['user_id' => $u->id]);
        $this->actingAs($u)->get('/area')->assertOk()->assertSee('Il mio avanzamento')->assertSee('Cosa fare');
    }
}
