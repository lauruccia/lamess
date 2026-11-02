<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_front_pages_are_public(): void
    {
        $pages = [
            '/il-prodotto' => 'Ingredienti',
            '/perche-lamess' => 'Lamèss',
            '/la-scienza' => 'La scienza dietro Lamèss',
            '/lavora-con-noi' => 'incaricato',
            '/chi-siamo' => 'Chi siamo',
            '/domande' => 'Domande frequenti',
            '/contatti' => 'info@lamess.it',
        ];
        foreach ($pages as $url => $text) {
            $this->get($url)->assertOk()->assertSee($text, false);
        }
    }

    public function test_front_menu_links_to_shop_and_login(): void
    {
        $this->get('/domande')
            ->assertSee('href="/negozio"', false)
            ->assertSee('href="/accedi"', false)
            ->assertSee('href="/registrazione"', false);
    }
}
