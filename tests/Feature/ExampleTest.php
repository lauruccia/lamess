<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase; // la home legge i prodotti: serve il database di prova

    public function test_home_is_a_public_page_with_login_and_register(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Stop Time')
            ->assertSee('href="/accedi"', false)
            ->assertSee('href="/registrazione"', false);
    }
}
