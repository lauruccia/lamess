<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HowItWorksPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_is_public_and_shows_the_plan(): void
    {
        $this->markTestSkipped('Pagina /come-funziona nascosta.');
        $this->get('/come-funziona')
            ->assertOk()
            ->assertSee('Come funziona, in due minuti.', false)
            ->assertSee('Prova tu.', false)
            ->assertSee('Cosa trovi nella tua area riservata.', false)
            // scaglioni e livelli del piano Incaricati (file di piano, senza piano in vigore nel database)
            ->assertSee('dal 10% al 50%', false)
            ->assertSee('I 5 livelli della rete', false)
            ->assertSee('Pearl', false)
            ->assertSee('non garantiscono alcun guadagno', false)
            ->assertSee('href="/registrazione"', false);
    }

    public function test_euro_amounts_are_hidden_unless_enabled(): void
    {
        $this->markTestSkipped('Pagina /come-funziona nascosta.');
        $this->get('/come-funziona')->assertOk()->assertDontSee('148,50', false)->assertSee('€ •••', false);

        config(['lamess.how_it_works_show_euro' => true]);
        $this->get('/come-funziona')->assertOk()->assertSee('148,50', false);
    }

    public function test_referral_code_is_kept_on_the_links(): void
    {
        $this->markTestSkipped('Pagina /come-funziona nascosta.');
        $this->get('/come-funziona?ref=LM00001')->assertOk()->assertSee('href="/registrazione?ref=LM00001"', false);
    }

    public function test_registration_page_has_the_benefits_popup_and_no_link_to_the_network_presentation(): void
    {
        $this->get('/registrazione?ref=LM00001')
            ->assertOk()
            ->assertSee('id="benefits"', false)
            ->assertSee('data-benefits', false)
            ->assertSee('Dosi chiare, scritte in etichetta', false)
            ->assertSee('Zero zuccheri', false)
            // la presentazione del sistema multilevel non si raggiunge dalla pagina referral
            ->assertDontSee('/come-funziona', false)
            ->assertDontSee('Come funziona', false);
    }

    public function test_work_with_us_page_links_to_the_presentation(): void
    {
        $this->markTestSkipped('Pagina /come-funziona nascosta.');
        $this->get('/lavora-con-noi')->assertOk()->assertSee('href="/come-funziona"', false);
    }

    public function test_benefits_popup_has_the_falling_beans(): void
    {
        $this->get('/registrazione')->assertOk()->assertSee('class="bn-beans"', false)->assertSee('id="bn-bean"', false)->assertSee('class="bn-scene"', false)->assertSee('class="bn-temp"', false);
    }
}
