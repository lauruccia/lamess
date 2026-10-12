<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Access\Roles;
use App\Domain\Import\OdooHistoryImporter;
use App\Domain\Import\OdooImporter;
use App\Filament\Resources\ImportReconciliations\Pages\ListImportReconciliations;
use App\Models\ImportReconciliation;
use App\Models\User;
use Database\Seeders\PrelaunchPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class OdooHistoryImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('lamess:ruoli');
        $this->seed(PrelaunchPlanSeeder::class);
        $this->dir = sys_get_temp_dir().'/lamess-storico-'.uniqid();
        mkdir($this->dir);

        $cons = ['ref', 'nome', 'email', 'telefono', 'indirizzo', 'citta', 'cap', 'provincia', 'paese', 'codice_fiscale', 'tipo', 'sponsor_ref', 'sponsor_nome', 'stato', 'stato_odoo', 'kyc', 'dal', 'incarico', 'ingresso_rete', 'rank_odoo', 'rank_max_odoo', 'pr_carriera_odoo', 'attivita_mensile_odoo', 'fast_start', 'nota'];
        $this->csv('consulenti.csv', $cons, [
            ['LMS-B', 'Bruno Verdi', 'bruno@example.test', '', '', '', '', '', 'IT', '', 'private', '', '', 'active', 'Attivo', 'none', '2026-02-05 10:00:00', '', '', 'none', '', '0', '', '', ''],
        ]);
        $this->csv('clienti.csv', ['ref', 'nome', 'email', 'telefono', 'indirizzo', 'citta', 'cap', 'provincia', 'paese', 'codice_fiscale', 'tipo', 'consulente_ref', 'consulente_nome', 'registrato', 'stato_odoo'], [
            ['LMS-C1', 'Chiara Bianchi', 'chiara@example.test', '', '', '', '', '', 'IT', '', 'private', 'LMS-B', 'Bruno Verdi', '2026-04-01 12:00:00', ''],
            ['', 'Dino Senzaref', 'dino@example.test', '', '', '', '', '', 'IT', '', 'private', 'LMS-B', 'Bruno Verdi', '2026-04-02 12:00:00', ''],
        ]);
        $mv = ['rif_esterno', 'periodo', 'data', 'beneficiario_ref', 'beneficiario_nome', 'tipo', 'pv', 'cv', 'escluso_bonus', 'ordine', 'fattura', 'prodotto', 'acquirente', 'manuale'];
        $this->csv('movimenti.csv', $mv, [
            ['K1 - BOX (Chiara)', '2026-09', '2026-09-30 00:00:00', 'LMS-B', 'Bruno Verdi', 'pvf', '100.00', '100.00', '0', 'K1', 'FT/2026/00001 (K1)', '1 BOX', 'Chiara', '0'],
            ['K5 - BOX (Dino)', '2026-09', '2026-09-30 00:00:00', 'LMS-B', 'Bruno Verdi', 'pvf', '100.00', '100.00', '0', 'K5', '', '1 BOX', 'Dino', '0'],
            ['K9 - BOX (Chiara)', '2026-09', '2026-09-30 00:00:00', 'LMS-B', 'Bruno Verdi', 'pvc', '50.00', '50.00', '0', 'K9', '', '1 BOX', 'Chiara', '0'],
        ]);
        $o = ['ref', 'data', 'cliente_nome', 'cliente_ref', 'sponsor_nome', 'sponsor_ref', 'azienda', 'pr', 'imponibile', 'imposte', 'totale', 'stato', 'stato_fattura', 'rif_cliente'];
        $this->csv('ordini.csv', $o, [
            ['K1', '2026-09-30 10:00:00', 'Chiara Bianchi', 'LMS-C1', 'Bruno Verdi', 'LMS-B', 'X', '100', '131.07', '28.83', '159.90', 'Ordine di vendita', 'Interamente fatturato', ''],
            ['K2', '2026-09-30 11:00:00', 'Sconosciuto Tizio', '', 'Bruno Verdi', 'LMS-B', 'X', '100', '100.00', '22.00', '122.00', 'Ordine di vendita', 'Interamente fatturato', ''],
            ['K3', '2026-09-30 12:00:00', 'Chiara Bianchi', 'LMS-C1', '', '', 'X', '0', '0', '0', '0', 'Preventivo', 'Nulla da fatturare', ''],
            ['K4', '2026-09-30 13:00:00', 'Chiara Bianchi', 'LMS-C1', '', '', 'X', '100', '100.00', '22.00', '122.00', 'Annullato', 'Nulla da fatturare', ''],
            ['K5', '2026-09-30 14:00:00', 'Dino Senzaref', '', 'Bruno Verdi', 'LMS-B', 'X', '100', '100.00', '22.00', '122.00', 'Ordine di vendita', 'Interamente fatturato', ''],
        ]);
        $f = ['numero', 'data', 'partner_nome', 'partner_ref', 'paese', 'codice_fiscale', 'pr', 'imponibile', 'imposta', 'totale', 'stato_sdi', 'stato_pagamento'];
        $this->csv('fatture.csv', $f, [
            ['RINV/2026/00001', '2026-10-02 00:00:00', 'Chiara Bianchi', 'LMS-C1', 'Italia', '', '100', '-131.07', '-28.83', '-159.90', '', 'Pagata'],
            ['FT/2026/00001', '2026-09-30 00:00:00', 'Chiara Bianchi', 'LMS-C1', 'Italia', 'BNCCHR80A41H501X', '100', '131.07', '28.83', '159.90', '', 'Pagata'],
            ['FT/2026/00005', '2026-09-30 00:00:00', 'Dino Senzaref', '', 'Italia', '', '100', '100.00', '22.00', '122.00', 'SdI Rifiutato', 'Pagata'],
            ['FT/2026/00077', '2026-09-01 00:00:00', 'Qualcuno Altro', '', 'Italia', '', '0', '50.00', '11.00', '61.00', '', 'Pagata'],
            ['DINV/2026/00001', '2026-09-02 00:00:00', 'Chiara Bianchi', 'LMS-C1', 'Italia', '', '0', '10.00', '2.20', '12.20', '', 'In pagamento'],
        ]);
        app(OdooImporter::class)->import($this->dir);
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

    public function test_imports_orders_invoices_credit_note_and_links_movements(): void
    {
        $r = app(OdooHistoryImporter::class)->import($this->dir);

        $this->assertSame(3, $r['counts']['ordini_creati'], 'K1, K4 (annullato), K5');
        $this->assertSame(1, $r['counts']['preventivi_saltati']);
        $this->assertSame(1, $r['counts']['ordini_senza_acquirente']);
        $this->assertSame(3, $r['counts']['fatture_create']);
        $this->assertSame(1, $r['counts']['fatture_non_importate']);
        $this->assertSame(1, $r['counts']['note_credito_create']);

        $k1 = DB::table('orders')->where('number', 'K1')->first();
        $this->assertSame('odoo', $k1->source);
        $this->assertSame('refunded', $k1->status);
        $this->assertSame('customer', $k1->buyer_type);
        $this->assertEquals(100, $k1->pv_total);
        $this->assertSame('cancelled', DB::table('orders')->where('number', 'K4')->value('status'));

        $inv = DB::table('invoices')->where('number', 'FT/2026/00001')->first();
        $this->assertSame($k1->id, $inv->order_id);
        $this->assertSame('sent', $inv->sink_status, 'lo storico non va rimandato al gestionale');
        $this->assertSame('odoo', $inv->sink_ref);
        $this->assertEquals(159.90, $inv->total);
        $this->assertNull(DB::table('invoices')->where('number', 'FT/2026/00077')->value('order_id'));
        $this->assertSame(1, DB::table('payments')->where('order_id', $k1->id)->where('status', 'paid')->count());

        $cn = DB::table('credit_notes')->where('number', 'RINV/2026/00001')->first();
        $this->assertSame($inv->id, $cn->invoice_id);
        $this->assertEquals(159.90, $cn->total);

        // movimenti agganciati agli ordini, PV invariati
        $this->assertSame($k1->id, DB::table('pv_movements')->where('external_ref', 'K1 - BOX (Chiara)')->value('order_id'));
        $this->assertNull(DB::table('pv_movements')->where('external_ref', 'K9 - BOX (Chiara)')->value('order_id'));
        $this->assertEquals(250, DB::table('pv_movements')->sum('pv'));

        $codes = DB::table('import_reconciliations')->pluck('subject_ref', 'code');
        $this->assertSame('K2', $codes['ordine_senza_acquirente']);
        $this->assertSame('FT/2026/00077', $codes['fattura_senza_ordine']);
        $this->assertSame('FT/2026/00005', $codes['fattura_sdi_rifiutata']);
        $this->assertSame('K9', $codes['movimento_ordine_mancante']);
        $this->assertSame('DINV/2026/00001', $codes['fattura_bozza_non_importata']);
        $this->assertArrayHasKey('nota_credito_collegata', $codes->all());
    }

    public function test_dry_run_writes_nothing_and_rerun_is_idempotent(): void
    {
        app(OdooHistoryImporter::class)->import($this->dir, true);
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('import_reconciliations')->count());
        $this->assertNull(DB::table('products')->where('sku', 'STORICO-ODOO')->first());

        app(OdooHistoryImporter::class)->import($this->dir);
        $before = [DB::table('orders')->count(), DB::table('invoices')->count(), DB::table('credit_notes')->count(), DB::table('payments')->count(), DB::table('import_reconciliations')->count()];
        $r = app(OdooHistoryImporter::class)->import($this->dir);

        $this->assertSame(0, $r['counts']['ordini_creati']);
        $this->assertSame(0, $r['counts']['fatture_create']);
        $this->assertSame($before, [DB::table('orders')->count(), DB::table('invoices')->count(), DB::table('credit_notes')->count(), DB::table('payments')->count(), DB::table('import_reconciliations')->count()]);
    }

    public function test_accepted_difference_survives_rerun_and_admin_can_approve(): void
    {
        app(OdooHistoryImporter::class)->import($this->dir);
        $admin = User::factory()->role(Roles::SUPER_ADMIN)->create();
        $this->actingAs($admin);
        $row = ImportReconciliation::query()->where('code', 'fattura_sdi_rifiutata')->firstOrFail();

        Livewire::test(ListImportReconciliations::class)
            ->callTableAction('accept', $row, ['note' => 'Riemessa il 3/10'])
            ->assertHasNoTableActionErrors();

        $row->refresh();
        $this->assertSame('accepted', $row->status);
        $this->assertSame('Riemessa il 3/10', $row->resolution_note);
        $this->assertSame($admin->id, $row->resolved_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'import.reconciliation_accepted']);

        app(OdooHistoryImporter::class)->import($this->dir);
        $this->assertSame('accepted', $row->fresh()->status, 'un nuovo import non riapre le differenze approvate');
    }

    public function test_rejects_missing_files(): void
    {
        unlink($this->dir.'/ordini.csv');
        $this->expectException(\InvalidArgumentException::class);
        app(OdooHistoryImporter::class)->import($this->dir);
    }
}
