<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\PaymentProvider;
use App\Domain\Orders\PaymentResult;
use App\Domain\Subscriptions\SubscriptionService;
use App\Models\Order;
use App\Models\Subscription;
use App\Notifications\SubscriptionChargeReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class SubscriptionReminderTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->plan();
        $this->shipping('10.00');
        $this->openPeriod(now('Europe/Rome')->format('Y-m'));
        $this->app->instance(PaymentProvider::class, new class implements PaymentProvider
        {
            public function name(): string
            {
                return 'test';
            }

            public function charge(Order $order, array $details = []): PaymentResult
            {
                return new PaymentResult(true, 'T-'.$order->id);
            }
        });
    }

    private function subscription(): Subscription
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');

        return app(SubscriptionService::class)->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 12);
    }

    public function test_reminder_goes_out_seven_days_before_the_charge_and_only_once(): void
    {
        $sub = $this->subscription();
        $svc = app(SubscriptionService::class);

        $this->travelTo($sub->next_run_at->subDays(8));
        $this->assertSame(0, $svc->sendReminders(), 'troppo presto: manca più di una settimana');

        $this->travelTo($sub->next_run_at->subDays(6));
        $this->assertSame(1, $svc->sendReminders());
        Notification::assertSentTo($sub->user, SubscriptionChargeReminder::class);

        $this->assertSame(0, $svc->sendReminders(), 'una sola email per scadenza');
        Notification::assertSentToTimes($sub->user, SubscriptionChargeReminder::class, 1);
    }

    public function test_paused_or_failing_subscriptions_get_no_reminder(): void
    {
        $sub = $this->subscription();
        $svc = app(SubscriptionService::class);
        $this->travelTo($sub->next_run_at->subDays(3));

        $sub->update(['status' => Subscription::PAUSED]);
        $this->assertSame(0, $svc->sendReminders());

        $sub->update(['status' => Subscription::ACTIVE, 'retry_count' => 1]);
        $this->assertSame(0, $svc->sendReminders(), 'con un addebito già fallito parte l\'email di errore, non il promemoria');
    }

    public function test_skipping_a_month_moves_the_reminder_to_the_new_date(): void
    {
        $sub = $this->subscription();
        $svc = app(SubscriptionService::class);
        $this->travelTo($sub->next_run_at->subDays(5));
        $this->assertSame(1, $svc->sendReminders());

        $sub = $svc->skipNext($sub->refresh());
        $this->assertSame(0, $svc->sendReminders(), 'il nuovo addebito è tra oltre una settimana');

        $this->travelTo($sub->next_run_at->subDays(5));
        $this->assertSame(1, $svc->sendReminders());
    }

    public function test_reminder_can_be_switched_off(): void
    {
        config(['lamess.subscription.reminder_days' => 0]);
        $sub = $this->subscription();
        $this->travelTo($sub->next_run_at->subDays(1));

        $this->assertSame(0, app(SubscriptionService::class)->sendReminders());
    }
}
