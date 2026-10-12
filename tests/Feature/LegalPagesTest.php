<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_le_pagine_legali_sono_pubbliche(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Privacy Policy')->assertSee('INHORECA.IT');
        $this->get('/termini')->assertOk()->assertSee('Termini e Condizioni');
        $this->get('/politiche-di-reso')->assertOk()->assertSee('Informativa sui rimborsi');
        $this->get('/terms')->assertRedirect('/termini');
    }

    public function test_footer_con_dati_societari_e_link(): void
    {
        $this->get('/privacy')->assertSee('P.IVA 02530190517', false)->assertSee('kadeogroup@pec.it')->assertSee('href="/politiche-di-reso"', false);
    }
}
