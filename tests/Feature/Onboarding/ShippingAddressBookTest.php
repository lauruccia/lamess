<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Customers\ShippingAddressBook;
use App\Domain\Orders\OrderException;
use App\Models\ShippingAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

/** Rubrica indirizzi di spedizione: massimo 10 per utente, separata dalla fatturazione. */
final class ShippingAddressBookTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    private function data(int $n = 1, array $over = []): array
    {
        return $over + ['label' => "Casa {$n}", 'address' => "Via Roma {$n}", 'city' => 'Reggio Calabria', 'zip' => '89100', 'province' => 'RC'];
    }

    public function test_first_address_is_default_and_limit_is_ten(): void
    {
        $user = $this->fan()->user;
        $book = app(ShippingAddressBook::class);

        for ($i = 1; $i <= 10; $i++) {
            $a = $book->add($user, $this->data($i));
            $this->assertSame($i === 1, $a->is_default);
        }
        $this->assertCount(10, $book->list($user));

        $this->expectException(OrderException::class);
        $book->add($user, $this->data(11));
    }

    public function test_default_moves_and_deleting_the_default_promotes_another(): void
    {
        $user = $this->fan()->user;
        $book = app(ShippingAddressBook::class);
        $a = $book->add($user, $this->data(1));
        $b = $book->add($user, $this->data(2));

        $book->makeDefault($user, $b);
        $this->assertTrue($b->refresh()->is_default);
        $this->assertFalse($a->refresh()->is_default);

        $book->remove($user, $b);
        $this->assertTrue($a->refresh()->is_default);
    }

    public function test_cannot_touch_someone_elses_address(): void
    {
        $mine = $this->fan();
        $other = $this->fan();
        $book = app(ShippingAddressBook::class);
        $theirs = $book->add($other->user, $this->data(1));

        $this->expectException(OrderException::class);
        $book->remove($mine->user, $theirs);
    }

    public function test_resolve_billing_saved_and_new(): void
    {
        $user = $this->fan()->user;
        $book = app(ShippingAddressBook::class);
        $billing = ['address' => 'Via Fatture 9', 'city' => 'Messina', 'zip' => '98100', 'province' => 'ME'];
        $saved = $book->add($user, $this->data(1));

        $this->assertSame('Via Fatture 9', $book->resolve($user, ['ship_to' => 'billing'], $billing)['address']);
        $this->assertSame('Via Roma 1', $book->resolve($user, ['ship_to' => (string) $saved->id], $billing)['address']);

        // Nuovo senza salvare: vale solo per l'ordine
        $r = $book->resolve($user, ['ship_to' => 'new', 'ship_address' => 'Via Mare 3', 'ship_city' => 'Scilla', 'ship_zip' => '89058'], $billing);
        $this->assertSame('Via Mare 3', $r['address']);
        $this->assertSame(1, ShippingAddress::where('user_id', $user->id)->count());

        // Nuovo con salvataggio
        $r = $book->resolve($user, ['ship_to' => 'new', 'ship_save' => '1', 'ship_label' => 'Mare', 'ship_address' => 'Via Mare 3', 'ship_city' => 'Scilla', 'ship_zip' => '89058'], $billing);
        $this->assertNotNull($r['id']);
        $this->assertSame(2, ShippingAddress::where('user_id', $user->id)->count());
    }

    public function test_resolve_refuses_an_address_of_another_user_and_incomplete_new(): void
    {
        $user = $this->fan()->user;
        $other = $this->fan();
        $book = app(ShippingAddressBook::class);
        $theirs = $book->add($other->user, $this->data(1));

        try {
            $book->resolve($user, ['ship_to' => (string) $theirs->id], []);
            $this->fail('Doveva rifiutare l\'indirizzo altrui.');
        } catch (OrderException) {
            $this->assertTrue(true);
        }
        $this->expectException(OrderException::class);
        $book->resolve($user, ['ship_to' => 'new', 'ship_address' => 'Via X'], []);
    }
}
