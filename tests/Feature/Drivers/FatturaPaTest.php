<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use App\Domain\Access\Roles;
use App\Domain\Billing\BillingException;
use App\Domain\Billing\FatturaPaBuilder;
use App\Domain\Billing\FileInvoiceSink;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\MailInvoiceSink;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\User;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class FatturaPaTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake();
        config(['lamess.seller' => [
            'name' => 'Lamèss S.r.l.', 'vat_number' => 'IT01234567890', 'address' => 'Via Roma 1', 'email' => 'a@b.it',
            'tax_code' => '01234567890', 'zip' => '89100', 'city' => 'Reggio Calabria', 'province' => 'rc', 'regime' => 'RF01',
        ]]);
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

    private function assertValidFatturaPa(string $xml): void
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml, LIBXML_NONET);
        libxml_use_internal_errors(true);
        $ok = $dom->schemaValidate(base_path('tests/Fixtures/fatturapa/Schema_FatturaPA_v1.2.2.xsd'));
        $this->assertTrue($ok, collect(libxml_get_errors())->map(fn ($e) => trim($e->message))->implode(' | '));
    }

    public function test_invoice_for_private_customer_is_schema_valid_and_consistent(): void
    {
        $o = $this->paidOrder();
        $f = app(FatturaPaBuilder::class)->build($o->invoice);

        $this->assertValidFatturaPa($f['xml']);
        $this->assertMatchesRegularExpression('/^IT01234567890_[A-Z0-9]{1,5}\.xml$/', $f['filename']);
        $this->assertStringContainsString('<TipoDocumento>TD01</TipoDocumento>', $f['xml']);
        $this->assertStringContainsString('<CodiceDestinatario>0000000</CodiceDestinatario>', $f['xml']);
        $this->assertStringContainsString('<ModalitaPagamento>MP08</ModalitaPagamento>', $f['xml']);
        $this->assertStringContainsString('<Provincia>RC</Provincia>', $f['xml']);
        $this->assertStringContainsString('<ImportoTotaleDocumento>'.$o->invoice->total.'</ImportoTotaleDocumento>', $f['xml'], 'totale del file = totale della fattura');
        $this->assertSame(3, substr_count($f['xml'], '<DettaglioLinee>'), 'due prodotti + spedizione');
    }

    public function test_order_number_is_referenced_in_invoice_and_credit_note(): void
    {
        $o = $this->paidOrder();
        $f = app(FatturaPaBuilder::class)->build($o->invoice);
        $this->assertValidFatturaPa($f['xml']);
        $this->assertStringContainsString('<DatiOrdineAcquisto>', $f['xml']);
        $this->assertStringContainsString('<IdDocumento>'.$o->number.'</IdDocumento>', $f['xml']);
        $this->assertLessThan(strpos($f['xml'], '<DatiBeniServizi>'), strpos($f['xml'], '<DatiOrdineAcquisto>'));

        $note = app(InvoiceService::class)->issueCreditNote($o->load('items', 'invoice'), [$o->items->last()->id => 1], 'Reso');
        $n = app(FatturaPaBuilder::class)->build($note);
        $this->assertValidFatturaPa($n['xml']);
        $this->assertLessThan(strpos($n['xml'], '<DatiFattureCollegate>'), strpos($n['xml'], '<DatiOrdineAcquisto>'), 'ordine prima delle fatture collegate, come vuole lo schema');
        $this->assertStringContainsString('<IdDocumento>'.$o->number.'</IdDocumento>', $n['xml']);
    }

    public function test_company_invoice_uses_sdi_code_vat_and_transfer_payment(): void
    {
        $o = $this->paidOrder(['type' => 'company', 'company_name' => 'Acme & Figli S.p.A.', 'vat_number' => 'IT09876543210', 'tax_code' => '', 'sdi_code' => 'ABC1234', 'first_name' => '', 'last_name' => ''], 'transfer');
        $f = app(FatturaPaBuilder::class)->build($o->invoice);

        $this->assertValidFatturaPa($f['xml']);
        $this->assertStringContainsString('<CodiceDestinatario>ABC1234</CodiceDestinatario>', $f['xml']);
        $this->assertStringContainsString('<IdCodice>09876543210</IdCodice>', $f['xml']);
        $this->assertStringContainsString('Acme &amp; Figli S.p.A.', $f['xml']);
        $this->assertStringContainsString('<ModalitaPagamento>MP05</ModalitaPagamento>', $f['xml']);
    }

    /** Ordine verso l'estero: paese, tariffa di spedizione e mercato aperti per il test. */
    private function foreignOrder(string $country, array $billing, string $vat = '22.00'): Order
    {
        config(['lamess.shop_countries' => ['IT', $country]]);
        $this->plan();
        \App\Models\ShippingRate::create(['country_code' => $country, 'amount_net' => '0.00', 'vat_rate' => $vat]);
        $p = $this->product('FX'.$country, '100.00', ['vat_rate' => $vat]);
        $orders = app(OrderService::class);
        $o = $orders->place($this->buyer($this->activeSponsor()), [$p->id => 1], $country, 'card', $this->billing($billing));

        return $orders->markPaid($o);
    }

    public function test_foreign_private_customer_gets_country_xxxxxxx_and_zero_id_without_cf(): void
    {
        $o = $this->foreignOrder('DE', ['tax_code' => null, 'zip' => '10115', 'city' => 'Berlin', 'province' => null, 'address' => 'Unter den Linden 1']);
        $f = app(FatturaPaBuilder::class)->build($o->invoice);

        $this->assertValidFatturaPa($f['xml']);
        $this->assertStringContainsString('<CodiceDestinatario>XXXXXXX</CodiceDestinatario>', $f['xml']);
        $buyer = substr($f['xml'], (int) strpos($f['xml'], '<CessionarioCommittente>'));
        $this->assertStringContainsString('<IdPaese>DE</IdPaese>', $buyer);
        $this->assertStringContainsString('<IdCodice>0000000</IdCodice>', $buyer);
        $this->assertStringContainsString('<Nazione>DE</Nazione>', $buyer);
        $this->assertStringNotContainsString('<Provincia>', $buyer, 'nessuna provincia per l\'estero');
        $this->assertStringNotContainsString('<CodiceFiscale>', $buyer);
    }

    public function test_foreign_company_uses_its_vat_without_country_prefix_and_non_five_digit_zip_becomes_00000(): void
    {
        $o = $this->foreignOrder('NL', ['type' => 'company', 'company_name' => 'Koffie BV', 'vat_number' => 'NL123456789B01', 'tax_code' => '', 'first_name' => '', 'last_name' => '', 'zip' => '1012 AB', 'city' => 'Amsterdam', 'province' => null, 'pec' => 'x@pec.it']);
        $f = app(FatturaPaBuilder::class)->build($o->invoice);

        $this->assertValidFatturaPa($f['xml']);
        $buyer = substr($f['xml'], (int) strpos($f['xml'], '<CessionarioCommittente>'));
        $this->assertStringContainsString('<IdPaese>NL</IdPaese>', $buyer);
        $this->assertStringContainsString('<IdCodice>123456789B01</IdCodice>', $buyer);
        $this->assertStringContainsString('<CAP>00000</CAP>', $buyer);
        $this->assertStringNotContainsString('PECDestinatario', $f['xml'], 'nessuna PEC verso l\'estero');
    }

    public function test_zero_vat_lines_carry_natura_and_non_eu_buyer_is_an_export(): void
    {
        $o = $this->foreignOrder('US', ['tax_code' => null, 'zip' => '10001', 'city' => 'New York', 'province' => null, 'address' => '5th Ave 1'], '0.00');
        $f = app(FatturaPaBuilder::class)->build($o->invoice);

        $this->assertValidFatturaPa($f['xml']);
        $this->assertSame(2, substr_count($f['xml'], '<Natura>N3.1</Natura>'), 'una nella riga, una nel riepilogo');
        $this->assertStringContainsString('<RiferimentoNormativo>', $f['xml']);
        $this->assertStringNotContainsString('<DatiBollo>', $f['xml'], 'le esportazioni non pagano il bollo');
    }

    public function test_zero_vat_for_italian_customer_uses_configured_natura_and_adds_virtual_stamp_over_77_47(): void
    {
        $this->plan();
        $this->shipping('0.00');
        $p = $this->product('Z', '100.00', ['vat_rate' => '0.00']);
        $orders = app(OrderService::class);
        $o = $orders->markPaid($orders->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'card', $this->billing()));
        $f = app(FatturaPaBuilder::class)->build($o->invoice);

        $this->assertValidFatturaPa($f['xml']);
        $this->assertStringContainsString('<Natura>N2.2</Natura>', $f['xml']);
        $this->assertStringContainsString('<BolloVirtuale>SI</BolloVirtuale>', $f['xml']);
        $this->assertStringContainsString('<ImportoBollo>2.00</ImportoBollo>', $f['xml']);
    }

    public function test_foreign_private_checkout_does_not_require_a_tax_code(): void
    {
        $o = $this->foreignOrder('ES', ['tax_code' => null, 'zip' => '28001', 'city' => 'Madrid', 'province' => null]);

        $this->assertNotNull($o->invoice, 'documento emesso senza codice fiscale');
        $this->assertSame('ES', $o->invoice->buyer['country_code']);
    }

    public function test_pec_is_used_when_no_sdi_code(): void
    {
        $o = $this->paidOrder(['type' => 'company', 'company_name' => 'Acme', 'vat_number' => '09876543210', 'pec' => 'acme@pec.it']);
        $f = app(FatturaPaBuilder::class)->build($o->invoice);

        $this->assertValidFatturaPa($f['xml']);
        $this->assertStringContainsString('<PECDestinatario>acme@pec.it</PECDestinatario>', $f['xml']);
    }

    public function test_credit_note_is_td04_and_references_invoice(): void
    {
        $o = $this->paidOrder();
        $item = $o->items->firstWhere('sku', 'B') ?? $o->items->last();
        $note = app(InvoiceService::class)->issueCreditNote($o->load('items', 'invoice'), [$item->id => 1], 'Reso di un articolo');
        $f = app(FatturaPaBuilder::class)->build($note);

        $this->assertValidFatturaPa($f['xml']);
        $this->assertStringContainsString('<TipoDocumento>TD04</TipoDocumento>', $f['xml']);
        $this->assertStringContainsString('<IdDocumento>'.$o->invoice->number.'</IdDocumento>', $f['xml']);
        $this->assertStringContainsString('<Causale>Reso di un articolo</Causale>', $f['xml']);
        $this->assertStringNotContainsString('DatiPagamento', $f['xml']);
        $this->assertStringStartsWith('IT01234567890_N', $f['filename']);
    }

    public function test_incomplete_seller_or_buyer_data_is_reported_clearly(): void
    {
        $o = $this->paidOrder();
        config(['lamess.seller.zip' => '', 'lamess.seller.vat_number' => '123']);
        try {
            app(FatturaPaBuilder::class)->build($o->invoice);
            $this->fail('atteso errore');
        } catch (BillingException $e) {
            $this->assertStringContainsString('partita IVA', $e->getMessage());
            $this->assertStringContainsString('CAP', $e->getMessage());
        }

        config(['lamess.seller.zip' => '89100', 'lamess.seller.vat_number' => '01234567890']);
        $o->invoice->update(['buyer' => ['type' => 'private', 'first_name' => 'A', 'last_name' => 'B', 'address' => 'Via 1', 'zip' => '89100', 'city' => 'RC']]);
        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('codice fiscale');
        app(FatturaPaBuilder::class)->build($o->invoice->refresh());
    }

    public function test_receipts_are_not_transmitted(): void
    {
        config(['lamess.private_document' => 'receipt']);
        $o = $this->paidOrder();
        $this->assertSame('receipt', $o->invoice->type);

        $r = (new FileInvoiceSink(new FatturaPaBuilder))->send($o->invoice);
        $this->assertTrue($r->ok);
        $this->assertSame('ricevuta-non-trasmessa', $r->reference);
        $this->assertSame([], Storage::allFiles('sdi-out'));
    }

    public function test_file_sink_writes_xml_and_service_marks_sent(): void
    {
        config(['lamess.invoice_sink_driver' => 'file']);
        $o = $this->paidOrder();
        $inv = $o->invoice->refresh();

        $this->assertSame('sent', $inv->sink_status, (string) $inv->sink_error);
        $this->assertNotNull($inv->sink_ref);
        Storage::assertExists('sdi-out/'.$inv->sink_ref);
        $this->assertStringContainsString($inv->number, Storage::get('sdi-out/'.$inv->sink_ref));
    }

    public function test_batch_mode_keeps_invoices_pending_until_sent_in_block(): void
    {
        Mail::fake();
        config(['lamess.invoice_sink_driver' => 'mail', 'lamess.sdi.mail_to' => 'sdi@example.com', 'lamess.mail_enabled' => true, 'lamess.sdi.send_mode' => 'batch']);
        $inv = $this->paidOrder()->invoice->refresh();

        $this->assertSame('pending', $inv->sink_status);
        Mail::assertNothingSent();
        $this->assertSame(0, app(InvoiceService::class)->retryPending(), 'il giro orario non invia in modalità in blocco');
        $this->assertSame(1, app(InvoiceService::class)->pendingCount());
        $this->assertNull(InvoiceService::sendBlocker());

        $this->artisan('lamess:fatture', ['--tutte' => true])->assertSuccessful();
        $this->assertSame('sent', $inv->refresh()->sink_status);
        Mail::assertSent(\App\Mail\InvoiceXmlMail::class, 1);
        $this->assertSame(0, app(InvoiceService::class)->pendingCount());
    }

    public function test_batch_send_is_blocked_while_email_is_off(): void
    {
        config(['lamess.invoice_sink_driver' => 'mail', 'lamess.sdi.mail_to' => 'sdi@example.com', 'lamess.mail_enabled' => false, 'lamess.sdi.send_mode' => 'batch']);
        $inv = $this->paidOrder()->invoice->refresh();

        $this->assertNotNull(InvoiceService::sendBlocker());
        $this->artisan('lamess:fatture', ['--tutte' => true])->assertSuccessful();
        $this->assertSame('pending', $inv->refresh()->sink_status, 'con la posta spenta non si tocca nulla');
    }

    public function test_sink_error_is_recorded_without_losing_the_invoice_and_retry_recovers(): void
    {
        config(['lamess.invoice_sink_driver' => 'file', 'lamess.seller.zip' => '']);
        $o = $this->paidOrder();
        $inv = $o->invoice->refresh();
        $this->assertSame('error', $inv->sink_status);
        $this->assertStringContainsString('CAP', (string) $inv->sink_error);

        config(['lamess.seller.zip' => '89100']);
        $this->assertSame(1, app(InvoiceService::class)->retryPending());
        $this->assertSame('sent', $inv->refresh()->sink_status);
    }

    public function test_mail_sink_attaches_xml_and_respects_global_mail_switch(): void
    {
        Mail::fake();
        $o = $this->paidOrder();
        $inv = $o->invoice;
        $sink = new MailInvoiceSink(new FatturaPaBuilder);

        config(['lamess.sdi.mail_to' => '', 'lamess.mail_enabled' => true]);
        $this->assertStringContainsString('LAMESS_SDI_MAIL_TO', (string) $sink->send($inv)->error);

        config(['lamess.sdi.mail_to' => 'import@gestionale.test', 'lamess.mail_enabled' => false]);
        $r = $sink->send($inv);
        $this->assertFalse($r->ok, 'con le email spente non si segna «inviata»');

        config(['lamess.mail_enabled' => true]);
        $r = $sink->send($inv);
        $this->assertTrue($r->ok);
        Mail::assertSentCount(1);
        Storage::assertExists('sdi-out/'.$r->reference);
    }

    public function test_admin_can_download_xml_only_with_permission(): void
    {
        $o = $this->paidOrder();
        $this->actingAs($o->buyer)->get("/admin-files/invoice/{$o->invoice->id}/xml")->assertForbidden();

        $admin = User::factory()->role(Roles::ADMIN)->create();
        $admin->givePermissionTo('orders.view');
        $this->actingAs($admin)->get("/admin-files/invoice/{$o->invoice->id}/xml")->assertOk()->assertHeader('Content-Type', 'application/xml');
    }
}
