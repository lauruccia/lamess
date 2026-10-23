<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\Order;
use App\Notifications\OrderPaid;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Email ai clienti: importi in KY senza decimali per KMoney, saluti e piè di pagina in italiano. */
final class OrderEmailTextTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    public function test_ky_amounts_drop_the_decimals_only_when_whole(): void
    {
        $this->assertSame('1.500', Money::ky('1500.00'));
        $this->assertSame('1.500,50', Money::ky('1500.5'));
        $this->assertSame('0', Money::ky('0'));
    }

    public function test_payment_received_mail_shows_ky_for_kmoney_and_euros_otherwise(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $km = new Order(['payment_method' => 'kmoney', 'total' => '1500.00', 'kmoney_amount' => '1500.00', 'number' => 'ORD-1']);
        $card = new Order(['payment_method' => 'card', 'total' => '150.00', 'number' => 'ORD-2']);
        $mixed = new Order(['payment_method' => 'kmoney', 'total' => '200.00', 'kmoney_amount' => '150.00', 'kmoney_rest_method' => 'card', 'number' => 'ORD-3']);

        $this->assertSame('1.500 KY', (new OrderPaid($km))->paidText());
        $this->assertSame('€ 150,00', (new OrderPaid($card))->paidText());
        $this->assertSame('150 KY (KMoney) più € 50,00 con carta', (new OrderPaid($mixed))->paidText());
        $this->assertStringContainsString('pagamento di 1.500 KY per', implode(' ', (new OrderPaid($km))->toMail($user)->introLines));
    }

    public function test_every_mail_has_italian_salutation_and_the_company_footer(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $html = (string) (new OrderPaid(new Order(['payment_method' => 'card', 'total' => '10.00', 'number' => 'ORD-9'])))->toMail($user)->render();

        $this->assertStringContainsString('Cordiali saluti', $html);
        $this->assertStringContainsString('Se il pulsante', $html);
        $this->assertStringContainsString('Tutti i diritti riservati', $html);
        $this->assertStringContainsString('P.IVA 02530190517', $html);
        $this->assertStringNotContainsString('Regards', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
        $this->assertStringNotContainsString('trouble clicking', $html);
    }
}
