<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Onboarding\IvdService;
use App\Domain\Onboarding\OnboardingException;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

/** Impostazione «prima box obbligatoria per diventare incaricato»: spenta di default (PMK), accesa da Impostazioni di sistema. */
final class FirstBoxRequirementTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    private function order(int $userId, string $status, bool $paid): Order
    {
        static $n = 0;
        $n++;

        return Order::create([
            'number' => "ORD-TEST-{$n}", 'buyer_user_id' => $userId, 'buyer_type' => 'customer', 'status' => $status,
            'payment_method' => 'card', 'subtotal_net' => '123.00', 'vat_total' => '27.06', 'total' => '150.06',
            'paid_at' => $paid ? now() : null, 'billing' => [],
        ]);
    }

    public function test_off_by_default_no_purchase_needed(): void
    {
        $fan = $this->fan($this->activeSponsor());

        $this->assertTrue(app(IvdService::class)->hasPaidFirstBox($fan->user));
        $this->assertNotNull(app(IvdService::class)->apply($fan->user, $this->ivdData(), null));
    }

    public function test_when_on_application_is_blocked_until_first_box_is_paid(): void
    {
        config(['lamess.require_first_box_to_apply' => true]);
        $sponsor = $this->activeSponsor();
        $fan = $this->fan($sponsor);
        $svc = app(IvdService::class);

        $this->assertFalse($svc->hasPaidFirstBox($fan->user));
        try {
            $svc->apply($fan->user, $this->ivdData(), null);
            $this->fail('La richiesta senza prima box doveva essere rifiutata.');
        } catch (OnboardingException $e) {
            $this->assertStringContainsString('prima box', $e->getMessage());
        }

        // Ordine non pagato, annullato o rimborsato: non basta
        $this->order($fan->user_id, 'pending_payment', false);
        $this->order($fan->user_id, 'cancelled', true);
        $this->order($fan->user_id, 'refunded', true);
        $this->assertFalse($svc->hasPaidFirstBox($fan->user));

        $this->order($fan->user_id, 'paid', true);
        $contract = $svc->apply($fan->user, $this->ivdData(), null);
        $this->assertSame($sponsor->id, $contract->sponsor_id); // resta lo sponsor del link referral
    }
}
