<?php

declare(strict_types=1);

namespace Tests\Feature\Stability;

use App\Models\Order;
use App\Notifications\OrderPaid;
use App\Notifications\ResetPasswordIt;
use App\Notifications\SignatureOtpCode;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Stabilità e colli di bottiglia: immagini con cache, email in coda, lavoro di coda pianificato solo se serve. */
final class StabilityAndPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_image_is_served_with_long_cache_and_answers_304_when_unchanged(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('shop/products/foto.png', 'png-bytes');

        $first = $this->get('/media/shop/products/foto.png')->assertOk();
        $first->assertHeader('Cache-Control', 'max-age=2592000, public');
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->get('/media/shop/products/foto.png', ['If-None-Match' => $etag])->assertStatus(304);
        $this->get('/media/shop/products/manca.png')->assertNotFound();
    }

    public function test_customer_notifications_are_queued_after_commit_but_login_codes_stay_immediate(): void
    {
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, new OrderPaid(new Order));
        $this->assertNotInstanceOf(ShouldQueue::class, new SignatureOtpCode('123456', 15));
        $this->assertNotInstanceOf(ShouldQueue::class, new ResetPasswordIt('token'));
    }

    public function test_queue_worker_is_scheduled_only_when_queue_is_not_sync(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => $e->description === 'lamess:coda');
        $this->assertNotNull($event);

        config(['queue.default' => 'sync']);
        $this->assertFalse($event->filtersPass(app()));
        config(['queue.default' => 'database']);
        $this->assertTrue($event->filtersPass(app()));
    }
}
