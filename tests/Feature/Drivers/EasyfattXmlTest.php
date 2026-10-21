<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use App\Domain\Billing\EasyfattXmlBuilder;
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
use SimpleXMLElement;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class EasyfattXmlTest extends TestCase
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

    private function foreignOrder(string $country, array $billing, string $vat): Order
    {
        config(['lamess.shop_countries' => ['IT', $country]]);
        $this->plan();
        \App\Models\ShippingRate::create(['country_code' => $country, 'amount_net' => '0.00', 'vat_rate' => $vat]);
        $p = $this->product('FX'.$country, '100.00', ['vat_rate' => $vat]);
        $orders = app(OrderService::class);
        $o = $orders->place($this->buyer($this->activeSponsor()), [$p->id => 1], $country, 'card', $this->billing($billing));

        return $orders->markPaid($o);
    }

    private function parse(string $xml): SimpleXMLElement
    {
        $doc = simplexml_load_string($xml);
        $this->assertInstanceOf(SimpleXMLElement::class, $doc, 'XML ben formato');

        return $doc;
    }

    public function test_invoice_becomes_an_easyfatt_invoice_with_section_order_reference_and_payment(): void
    {
        $o = $this->paidOrder();
        $r = app(EasyfattXmlBuilder::class)->build([$o->invoice]);
        $doc = $this->parse($r['xml'])->Documents->Document;

        $this->assertSame(1, $r['count']);
        $this->assertSame('I', (string) $doc->DocumentType);
        $this->assertSame((string) EasyfattXmlBuilder::progressive($o->invoice->number), (string) $doc->Number);
        $this->assertSame('/FT', (string) $doc->Numbering);
        $this->assertSame($o->invoice->issued_at->setTimezone('Europe/Rome')->format('Y-m-d'), (string) $doc->Date);
        $this->assertSame('Mario Rossi', (string) $doc->CustomerName);
        $this->assertSame('RSSMRA80A01H501U', (string) $doc->CustomerFiscalCode);
        $this->assertSame('', (string) $doc->CustomerCountry, 'Italia: nessun paese scritto');
        $this->assertSame('false', (string) $doc->PricesIncludeVat);
        $this->assertStringContainsString($o->invoice->number, (string) $doc->InternalComment);
        $this->assertStringContainsString($o->number, (string) $doc->InternalComment);
        $this->assertSame('true', (string) $doc->Payments->Payment->Paid);
        $this->assertSame((string) $o->invoice->total, (string) $doc->Payments->Payment->Amount);
        $this->assertCount($o->invoice->lines->count(), $doc->Rows->Row, 'una riga per riga di fattura, spedizione compresa');
        $this->assertSame('A', (string) $doc->Rows->Row[0]->Code);
        $this->assertSame('22', (string) $doc->Rows->Row[0]->VatCode);
        $this->assertSame('22.00', (string) $doc->Rows->Row[0]->VatCode['Perc']);
        $this->assertSame('Imponibile', (string) $doc->Rows->Row[0]->VatCode['Class']);
        $this->assertSame('131.06557377', (string) $doc->Rows->Row[0]->Price);
    }

    public function test_rows_recomputed_like_easyfatt_give_the_same_net_and_vat_as_the_invoice(): void
    {
        $o = $this->paidOrder();
        $doc = $this->parse(app(EasyfattXmlBuilder::class)->build([$o->invoice])['xml'])->Documents->Document;

        $net = 0.0;
        $byRate = [];
        foreach ($doc->Rows->Row as $row) {
            $line = round((float) $row->Qty * (float) $row->Price, 2);
            $net += $line;
            $byRate[(string) $row->VatCode['Perc']] = ($byRate[(string) $row->VatCode['Perc']] ?? 0) + $line;
        }
        $vat = 0.0;
        foreach ($byRate as $rate => $base) {
            $vat += round($base * (float) $rate / 100, 2);
        }

        $this->assertEqualsWithDelta((float) $o->invoice->net, round($net, 2), 0.001);
        $this->assertEqualsWithDelta((float) $o->invoice->vat, round($vat, 2), 0.001);
        $this->assertEqualsWithDelta((float) $o->invoice->total, round($net + $vat, 2), 0.001);
    }

    public function test_company_gets_vat_without_country_prefix_sdi_code_and_a_different_delivery_address(): void
    {
        $o = $this->paidOrder(['type' => 'company', 'company_name' => 'Acme & Figli S.p.A.', 'vat_number' => 'IT09876543210', 'tax_code' => '', 'sdi_code' => 'ABC1234', 'first_name' => '', 'last_name' => ''], 'transfer');
        $o->invoice->update(['buyer' => array_merge($o->invoice->buyer, ['shipping' => ['recipient' => 'Magazzino Acme', 'address' => 'Via Napoli 5', 'city' => 'Catanzaro', 'zip' => '88100', 'province' => 'CZ']])]);
        $xml = app(EasyfattXmlBuilder::class)->build([$o->invoice->refresh()])['xml'];
        $doc = $this->parse($xml)->Documents->Document;

        $this->assertSame('Acme & Figli S.p.A.', (string) $doc->CustomerName);
        $this->assertSame('09876543210', (string) $doc->CustomerVatCode);
        $this->assertSame('ABC1234', (string) $doc->CustomerEInvoiceDestCode);
        $this->assertSame('Magazzino Acme', (string) $doc->DeliveryName);
        $this->assertSame('Via Napoli 5', (string) $doc->DeliveryAddress);
        $this->assertSame('CZ', (string) $doc->DeliveryProvince);
        $this->assertStringContainsString('Acme &amp; Figli S.p.A.', $xml, 'caratteri riservati scritti correttamente');
    }

    public function test_delivery_is_left_out_when_it_equals_the_billing_address(): void
    {
        $o = $this->paidOrder();
        $doc = $this->parse(app(EasyfattXmlBuilder::class)->build([$o->invoice])['xml'])->Documents->Document;

        $this->assertSame(0, $doc->DeliveryAddress->count());
    }

    public function test_foreign_zero_vat_rows_carry_the_easyfatt_class_and_the_country_name(): void
    {
        $o = $this->foreignOrder('US', ['tax_code' => null, 'zip' => '10001', 'city' => 'New York', 'province' => null, 'address' => '5th Ave 1'], '0.00');
        $doc = $this->parse(app(EasyfattXmlBuilder::class)->build([$o->invoice])['xml'])->Documents->Document;
        $vat = $doc->Rows->Row[0]->VatCode;

        $this->assertSame('N3.1', (string) $vat);
        $this->assertSame('0.00', (string) $vat['Perc']);
        $this->assertSame('Extra-UE', (string) $vat['Class']);
        $this->assertSame('Stati Uniti', (string) $doc->CustomerCountry);
        $this->assertSame('XXXXXXX', (string) $doc->CustomerEInvoiceDestCode);
        $this->assertSame(0, $doc->CustomerProvince->count());
    }

    public function test_credit_note_is_an_easyfatt_credit_note_that_names_the_invoice_it_reverses(): void
    {
        $o = $this->paidOrder();
        $item = $o->items->firstWhere('sku', 'B') ?? $o->items->last();
        $note = app(InvoiceService::class)->issueCreditNote($o->load('items', 'invoice'), [$item->id => 1], 'Reso di un articolo');
        $doc = $this->parse(app(EasyfattXmlBuilder::class)->build([$note])['xml'])->Documents->Document;

        $this->assertSame('N', (string) $doc->DocumentType);
        $this->assertSame('/NC', (string) $doc->Numbering);
        $this->assertSame('Mario Rossi', (string) $doc->CustomerName);
        $this->assertStringContainsString($o->invoice->number, (string) $doc->InternalComment);
        $this->assertSame(0, $doc->Payments->count());
    }

    public function test_period_export_takes_invoices_and_credit_notes_but_leaves_receipts_out_unless_asked(): void
    {
        $o = $this->paidOrder();
        $item = $o->items->last();
        app(InvoiceService::class)->issueCreditNote($o->load('items', 'invoice'), [$item->id => 1], 'Reso');

        config(['lamess.private_document' => 'receipt']);
        $p = $this->product('R', '50.00');
        $orders = app(OrderService::class);
        $orders->markPaid($orders->place($o->buyer, [$p->id => 1], 'IT', 'card', $this->billing()));

        $day = CarbonImmutable::now('Europe/Rome')->toDateString();
        $builder = app(EasyfattXmlBuilder::class);

        $without = $builder->forPeriod($day, $day);
        $this->assertSame(1, $without['invoices']);
        $this->assertSame(1, $without['credit_notes']);
        $this->assertSame(1, $without['receipts_skipped']);
        $this->assertCount(2, $this->parse($without['xml'])->Documents->Document);

        $with = $builder->forPeriod($day, $day, true);
        $this->assertSame(2, $with['invoices']);
        $this->assertSame(0, $with['receipts_skipped']);

        $other = CarbonImmutable::now('Europe/Rome')->subMonths(2)->toDateString();
        $this->assertSame(0, $builder->forPeriod($other, $other)['count']);
    }

    public function test_progressive_and_section_come_from_the_portal_number(): void
    {
        $this->assertSame(234, EasyfattXmlBuilder::progressive('FT/2026/00234'));
        $this->assertSame('/FT', EasyfattXmlBuilder::section('FT/2026/00234'));
        $this->assertSame(12, EasyfattXmlBuilder::progressive('2026/pri12'));
        $this->assertSame('/PRI', EasyfattXmlBuilder::section('2026/pri12'));
        $this->assertSame('/NC', EasyfattXmlBuilder::section('NC/2026/00001'));
    }

    public function test_admin_downloads_the_easyfatt_file_from_the_invoices_list(): void
    {
        $o = $this->paidOrder();
        $day = CarbonImmutable::now('Europe/Rome')->toDateString();
        $this->actingAs(User::factory()->role()->create());

        Livewire::test(ListInvoices::class)
            ->callAction('easyfatt', ['from' => $day, 'until' => $day, 'receipts' => false])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded('easyfatt-lamess-'.$day.'_'.$day.'.xml');

        $this->assertTrue(AuditEntry::where('action', 'export.easyfatt')->exists());
        $this->assertNotNull($o->invoice);
    }
}
