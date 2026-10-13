<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\KMoneyLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Collegamento KMoney col driver «api»: consenso OAuth, poi la pagina del mandato su KMoney (rotta a sé) e il ritorno. */
final class KMoneyMandateFlowTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'lamess.kmoney_driver' => 'api',
            'lamess.payments.kmoney' => true,
            'lamess.kmoney.base_url' => 'https://kmoney.test',
            'lamess.kmoney.client_id' => 'kshop-prova',
            'lamess.kmoney.client_secret' => 'segreto',
            'lamess.kmoney.seller_account' => 'KYBPROVA0000001',
        ]);
        $this->plan();
        $this->shipping('5.00');
    }

    private function linkedWithoutMandate(): array
    {
        $user = $this->buyer($this->activeSponsor());
        $link = KMoneyLink::create(['user_id' => $user->id, 'access_token' => 'at', 'expires_at' => now()->addHour()]);

        return [$user, $link];
    }

    public function test_authorize_payments_sends_the_linked_customer_to_the_kmoney_mandate_page(): void
    {
        [$user] = $this->linkedWithoutMandate();

        $res = $this->actingAs($user)->get('/area/kmoney/mandato?da=profilo');

        $res->assertRedirectContains('https://kmoney.test/oauth/mandate?');
        parse_str((string) parse_url((string) $res->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('kshop-prova', $q['client_id']);
        $this->assertSame('KYBPROVA0000001', $q['seller']);
        $this->assertSame(url('/area/kmoney/ritorno'), $q['return_url']);
        $res->assertSessionHas('kmoney_from', 'profilo');
    }

    public function test_authorize_payments_without_a_linked_account_goes_back_to_the_profile(): void
    {
        $user = $this->buyer($this->activeSponsor());

        $this->actingAs($user)->get('/area/kmoney/mandato?da=profilo')
            ->assertRedirect('/area/profilo#kmoney')
            ->assertSessionHasErrors('kmoney');
    }

    public function test_returning_from_consent_without_a_mandate_goes_on_to_the_mandate_page(): void
    {
        $user = $this->buyer($this->activeSponsor());
        Http::fake([
            'kmoney.test/api/oauth/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600]),
            'kmoney.test/api/v1/mandates' => Http::response(['mandates' => []]),
            'kmoney.test/api/v1/userinfo' => Http::response(['account' => ['number' => 'KYP0000000001']]),
        ]);

        $this->actingAs($user)
            ->withSession(['kmoney_oauth' => ['state' => 'S', 'verifier' => 'V'], 'kmoney_from' => 'profilo'])
            ->get('/area/kmoney/ritorno?code=C&state=S')
            ->assertRedirectContains('https://kmoney.test/oauth/mandate?')
            ->assertSessionHas('kmoney_from', 'profilo');

        $this->assertNull(KMoneyLink::where('user_id', $user->id)->first()->mandate_uuid);
    }

    public function test_returning_from_the_mandate_page_saves_the_mandate_read_back_from_kmoney(): void
    {
        [$user, $link] = $this->linkedWithoutMandate();
        Http::fake([
            'kmoney.test/api/v1/mandates' => Http::response(['mandates' => [['uuid' => 'M-1']]]),
            'kmoney.test/api/v1/userinfo' => Http::response(['account' => ['number' => 'KYP0000000001']]),
        ]);

        $this->actingAs($user)->withSession(['kmoney_from' => 'profilo'])
            ->get('/area/kmoney/ritorno?mandate=granted')
            ->assertRedirect('/area/profilo#kmoney')
            ->assertSessionHas('status');

        $this->assertSame('M-1', $link->refresh()->mandate_uuid);
    }

    public function test_the_granted_parameter_alone_is_not_trusted(): void
    {
        [$user, $link] = $this->linkedWithoutMandate();
        Http::fake([
            'kmoney.test/api/v1/mandates' => Http::response(['mandates' => []]),
            'kmoney.test/api/v1/userinfo' => Http::response(['account' => ['number' => 'KYP0000000001']]),
        ]);

        $this->actingAs($user)->withSession(['kmoney_from' => 'profilo'])
            ->get('/area/kmoney/ritorno?mandate=granted')
            ->assertRedirect('/area/profilo#kmoney')
            ->assertSessionHasErrors('kmoney');

        $this->assertNull($link->refresh()->mandate_uuid);
    }

    public function test_refusing_the_mandate_shows_a_message_and_does_not_loop_back_to_kmoney(): void
    {
        [$user, $link] = $this->linkedWithoutMandate();
        Http::fake();

        $this->actingAs($user)->withSession(['kmoney_from' => 'profilo'])
            ->get('/area/kmoney/ritorno?mandate=denied')
            ->assertRedirect('/area/profilo#kmoney')
            ->assertSessionHasErrors('kmoney');

        Http::assertNothingSent();
        $this->assertNull($link->refresh()->mandate_uuid);
    }
}
