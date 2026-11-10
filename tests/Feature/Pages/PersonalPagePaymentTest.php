<?php

declare(strict_types=1);

namespace Tests\Feature\Pages;

use App\Domain\Pages\PersonalPagePayment;
use App\Domain\Pages\PersonalPageService;
use App\Models\ConsultantPage;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\PersonalPageActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Pagamento automatico della pagina personale con KMoney (conto di KNM): richiesta, avviso firmato, idempotenza, ritorno. */
final class PersonalPagePaymentTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private const SECRET = 'segreto-di-prova';

    private const UUID = '11111111-2222-3333-4444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan();
        config([
            'lamess.personal_pages.enabled' => true, 'lamess.personal_pages.activation_required' => true, 'lamess.personal_pages.price' => '150.00',
            'lamess.kmoney.base_url' => 'https://kmoney.test',
            'lamess.personal_pages.kmoney' => ['enabled' => true, 'account' => 'KYB-KNM-TEST', 'api_token' => 'km_token_knm', 'webhook_secret' => self::SECRET],
        ]);
    }

    private function consultantUser(): User
    {
        $u = User::factory()->create(['name' => 'Maria A', 'email' => 'maria@example.test']);
        $this->consultant('A')->update(['user_id' => $u->id, 'referral_code' => 'REFA']);
        Profile::create(['user_id' => $u->id, 'type' => 'private', 'first_name' => 'Maria', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A41H501Z', 'phone' => '3331234567']);

        return $u->refresh();
    }

    private function fakeKMoney(string $status = 'pending', string $checkout = 'https://kmoney.test/pay/tok/inizia?c=abc'): void
    {
        Http::fake(function (Request $r) use ($status, $checkout) {
            if ($r->method() === 'POST') {
                return Http::response(['data' => ['uuid' => self::UUID, 'status' => 'pending', 'checkout_url' => $checkout, 'amount' => 15000]], 201);
            }

            return Http::response(['data' => ['uuid' => self::UUID, 'status' => $status, 'amount' => 15000, 'currency' => 'KY']], 200);
        });
    }

    /** @return array<string, mixed> */
    private function paid(array $over = []): array
    {
        return array_replace(['uuid' => self::UUID, 'external_reference' => 'A', 'amount' => 15000, 'currency' => 'KY', 'status' => 'paid', 'transfer_uuid' => 'tr-1'], $over);
    }

    private function hook(array $payload, ?string $secret = self::SECRET, string $event = 'payment_request.paid')
    {
        $body = json_encode(['event' => $event, 'timestamp' => now()->toIso8601String(), 'payload' => $payload]);

        return $this->call('POST', '/webhooks/kmoney-pagine', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-KMoney-Signature' => 'sha256='.hash_hmac('sha256', (string) $body, (string) $secret),
        ], (string) $body);
    }

    private function pageWithRequest(User $u): ConsultantPage
    {
        $page = app(PersonalPageService::class)->forConsultant($u->consultant);
        $page->forceFill(['kmoney_request_uuid' => self::UUID])->save();

        return $page->refresh();
    }

    public function test_off_by_default_no_button_and_no_request(): void
    {
        config(['lamess.personal_pages.kmoney.enabled' => false]);
        Http::fake();
        $u = $this->consultantUser();
        $this->actingAs($u)->get('/area/pagina')->assertOk()->assertDontSee('Paga con KMoney')->assertSee('Ho pagato, richiedi l\'attivazione', false);
        $this->actingAs($u)->post('/area/pagina/paga')->assertSessionHasErrors('activation');
        Http::assertNothingSent();

        config(['lamess.personal_pages.kmoney' => ['enabled' => true, 'api_token' => null, 'webhook_secret' => self::SECRET]]);
        $this->assertFalse(app(PersonalPagePayment::class)->enabled(), 'senza token resta spento anche con l\'interruttore acceso');
    }

    public function test_pay_button_creates_the_request_on_knm_with_prefilled_payer_and_redirects_to_kmoney(): void
    {
        $this->fakeKMoney();
        $u = $this->consultantUser();
        $this->actingAs($u)->get('/area/pagina')->assertSee('Paga con KMoney');

        $this->actingAs($u)->post('/area/pagina/paga')->assertRedirect('https://kmoney.test/pay/tok/inizia?c=abc');

        Http::assertSent(function (Request $r) {
            $d = $r->data();

            return $r->method() === 'POST' && $r->url() === 'https://kmoney.test/api/v1/payment-requests'
                && $r->hasHeader('Authorization', 'Bearer km_token_knm')
                && $d['amount'] === 15000 && $d['external_reference'] === 'A' && $d['expires_in_minutes'] === 1440
                && str_ends_with($d['return_url'], '/area/pagina/pagamento?esito=ok') && str_ends_with($d['cancel_url'], '/area/pagina/pagamento?esito=annullato')
                && $d['payer'] === ['name' => 'Maria Rossi', 'email' => 'maria@example.test', 'phone' => '3331234567', 'fiscal_code' => 'RSSMRA80A41H501Z'];
        });
        $this->assertSame(self::UUID, ConsultantPage::firstOrFail()->kmoney_request_uuid);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'page.payment_started')->count());
    }

    public function test_a_checkout_address_outside_kmoney_is_never_followed(): void
    {
        $this->fakeKMoney('pending', 'https://sito-sbagliato.test/pay/x');
        $u = $this->consultantUser();
        $this->actingAs($u)->post('/area/pagina/paga')->assertSessionHasErrors('activation');
        $this->assertNull(ConsultantPage::firstOrFail()->kmoney_request_uuid);
    }

    public function test_kmoney_down_is_a_friendly_error(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
        $u = $this->consultantUser();
        $this->actingAs($u)->post('/area/pagina/paga')->assertSessionHasErrors('activation');
    }

    public function test_signed_notice_activates_for_twelve_months_once_and_notifies(): void
    {
        Notification::fake();
        $u = $this->consultantUser();
        $page = $this->pageWithRequest($u);

        $this->hook($this->paid())->assertOk();
        $page->refresh();
        $this->assertSame('active', $page->activation);
        $this->assertSame('paid', $page->activation_source);
        $this->assertTrue($page->activation_expires_at->between(now()->addMonths(12)->subMinute(), now()->addMonths(12)->addMinute()));
        $this->assertSame(self::UUID, $page->kmoney_paid_uuid);
        $this->assertNull($page->kmoney_request_uuid);
        $this->assertStringContainsString('KYB-KNM-TEST', (string) $page->activation_note);
        Notification::assertSentToTimes($u, PersonalPageActivation::class, 1);

        // lo stesso avviso due volte = una sola attivazione
        $expires = $page->activation_expires_at;
        $this->hook($this->paid())->assertOk();
        $this->hook($this->paid())->assertOk();
        $this->assertTrue($page->refresh()->activation_expires_at->equalTo($expires));
        Notification::assertSentToTimes($u, PersonalPageActivation::class, 1);
    }

    public function test_notice_with_wrong_or_missing_signature_is_refused_and_changes_nothing(): void
    {
        $u = $this->consultantUser();
        $page = $this->pageWithRequest($u);

        $this->hook($this->paid(), 'altro-segreto')->assertStatus(400);
        $this->call('POST', '/webhooks/kmoney-pagine', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['event' => 'payment_request.paid', 'payload' => $this->paid()]))->assertStatus(400);
        $this->assertNotSame('active', $page->refresh()->activation);

        config(['lamess.personal_pages.kmoney.webhook_secret' => null]);
        $this->hook($this->paid(), '')->assertStatus(400);
    }

    public function test_different_amount_or_unknown_request_does_not_activate(): void
    {
        $u = $this->consultantUser();
        $page = $this->pageWithRequest($u);

        $this->hook($this->paid(['amount' => 100]))->assertStatus(422);
        $this->assertNotSame('active', $page->refresh()->activation);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'page.payment_mismatch')->count());

        $this->hook($this->paid(['uuid' => 'altra-richiesta']))->assertOk();   // 200: niente da riprovare, ma resta nel registro
        $this->hook($this->paid(['external_reference' => 'NON-ESISTE']))->assertOk();
        $this->assertNotSame('active', $page->refresh()->activation);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'page.payment_unmatched')->count());
    }

    public function test_other_events_are_ignored(): void
    {
        $u = $this->consultantUser();
        $page = $this->pageWithRequest($u);
        $this->hook($this->paid(), self::SECRET, 'transfer.created')->assertOk();
        $this->assertNotSame('active', $page->refresh()->activation);
    }

    public function test_return_checks_kmoney_when_the_notice_has_not_arrived_yet(): void
    {
        Notification::fake();
        $this->fakeKMoney('paid');
        $u = $this->consultantUser();
        $page = $this->pageWithRequest($u);

        $this->actingAs($u)->get('/area/pagina/pagamento?esito=ok')->assertRedirect('/area/pagina')->assertSessionHas('status', fn ($m) => str_contains($m, 'la tua pagina è attiva'));
        $this->assertSame('active', $page->refresh()->activation);

        // l'avviso che arriva dopo non attiva una seconda volta
        $expires = $page->activation_expires_at;
        $this->hook($this->paid())->assertOk();
        $this->assertTrue($page->refresh()->activation_expires_at->equalTo($expires));
    }

    public function test_return_still_pending_or_cancelled_gives_the_right_message_and_activates_nothing(): void
    {
        $this->fakeKMoney('pending');
        $u = $this->consultantUser();
        $page = $this->pageWithRequest($u);

        $this->actingAs($u)->get('/area/pagina/pagamento?esito=ok')->assertSessionHas('status', fn ($m) => str_contains($m, 'aspettando la conferma'));
        $this->actingAs($u)->get('/area/pagina/pagamento?esito=annullato')->assertSessionHas('status', fn ($m) => str_contains($m, 'annullato'));
        $this->assertNotSame('active', $page->refresh()->activation);
    }

    public function test_renewal_in_the_notice_period_extends_from_the_old_expiry(): void
    {
        Notification::fake();
        $u = $this->consultantUser();
        $svc = app(PersonalPageService::class);
        $page = $svc->forConsultant($u->consultant);
        $svc->activate($page, 'paid');
        $page->refresh()->forceFill(['activation_expires_at' => now()->addDays(10), 'kmoney_paid_uuid' => 'vecchia-richiesta'])->save();
        $old = $page->activation_expires_at;
        $this->fakeKMoney();

        $this->actingAs($u)->get('/area/pagina')->assertSee('Rinnova con KMoney');
        $this->actingAs($u)->post('/area/pagina/paga')->assertRedirect();
        $this->hook($this->paid())->assertOk();

        $this->assertTrue($page->refresh()->activation_expires_at->between($old->addMonths(12)->subMinute(), $old->addMonths(12)->addMinute()));
    }

    public function test_an_active_page_far_from_expiry_cannot_start_a_payment(): void
    {
        Http::fake();
        $u = $this->consultantUser();
        $svc = app(PersonalPageService::class);
        $svc->activate($svc->forConsultant($u->consultant), 'paid');

        $this->actingAs($u)->post('/area/pagina/paga')->assertSessionHasErrors('activation');
        Http::assertNothingSent();
    }

    public function test_amount_comes_from_the_price_in_cents_without_floats(): void
    {
        $p = app(PersonalPagePayment::class);
        $this->assertSame(15000, $p->amountCents());
        config(['lamess.personal_pages.price' => '99.90']);
        $this->assertSame(9990, $p->amountCents());
        config(['lamess.personal_pages.price' => '0.29']);
        $this->assertSame(29, $p->amountCents());
    }
}
