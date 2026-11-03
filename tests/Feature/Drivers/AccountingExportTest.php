<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use App\Domain\Billing\AccountingExport;
use App\Domain\Billing\InvoiceService;
use App\Domain\Orders\OrderService;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\AuditEntry;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ShopDb;
use Tests\TestCase;
use ZipArchive;

final class AccountingExportTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function paidOrder(array $billing = [], string $method = 'card'): Order
    {
        $this->plan();
        $this->shipping('5.00');
        $p = $this->product('A', '131.06557377');
        $q = $this->product('B', '10.00');
        $orders = app(OrderService::class);
        $o = $orders->place($this->buyer($this->activeSponsor()), [$p->id => 2, $q->id => 3], 'IT', $method, $this->billing($billing));

        return $orders->markPaid($o);
    }

    /** @return array{files: array<string, string>, csv: array<int, array<int, string>>} */
    private function open(string $binary): array
    {
        $path = tempnam(sys_get_temp_dir(), 'acc-test-');
        file_put_contents($path, $binary);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'ZIP valido');
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($path);
        $this->assertArrayHasKey('riepilogo.csv', $files);
        $csv = array_map(fn (string $l) => str_getcsv($l, ';', '"', ''), array_values(array_filter(explode("\r\n", substr($files['riepilogo.csv'], 3)))));

        return ['files' => $files, 'csv' => $csv];
    }

    public function test_zip_has_the_fatturapa_xml_a_summary_with_one_row_per_rate_and_a_readme(): void
    {
        $o = $this->paidOrder(['type' => 'company', 'company_name' => 'Acme S.r.l.', 'vat_number' => 'IT09876543210', 'tax_code' => '', 'first_name' => '', 'last_name' => '']);
        $day = CarbonImmutable::now('Europe/Rome')->toDateString();

        $r = app(AccountingExport::class)->forPeriod($day, $day);
        $z = $this->open($r['zip']);

        $this->assertSame(1, $r['invoices']);
        $this->assertSame(0, $r['credit_notes']);
        $xml = array_filter($z['files'], fn ($k) => str_starts_with($k, 'xml/'), ARRAY_FILTER_USE_KEY);
        $this->assertCount(1, $xml);
        $this->assertStringContainsString('FatturaElettronica', (string) array_values($xml)[0]);
        $this->assertArrayHasKey('LEGGIMI.txt', $z['files']);

        $this->assertSame('Tipo documento', $z['csv'][0][0]);
        $row = $z['csv'][1];
        $this->assertSame('TD01', $row[0]);
        $this->assertSame($o->invoice->number, $row[1]);
        $this->assertSame('Acme S.r.l.', $row[4]);
        $this->assertSame('IT09876543210', $row[6]);
        $this->assertSame('Carta', $row[13]);
        $this->assertSame($o->number, $row[14]);
        $this->assertSame('OK', $row[16]);
        $this->assertSame(str_replace('.', ',', (string) $o->invoice->net), $row[10]);
        $this->assertSame(str_replace('.', ',', (string) $o->invoice->vat), $row[11]);
    }

    public function test_credit_notes_are_negative_and_name_the_invoice_they_reverse(): void
    {
        $o = $this->paidOrder();
        $note = app(InvoiceService::class)->issueCreditNote($o->load('items', 'invoice'), [$o->items->last()->id => 1], 'Reso');
        $day = CarbonImmutable::now('Europe/Rome')->toDateString();

        $r = app(AccountingExport::class)->forPeriod($day, $day);
        $rows = collect($this->open($r['zip'])['csv'])->skip(1)->values();

        $this->assertSame(1, $r['credit_notes']);
        $nc = $rows->firstWhere(0, 'TD04');
        $this->assertSame($note->number, $nc[1]);
        $this->assertSame($o->invoice->number, $nc[3]);
        $this->assertStringStartsWith('-', $nc[10]);
        $this->assertStringStartsWith('-', $nc[12]);
    }

    public function test_receipts_of_private_customers_stay_out_and_are_counted(): void
    {
        config(['lamess.private_document' => 'receipt']);
        $this->plan();
        $this->shipping('5.00');
        $p = $this->product('R', '50.00');
        $orders = app(OrderService::class);
        $orders->markPaid($orders->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'card', $this->billing()));
        $day = CarbonImmutable::now('Europe/Rome')->toDateString();

        $r = app(AccountingExport::class)->forPeriod($day, $day);

        $this->assertSame(0, $r['invoices']);
        $this->assertSame(1, $r['receipts_skipped']);
    }

    public function test_a_document_with_incomplete_data_gets_no_xml_but_a_row_marked_with_the_error(): void
    {
        $o = $this->paidOrder(['type' => 'private', 'tax_code' => '']);
        $o->invoice->update(['buyer' => array_merge($o->invoice->buyer, ['tax_code' => '', 'vat_number' => ''])]);
        $day = CarbonImmutable::now('Europe/Rome')->toDateString();

        $r = app(AccountingExport::class)->forPeriod($day, $day);
        $z = $this->open($r['zip']);

        $this->assertCount(1, $r['errors']);
        $this->assertSame([], array_filter(array_keys($z['files']), fn ($k) => str_starts_with($k, 'xml/')));
        $this->assertStringStartsWith('ERRORE', $z['csv'][1][16]);
        $this->assertSame('', $z['csv'][1][15]);
        $this->assertStringContainsString($o->invoice->number, $z['files']['LEGGIMI.txt']);
    }

    public function test_formulas_in_customer_names_are_defused(): void
    {
        $o = $this->paidOrder(['type' => 'company', 'company_name' => '=CMD()', 'vat_number' => 'IT09876543210']);
        $day = CarbonImmutable::now('Europe/Rome')->toDateString();

        $z = $this->open(app(AccountingExport::class)->forPeriod($day, $day)['zip']);

        $this->assertSame("'=CMD()", $z['csv'][1][4]);
    }

    public function test_admin_downloads_the_zip_from_the_invoices_list_and_the_export_is_audited(): void
    {
        $this->paidOrder();
        $day = CarbonImmutable::now('Europe/Rome')->toDateString();
        $this->actingAs(User::factory()->role()->create());

        Livewire::test(ListInvoices::class)
            ->callAction('accountingZip', ['from' => $day, 'until' => $day])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded('fatture-lamess-'.$day.'_'.$day.'.zip');

        $this->assertTrue(AuditEntry::where('action', 'export.accounting')->exists());
    }
}
