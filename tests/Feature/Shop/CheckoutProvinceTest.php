<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** La provincia scritta per esteso («Macerata») diventa la sigla invece di far fallire il salvataggio. */
final class CheckoutProvinceTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan();
        $this->shipping('10.00');
        $this->openPeriod(now('Europe/Rome')->format('Y-m'));
    }

    private function save(array $over): TestResponse
    {
        $user = $this->buyer($this->activeSponsor());
        Profile::where('user_id', $user->id)->update(['address' => null, 'city' => null, 'zip' => null, 'tax_code' => null, 'province' => null]);
        $p = $this->product('A', '100.00');
        $this->actingAs($user);
        $this->userUnderTest = $user;

        return $this->withSession(['cart' => [$p->id => 1]])->post('/area/checkout/dati', $over + $this->billing() + ['payment_method' => 'transfer', 'privacy' => '1', 'subscribe' => 'none']);
    }

    private $userUnderTest;

    public function test_a_province_written_in_full_is_saved_as_the_code(): void
    {
        $this->save(['province' => 'Macerata', 'country' => 'IT'])->assertSessionHasNoErrors();
        $this->assertSame('MC', $this->userUnderTest->profile->refresh()->province);
    }

    public function test_an_unknown_italian_province_is_a_clear_error_not_a_crash(): void
    {
        $this->save(['province' => 'Atlantide', 'country' => 'IT'])->assertSessionHasErrors('province');
    }

    public function test_a_long_foreign_province_is_left_empty(): void
    {
        $this->save(['province' => 'Île-de-France', 'country' => 'FR', 'tax_code' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->userUnderTest->profile->refresh()->province);
    }
}
