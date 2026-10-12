<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\OdooImporter;
use Database\Seeders\PrelaunchPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class OdooImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('lamess:ruoli');
        $this->seed(PrelaunchPlanSeeder::class);
        $this->dir = sys_get_temp_dir().'/lamess-import-'.uniqid();
        mkdir($this->dir);

        $cons = ['ref', 'nome', 'email', 'telefono', 'indirizzo', 'citta', 'cap', 'provincia', 'paese', 'codice_fiscale', 'tipo', 'sponsor_ref', 'sponsor_nome', 'stato', 'stato_odoo', 'kyc', 'dal', 'incarico', 'ingresso_rete', 'rank_odoo', 'rank_max_odoo', 'pr_carriera_odoo', 'attivita_mensile_odoo', 'fast_start', 'nota'];
        $this->csv('consulenti.csv', $cons, [
            ['LMS-A', 'Anna Rossi', 'anna@example.test', '+39 333 1', 'Via Roma 1', 'Roma', '00100', 'RM', 'IT', 'RSSNNA80A41H501X', 'private', '', '', 'active', 'Attivo', 'verified', '2026-01-10 09:00:00', '2026-01-10 09:00:00', '', 'none', '', '0', '', '', ''],
            ['LMS-B', 'Bruno Verdi', 'anna@example.test', '', '', '', '', '', 'IT', '', 'private', 'LMS-A', 'Anna Rossi', 'active', 'Attivo', 'none', '2026-02-05 10:00:00', '', '', 'none', '', '0', '', '', ''],
            ['', 'Carla Neri', 'carla@example.test', '', '', '', '', '', 'IT', '', 'private', 'LMS-B', 'Bruno Verdi', 'suspended', 'Sospeso', 'none', '2026-03-01 10:00:00', '', '', 'none', '', '0', '', '', ''],
            ['LMS-D', 'Dario Test', 'dario@example.test', '', '', '', '', '', 'IT', '', 'private', 'LMS-X', 'Sconosciuto', 'active', 'Attivo', 'none', '2026-03-02 10:00:00', '', '', 'none', '', '0', '', '', 'sponsor Sconosciuto non presente nei dati: importato senza sponsor'],
        ]);
        $this->csv('clienti.csv', ['ref', 'nome', 'email', 'telefono', 'indirizzo', 'citta', 'cap', 'provincia', 'paese', 'codice_fiscale', 'tipo', 'consulente_ref', 'consulente_nome', 'registrato', 'stato_odoo'], [
            ['LMS-C1', 'Chiara Bianchi', 'chiara@example.test', '', '', '', '', '', 'IT', '', 'private', 'LMS-B', 'Bruno Verdi', '2026-04-01 12:00:00', ''],
            ['', 'Senza Mail', '', '', '', '', '', '', 'NL', '', 'private', '', '', '2026-04-02 12:00:00', ''],
        ]);
        $mv = ['rif_esterno', 'periodo', 'data', 'beneficiario_ref', 'beneficiario_nome', 'tipo', 'pv', 'cv', 'escluso_bonus', 'ordine', 'fattura', 'prodotto', 'acquirente', 'manuale'];
        $this->csv('movimenti.csv', $mv, [
            ['K1 - BOX (Chiara)', '2026-09', '2026-09-30 00:00:00', 'LMS-B', 'Bruno Verdi', 'pvf', '100.00', '100.00', '0', 'K1', 'FT/2026/00001', 'BOX', 'Chiara', '0'],
            ['K1 - BOX (Chiara) #2', '2026-09', '2026-09-30 00:00:00', 'LMS-B', 'Bruno Verdi', 'pvc', '50.00', '50.00', '1', 'K1', 'FT/2026/00001', 'BOX', 'Chiara', '0'],
            ['', '2026-10', '2026-10-01 00:00:00', '', 'Carla Neri', 'pvf', '400.00', '400.00', '1', '', '2026/pri1', '', 'Rettifica manuale M3', '1'],
        ]);
        file_put_contents($this->dir.'/contatori.csv', "chiave;valore\nFT-2026;234\n");
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

    public function test_imports_people_tree_movements_and_counters(): void
    {
        $r = app(OdooImporter::class)->import($this->dir)['counts'];

        $this->assertSame(4, $r['consulenti_creati']);
        $this->assertSame(2, $r['clienti_creati']);
        $this->assertSame(3, $r['movimenti_creati']);
        $this->assertSame(2, $r['periodi_aperti']);
        $this->assertSame(2, $r['email_segnaposto'] + $r['email_duplicate'], 'una email duplicata (Bruno), una mancante (cliente)');
        $this->assertSame(2, $r['consulenti_senza_sponsor'], 'Anna (radice) e Dario hanno sponsor nullo');

        // albero: Anna → Bruno → Carla; closure con profondità
        $anna = DB::table('consultants')->where('referral_code', 'LMS-A')->first();
        $bruno = DB::table('consultants')->where('referral_code', 'LMS-B')->first();
        $carla = DB::table('consultants')->where('name', 'Carla Neri')->first();
        $this->assertSame($anna->id, $bruno->sponsor_id);
        $this->assertSame($bruno->id, $carla->sponsor_id);
        $this->assertSame(2, (int) DB::table('network_paths')->where('ancestor_id', $anna->id)->where('descendant_id', $carla->id)->value('depth'));
        $this->assertSame('suspended', $carla->status);
        $this->assertSame('LM00001', $anna->code);
        $this->assertSame('LM00004', DB::table('consultants')->where('referral_code', 'LMS-D')->value('code'));

        // email: nessun duplicato, nessun dominio reale per i segnaposto
        $this->assertSame(0, DB::table('users')->select('email')->groupBy('email')->havingRaw('count(*) > 1')->count());
        $this->assertSame(2, DB::table('users')->where('email', 'like', '%@import.lamess.invalid')->count());

        // clienti e ruoli
        $this->assertSame($bruno->id, DB::table('customers')->join('users', 'users.id', '=', 'customers.user_id')->where('users.email', 'chiara@example.test')->value('assigned_consultant_id'));
        $this->assertSame(4, DB::table('model_has_roles')->where('role_id', DB::table('roles')->where('name', 'consultant')->value('id'))->count());

        // movimenti: dedotti per periodo, beneficiario anche per nome (incaricato senza codice)
        $this->assertSame(2, DB::table('pv_movements')->where('period_id', DB::table('periods')->where('code', '2026-09')->value('id'))->count());
        $manual = DB::table('pv_movements')->where('external_ref', 'like', 'odoo-manuale:%')->first();
        $this->assertSame($carla->id, $manual->consultant_id);
        $this->assertSame('migration', $manual->type);
        $this->assertSame(1, (int) $manual->excluded_from_bonus);
        $this->assertNotNull($manual->amount); $this->assertEquals(0, $manual->amount, 'rettifica manuale = punti di carriera, non vendita: nessuna conversione in euro');
        $this->assertNull(DB::table('pv_movements')->where('external_ref', 'not like', 'odoo-manuale:%')->value('amount'), 'i movimenti di vendita restano senza importo');
        $this->assertSame('2026-10-01 10:00:00', substr((string) $manual->event_at, 0, 19), 'mezzogiorno di Roma (UTC+2 a ottobre)');

        $this->assertSame(234, (int) DB::table('document_counters')->where('key', 'FT-2026')->value('value'));
    }

    public function test_is_idempotent_and_dry_run_writes_nothing(): void
    {
        app(OdooImporter::class)->import($this->dir, true);
        $this->assertSame(0, DB::table('consultants')->count());
        $this->assertSame(0, DB::table('pv_movements')->count());

        app(OdooImporter::class)->import($this->dir);
        $second = app(OdooImporter::class)->import($this->dir)['counts'];

        $this->assertSame(0, $second['consulenti_creati']);
        $this->assertSame(0, $second['clienti_creati']);
        $this->assertSame(0, $second['movimenti_creati']);
        $this->assertSame(3, $second['movimenti_gia_presenti']);
        $this->assertSame(4, DB::table('consultants')->count());
        $this->assertSame(3, DB::table('pv_movements')->count());
    }

    public function test_emails_are_blocked_unless_enabled(): void
    {
        $this->assertFalse(config('lamess.mail_enabled'));
        $sent = 0;
        Event::listen(MessageSent::class, function () use (&$sent): void {
            $sent++;
        });

        Mail::raw('prova', fn ($m) => $m->to('chiunque@example.com')->subject('x'));

        $this->assertSame(0, $sent, 'con lamess.mail_enabled=false nessun messaggio deve arrivare al trasporto');
    }
}
