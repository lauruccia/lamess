<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\KMoneyApiProvider;
use App\Domain\Orders\KMoneyProvider;
use App\Domain\Orders\OrderService;
use App\Domain\Subscriptions\SubscriptionService;
use App\Models\KMoneyLink;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\ShopDb;
use Tests\TestCase;

/**
 * Abbonamento pagato con KMoney: la copia che Lamèss manda a KMoney (così il cliente la vede e può bloccarla) e il blocco del cliente.
 *
 * Due regole sorvegliate: la copia non deve MAI fermare un addebito o una disdetta, e un abbonamento bloccato su KMoney va in pausa subito,
 * senza consumare i tentativi e senza lasciare un ordine appeso.
 */
final class SubscriptionKMoneySyncTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private bool $blocked = false;

    private int $syncStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config([
            'lamess.kmoney_driver' => 'api',
            'lamess.payments.kmoney' => true,
            'lamess.kmoney.base_url' => 'https://kmoney.test',
            'lamess.kmoney.client_id' => 'kshop-prova',
            'lamess.kmoney.client_secret' => 'segreto',
            'lamess.kmoney.seller_account' => 'KYBPROVA0000001',
        ]);
        $this->app->bind(KMoneyProvider::class, fn () => new KMoneyApiProvider);
        $this->app->forgetInstance(OrderService::class);
        $this->plan();
        $this->shipping('10.00');
        $this->openPeriod(now('Europe/Rome')->format('Y-m'));
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/charge')) {
                return $this->blocked
                    ? Http::response(['status' => 'refused', 'reason' => 'subscription_blocked'], 403)
                    : Http::response(['status' => 'booked', 'transfer_uuid' => 'T-'.Str::random(6)], 200);
            }
            if (str_contains($r->url(), '/api/v1/subscriptions/')) {
                return Http::response(['blocked' => false], $this->syncStatus);
            }

            return Http::response([], 404);
        });
    }

    private function subs(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    private function start(): CarbonImmutable
    {
        $t = now('Europe/Rome')->toImmutable()->startOfMonth()->addDays(9)->setTime(10, 0);
        $this->travelTo($t);

        return $t;
    }

    private function linked(User $user): void
    {
        // Scadenza lontana: i test viaggiano di un mese e un token scaduto verrebbe rinnovato (e il collegamento perso).
        KMoneyLink::create(['user_id' => $user->id, 'access_token' => 'at', 'refresh_token' => 'rt', 'expires_at' => now()->addYears(2), 'mandate_uuid' => 'M-1', 'account_number' => 'KYPTEST0000001']);
    }

    /** @return array<string,mixed> corpo dell'ultima copia mandata a KMoney */
    private function lastSync(): array
    {
        $puts = Http::recorded(fn (Request $r) => $r->method() === 'PUT')->map(fn (array $pair) => $pair[0]);
        $this->assertTrue($puts->isNotEmpty(), 'nessuna copia mandata a KMoney');

        return $puts->last()->data();
    }

    private function subscribed(): Subscription
    {
        $u = $this->buyer($this->activeSponsor());
        $this->linked($u);
        $p = $this->product('A', '123.00', ['kmoney_percent' => 100]);
        $this->start();
        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0, [], null, 'kmoney');
        $this->assertSame(1, $sub->cycles_done, (string) $sub->last_error);

        return $sub;
    }

    public function test_the_cycle_sends_the_copy_to_kmoney_and_declares_the_subscription_in_the_charge(): void
    {
        $sub = $this->subscribed();
        $cents = (int) round((float) Order::where('subscription_id', $sub->id)->value('kmoney_amount') * 100);
        $this->assertGreaterThan(0, $cents);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), "/api/v1/subscriptions/{$sub->id}")
            && $r['status'] === 'active'
            && $r['title'] === '1 prodotto al mese'
            && $r['items'] === ['1 × Prodotto A']
            && $r['amount'] === $cents
            && $r['seller_account_number'] === 'KYBPROVA0000001'
            && $r['next_charge_at'] !== null);
        // Il ciclo si lega all'abbonamento: è ciò che permette a KMoney di rifiutarlo se il cliente lo ha bloccato.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/charge') && $r['subscription_id'] === (string) $sub->id);
    }

    public function test_the_copy_is_sent_before_the_charge_so_kmoney_knows_the_subscription(): void
    {
        $this->subscribed();

        $urls = Http::recorded()->map(fn (array $pair) => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();
        $firstPut = array_search(true, array_map(fn ($u) => str_starts_with($u, 'PUT '), $urls), true);
        $firstCharge = array_search(true, array_map(fn ($u) => str_contains($u, '/charge'), $urls), true);
        $this->assertNotFalse($firstPut);
        $this->assertNotFalse($firstCharge);
        $this->assertLessThan($firstCharge, $firstPut);
    }

    public function test_a_subscription_blocked_on_kmoney_pauses_at_once_without_burning_retries(): void
    {
        $sub = $this->subscribed();
        $start = CarbonImmutable::instance($sub->started_at);

        $this->blocked = true;
        $this->travelTo($start->addMonthNoOverflow());
        $this->assertSame(1, $this->subs()->runDue()['paused']);

        $sub->refresh();
        $this->assertSame('paused', $sub->status);
        $this->assertSame(0, $sub->retry_count, 'un blocco del cliente non è un addebito fallito da ritentare');
        $this->assertSame(1, $sub->cycles_done);
        $this->assertNull($sub->next_run_at);
        $this->assertStringContainsString('bloccato', (string) $sub->last_error);
        $this->assertSame(0, Order::where('subscription_id', $sub->id)->where('status', 'pending_payment')->count(), 'nessun ordine appeso');
        $this->assertSame('paused', $this->lastSync()['status']);
        $this->assertStringContainsString('bloccato', (string) $this->lastSync()['last_error']);
    }

    public function test_resuming_while_still_blocked_stays_paused(): void
    {
        $sub = $this->subscribed();
        $this->blocked = true;
        $this->travelTo(CarbonImmutable::instance($sub->started_at)->addMonthNoOverflow());
        $this->subs()->runDue();

        $this->subs()->resume($sub->refresh());

        $this->assertSame('paused', $sub->refresh()->status);
        $this->assertSame(1, $sub->cycles_done);
    }

    public function test_a_failing_copy_never_stops_the_charge(): void
    {
        $this->syncStatus = 500;

        $sub = $this->subscribed();

        $this->assertSame('active', $sub->status);
        $this->assertSame(1, Order::where('subscription_id', $sub->id)->where('status', 'paid')->count());
    }

    public function test_skip_pause_and_cancel_update_the_copy(): void
    {
        $sub = $this->subscribed();

        $this->subs()->skipNext($sub->refresh());
        $this->assertSame($sub->refresh()->next_run_at->toIso8601String(), $this->lastSync()['next_charge_at']);

        $this->subs()->pause($sub->refresh());
        $this->assertSame('paused', $this->lastSync()['status']);
        $this->assertNull($this->lastSync()['next_charge_at']);

        $this->subs()->cancel($sub->refresh());
        $this->assertSame('cancelled', $this->lastSync()['status']);
    }

    public function test_a_card_subscription_never_talks_to_kmoney(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $this->linked($u);
        $p = $this->product('A', '10.00');
        $sub = Subscription::create([
            'user_id' => $u->id, 'status' => 'active', 'lines' => [$p->id => 1], 'country_code' => 'IT', 'billing' => $this->billing(),
            'payment_method' => 'card', 'started_at' => now(), 'next_run_at' => now(),
        ]);

        $this->subs()->pause($sub);

        Http::assertNothingSent();
    }

    public function test_the_backfill_command_sends_only_live_kmoney_subscriptions(): void
    {
        $p = $this->product('A', '10.00');
        $make = function (string $method, string $status) use ($p): Subscription {
            $u = $this->buyer();
            $this->linked($u);

            return Subscription::create([
                'user_id' => $u->id, 'status' => $status, 'lines' => [$p->id => 1], 'country_code' => 'IT', 'billing' => $this->billing(),
                'payment_method' => $method, 'started_at' => now(), 'next_run_at' => now(),
            ]);
        };
        $active = $make('kmoney', 'active');
        $paused = $make('kmoney', 'paused');
        $make('kmoney', 'cancelled');
        $make('card', 'active');

        $this->artisan('lamess:abbonamenti-kmoney')->expectsOutputToContain('inviati: 2')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/api/v1/subscriptions/{$active->id}") && $r['status'] === 'active');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/api/v1/subscriptions/{$paused->id}") && $r['status'] === 'paused');
    }

    public function test_a_customer_without_a_kmoney_link_is_skipped_quietly(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '10.00');
        $sub = Subscription::create([
            'user_id' => $u->id, 'status' => 'active', 'lines' => [$p->id => 1], 'country_code' => 'IT', 'billing' => $this->billing(),
            'payment_method' => 'kmoney', 'started_at' => now(), 'next_run_at' => now(),
        ]);

        $this->subs()->pause($sub);

        $this->assertSame('paused', $sub->refresh()->status);
        Http::assertNothingSent();
    }
}
