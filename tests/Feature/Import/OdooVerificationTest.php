<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\OdooImporter;
use App\Domain\Import\OdooVerification;
use App\Domain\Periods\PeriodService;
use App\Models\Period;
use Database\Seeders\PrelaunchPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/** Fase 5: confronto del motore con i numeri di Odoo. */
final class OdooVerificationTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('lamess:ruoli');
        $this->seed(PrelaunchPlanSeeder::class);
        $this->dir = sys_get_temp_dir().'/lamess-verifica-'.uniqid();
        mkdir($this->dir);
        $head = ['ref', 'nome', 'email', 'telefono', 'indirizzo', 'citta', 'cap', 'provincia', 'paese', 'codice_fiscale', 'tipo', 'sponsor_ref', 'sponsor_nome', 'stato', 'stato_odoo', 'kyc', 'dal', 'incarico', 'ingresso_rete', 'rank_odoo', 'rank_max_odoo', 'pr_carriera_odoo', 'attivita_mensile_odoo', 'fast_start', 'nota'];
        $row = fn (array $x) => array_replace(array_fill_keys($head, ''), ['paese' => 'IT', 'tipo' => 'private', 'stato' => 'active', 'stato_odoo' => 'Attivo', 'kyc' => 'none', 'dal' => '2026-08-01 10:00:00'], $x);
        $this->csv('consulenti.csv', $head, [
            $row(['ref' => 'LMS-A', 'nome' => 'Anna Rossi', 'email' => 'a@example.test', 'rank_odoo' => '', 'pr_carriera_odoo' => '300']),
            $row(['ref' => 'LMS-B', 'nome' => 'Bruno Verdi', 'email' => 'b@example.test', 'sponsor_ref' => 'LMS-A', 'sponsor_nome' => 'Anna Rossi', 'rank_odoo' => 'pearl', 'pr_carriera_odoo' => '600']),
        ]);
        $this->csv('clienti.csv', ['ref', 'nome', 'email', 'telefono', 'indirizzo', 'citta', 'cap', 'provincia', 'paese', 'codice_fiscale', 'tipo', 'consulente_ref', 'consulente_nome', 'registrato', 'stato_odoo'], []);
        $this->csv('movimenti.csv', ['rif_esterno', 'periodo', 'data', 'beneficiario_ref', 'beneficiario_nome', 'tipo', 'pv', 'cv', 'escluso_bonus', 'ordine', 'fattura', 'prodotto', 'acquirente', 'manuale'], [
            ['K1', '2026-09', '2026-09-10 00:00:00', 'LMS-A', 'Anna Rossi', 'pvf', '300.00', '300.00', '0', 'K1', '', 'BOX', 'X', '0'],
            ['K2', '2026-09', '2026-09-11 00:00:00', 'LMS-B', 'Bruno Verdi', 'pvf', '100.00', '100.00', '0', 'K2', '', 'BOX', 'Y', '0'],
        ]);
        app(OdooImporter::class)->import($this->dir);
        app(PeriodService::class)->calculate(Period::where('code', '2026-09')->firstOrFail());
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @param list<string> $head @param list<list<string>> $rows */
    private function csv(string $file, array $head, array $rows): void
    {
        $h = fopen($this->dir.'/'.$file, 'wb');
        fputcsv($h, $head, ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($h, $r, ';', '"', '');
        }
        fclose($h);
    }

    public function test_reports_equal_and_different_values(): void
    {
        $r = app(OdooVerification::class)->compare($this->dir, '2026-09');

        $this->assertSame(2, $r['compared']);
        $this->assertSame(1, $r['rank_equal'], 'Anna: nessuna qualifica = CONSULTANT, uguale');
        $this->assertSame('LMS-B', $r['rank_diff'][0]['ref']);
        $this->assertSame('PEARL', $r['rank_diff'][0]['odoo']);
        $this->assertSame(0, $r['pr_equal'], 'Anna: 400 PR di gruppo contro 300 di Odoo; Bruno: 100 contro 600');
        $this->assertEquals(-300, $r['pr_diff'][0]['personali_odoo_impliciti'], 'PR di Anna in Odoo (300) meno quelli del diretto Bruno (600)');
    }

    public function test_differences_land_in_reconciliation_once(): void
    {
        app(OdooVerification::class)->compare($this->dir, '2026-09');
        app(OdooVerification::class)->compare($this->dir, '2026-09');
        $this->assertSame(1, DB::table('import_reconciliations')->where('code', 'verifica_rank_diverso')->count());
        $this->assertGreaterThanOrEqual(1, DB::table('import_reconciliations')->where('code', 'verifica_pr_diverso')->count());

        app(OdooVerification::class)->compare($this->dir, '2026-09', true);
        $this->assertSame(1, DB::table('import_reconciliations')->where('code', 'verifica_rank_diverso')->count());
    }

    public function test_bonus_totals_are_compared_with_commission_period_export(): void
    {
        $direct = (float) DB::table('commission_lines')->where('bonus_type', 'direct')->sum('amount');
        $this->assertGreaterThan(0, $direct);
        $h = fopen($this->dir.'/commission_period.csv', 'wb');
        fputcsv($h, ['periodo', 'stato', 'direct', 'team', 'matching', 'rank', 'fast_start', 'totale', 'fatturato'], ';', '"', '');
        fputcsv($h, ['2026-09', 'Open', number_format($direct, 2, '.', ''), '0.00', '0.00', '99.00', '0.00', '0', '0'], ';', '"', '');
        fclose($h);

        $r = app(OdooVerification::class)->compareBonuses($this->dir, '2026-09');
        $byType = array_column($r['rows'], null, 'type');
        $this->assertEquals(0.0, $byType['direct']['diff']);
        $this->assertEquals(-99.0, $byType['rank']['diff'], 'Odoo stima 99 € di Rank Bonus che il portale non calcola');
        $this->assertSame(1, DB::table('import_reconciliations')->where('code', 'verifica_bonus_diverso')->count());

        app(OdooVerification::class)->compareBonuses($this->dir, '2026-09');
        $this->assertSame(1, DB::table('import_reconciliations')->where('code', 'verifica_bonus_diverso')->count(), 'nessun doppione');
    }

    public function test_period_without_calculation_is_refused(): void
    {
        app(PeriodService::class)->open('2026-08', Period::firstOrFail()->planVersion);
        $this->expectException(InvalidArgumentException::class);
        app(OdooVerification::class)->compare($this->dir, '2026-08');
    }
}
