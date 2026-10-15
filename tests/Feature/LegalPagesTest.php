<?php

namespace Tests\Feature;

use App\Domain\Engine\Plan\PlanLoader;
use App\Models\CompPlanVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_il_piano_compensi_si_genera_dal_piano_attivo(): void
    {
        $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);
        $data['kosmos']['direct_tiers'][1]['pct'] = 17;
        CompPlanVersion::createFromArray('Piano di prova', $data, CompPlanVersion::ACTIVE);

        $this->get('/piano-compensi')->assertOk()->assertSee('Il piano compensi')->assertSee('17%')->assertSee('Bonus di Struttura')
            ->assertSee('Compressione della linea')->assertSee('Piano di prova');
    }

    public function test_il_piano_compensi_senza_piano_attivo_non_si_rompe(): void
    {
        $this->get('/piano-compensi')->assertOk()->assertSee('sarà pubblicato');
    }
}
