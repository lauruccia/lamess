<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\OdooAnagraficaImporter;
use App\Models\Consultant;
use App\Models\ImportReconciliation;
use App\Models\IvdContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NetworkDb;
use Tests\TestCase;

final class OdooAnagraficaImporterTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private string $dir;

    private const HEAD = ['ref', 'nome', 'email', 'firma', 'richiesta_firma', 'kyc', 'kyc_flag_odoo', 'kyc_rifiuto', 'documenti', 'acc_dati', 'acc_privacy', 'acc_fiscali', 'rank', 'sponsor_ref', 'sponsor_nome'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/lamess-anagrafica-'.uniqid();
        mkdir($this->dir);
    }

    private function csv(array $rows): void
    {
        $h = fopen($this->dir.'/anagrafica_odoo.csv', 'wb');
        fputcsv($h, self::HEAD, ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($h, $r, ';', '"', '');
        }
        fclose($h);
    }

    public function test_registers_historic_signature_aligns_kyc_and_reports_differences_without_overwriting(): void
    {
        $this->plan();
        $sponsor = $this->consultant('LM00001');
        $sponsor->update(['referral_code' => 'REF-S']);
        $a = $this->consultant('LM00002', $sponsor);
        $a->update(['referral_code' => 'REF-A', 'kyc_status' => 'none', 'user_id' => \App\Models\User::factory()->create()->id]);
        $b = $this->consultant('LM00003');
        $b->update(['referral_code' => 'REF-B', 'kyc_status' => 'submitted']);
        $this->csv([
            ['REF-A', 'A', '', '2026-08-01 12:00:00', '71', 'verified', '1', '', '2', '1', '1', '1', 'none', 'REF-S', 'S'],
            ['REF-B', 'B', '', '', '55', 'verified', '1', '', '0', '0', '0', '0', 'none', 'REF-ALTRO', 'Altro'],
            ['REF-CLIENTE', 'Solo cliente', '', '', '', 'none', '0', '', '', '0', '0', '0', 'none', '', ''],
        ]);

        $r = app(OdooAnagraficaImporter::class)->import($this->dir);

        $this->assertSame(1, $r['counts']['firme_registrate']);
        $this->assertSame(1, $r['counts']['firme_in_sospeso']);
        $this->assertSame(1, $r['counts']['solo_clienti']);
        $contract = IvdContract::where('consultant_id', $a->id)->firstOrFail();
        $this->assertSame('accepted', $contract->status);
        $this->assertSame('odoo', $contract->signature_provider);
        $this->assertNull($contract->expires_at, 'nessuna scadenza: non scatta il canone');
        $this->assertSame('verified', $a->refresh()->kyc_status);
        $this->assertSame('submitted', $b->refresh()->kyc_status, 'un KYC già impostato nel portale non si sovrascrive');
        $this->assertNull($b->sponsor_id);
        $this->assertTrue(ImportReconciliation::where('code', 'kyc_diverso')->where('subject_ref', 'REF-B')->exists());
        $this->assertTrue(ImportReconciliation::where('code', 'sponsor_diverso')->where('subject_ref', 'REF-B')->exists());
        $this->assertFalse(ImportReconciliation::where('subject_ref', 'REF-A')->where('code', 'sponsor_diverso')->exists());

        // rieseguita: nessun duplicato, e ciò che l'amministrazione ha chiuso resta chiuso
        ImportReconciliation::where('code', 'kyc_diverso')->update(['status' => 'resolved']);
        $again = app(OdooAnagraficaImporter::class)->import($this->dir);
        $this->assertSame(0, $again['counts']['firme_registrate']);
        $this->assertSame(1, IvdContract::where('consultant_id', $a->id)->count());
        $this->assertSame('resolved', ImportReconciliation::where('code', 'kyc_diverso')->value('status'));
    }

    public function test_dry_run_writes_nothing_and_legacy_signature_has_no_certificate(): void
    {
        $this->plan();
        $a = $this->consultant('LM00002');
        $a->update(['referral_code' => 'REF-A', 'user_id' => \App\Models\User::factory()->create()->id]);
        $this->csv([['REF-A', 'A', '', '2026-08-01 12:00:00', '', 'none', '0', '', '', '1', '1', '1', 'none', '', '']]);
        app(OdooAnagraficaImporter::class)->import($this->dir, true);
        $this->assertSame(0, IvdContract::count());

        app(OdooAnagraficaImporter::class)->import($this->dir);
        $c = IvdContract::firstOrFail();
        $this->actingAs(\App\Models\User::factory()->role()->create())->get("/admin-files/contract/{$c->id}/certificate")->assertForbidden();
    }
}
