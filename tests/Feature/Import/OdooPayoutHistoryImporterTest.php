<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\OdooPayoutHistoryImporter;
use App\Domain\Payouts\FiscalCalculator;
use App\Models\CommissionReceipt;
use App\Models\ImportReconciliation;
use App\Models\Payout;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NetworkDb;
use Tests\TestCase;

final class OdooPayoutHistoryImporterTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private string $dir;

    private const HEAD = ['ref', 'nome', 'richiesta', 'lordo', 'tracciato', 'non_tracciato', 'netto', 'stato', 'revisionato_da', 'costo_amm', 'liquidazione', 'autofattura', 'fattura_stato', 'fattura_data'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/lamess-prelievi-'.uniqid();
        mkdir($this->dir);
    }

    private function csv(array $rows): void
    {
        $h = fopen($this->dir.'/prelievi.csv', 'wb');
        fputcsv($h, self::HEAD, ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($h, $r, ';', '"', '');
        }
        fclose($h);
    }

    public function test_odoo_net_is_reproduced_by_the_calculator_on_every_historical_withdrawal(): void
    {
        $lines = array_map(fn ($l) => str_getcsv($l, ';', '"', ''), array_slice(file(base_path('tests/Fixtures/odoo/prelievi_netto.csv'), FILE_IGNORE_NEW_LINES), 1));
        $this->assertCount(45, $lines);
        foreach ($lines as [$gross, $fee, $net]) {
            $r = FiscalCalculator::compute('occasional', $gross, '0', $fee, FiscalCalculator::ODOO_PARAMS);
            $this->assertSame($net, $r['net'], "lordo {$gross}");
        }
        $this->assertSame('818.10', FiscalCalculator::compute('occasional', '1000', '0', '2.50', FiscalCalculator::ODOO_PARAMS)['net']);
    }

    public function test_imports_requests_payouts_and_receipts_without_touching_the_wallet(): void
    {
        $this->plan();
        $this->openPeriod('2026-09');
        $c = $this->consultant('LM00001');
        $c->update(['referral_code' => 'REF-A', 'iban' => 'IT60X0542811101000000123456', 'iban_holder' => 'Incaricato LM00001']);
        $this->csv([
            ['REF-A', 'A', '2026-09-16 17:04:38', '75.00', '75.00', '0.00', '59.04', 'Approvato', 'Admin', '2.50', '', 'COMM00001/2026/09/0001', 'Pagata', '2026-09-16 00:00:00'], // pagata
            ['REF-A', 'A', '2026-09-20 10:00:00', '103.50', '76.50', '27.00', '82.43', 'Liquidato', 'Admin', '2.50', '2026-09-22 10:00:00', 'COMM00001/2026/09/0002', 'Pagata', '2026-09-22 00:00:00'], // non tracciato
            ['REF-A', 'A', '2026-09-25 10:00:00', '18.00', '18.00', '0.00', '12.27', 'Richiesto', '', '2.50', '', '', '', ''],
            ['REF-A', 'A', '2026-09-26 10:00:00', '500.00', '0.00', '500.00', '409.05', 'Rifiutato', 'Admin', '2.50', '', '', '', ''],
            ['REF-A', 'A', '2026-09-27 10:00:00', '40.00', '40.00', '0.00', '99.99', 'Approvato', 'Admin', '2.50', '', '', 'Confermata', '2026-09-27 00:00:00'], // netto diverso
            ['REF-X', 'Sconosciuto', '2026-09-28 10:00:00', '40.00', '40.00', '0.00', '30.32', 'Richiesto', '', '2.50', '', '', '', ''],
        ]);

        $r = app(OdooPayoutHistoryImporter::class)->import($this->dir);

        $this->assertSame(5, $r['counts']['prelievi_creati']);
        $this->assertSame(2, $r['counts']['pagamenti_creati']);
        $this->assertSame(2, $r['counts']['ricevute_create']);
        $this->assertSame(1, $r['counts']['prelievi_rifiutati']);
        $this->assertSame(2, $r['counts']['prelievi_aperti']);
        $paid = WithdrawalRequest::where('status', 'paid')->get();
        $this->assertCount(2, $paid);
        $p = Payout::where('external_ref', 'like', 'odoo:%')->orderBy('id')->first();
        $this->assertSame('75.00', (string) $p->gross_amount);
        $this->assertSame('13.46', (string) $p->withholding_amount);
        $this->assertSame('59.04', (string) $p->amount);
        $this->assertSame('COMM00001/2026/09/0001', CommissionReceipt::orderBy('id')->first()->number);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('wallet_transactions')->count(), 'il wallet non si tocca');
        $codes = ImportReconciliation::pluck('code')->all();
        $this->assertContains('prelievo_importo_non_tracciato', $codes);
        $this->assertContains('prelievo_senza_incaricato', $codes);
        $this->assertContains('prelievo_netto_diverso', $codes);
        $this->assertContains('prelievo_stato_da_fattura', $codes);
    }

    public function test_import_is_idempotent_and_dry_run_writes_nothing(): void
    {
        $this->plan();
        $this->openPeriod('2026-09');
        $this->consultant('LM00001')->update(['referral_code' => 'REF-A']);
        $this->csv([['REF-A', 'A', '2026-09-16 17:04:38', '75.00', '75.00', '0.00', '59.04', 'Liquidato', 'Admin', '2.50', '2026-09-18 10:00:00', 'COMM00001/2026/09/0001', 'Pagata', '2026-09-18 00:00:00']]);
        $svc = app(OdooPayoutHistoryImporter::class);

        $svc->import($this->dir, true);
        $this->assertSame(0, WithdrawalRequest::count());
        $svc->import($this->dir);
        $again = $svc->import($this->dir);
        $this->assertSame(1, WithdrawalRequest::count());
        $this->assertSame(1, Payout::count());
        $this->assertSame(1, $again['counts']['prelievi_gia_presenti']);
    }

    public function test_missing_period_still_imports_the_request(): void
    {
        $this->plan();
        $this->consultant('LM00001')->update(['referral_code' => 'REF-A']);
        $this->csv([['REF-A', 'A', '2026-09-16 17:04:38', '75.00', '75.00', '0.00', '59.04', 'Liquidato', 'Admin', '2.50', '2026-09-18 10:00:00', '', '', '']]);

        $r = app(OdooPayoutHistoryImporter::class)->import($this->dir);
        $this->assertSame(1, WithdrawalRequest::count());
        $this->assertSame(0, Payout::count());
        $this->assertSame(['prelievo_periodo_mancante' => 1], $r['issues']);
    }
}
