<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportReconciliation extends Model
{
    protected $guarded = [];

    public const CODES = [
        'fattura_senza_ordine' => 'Fattura senza ordine',
        'fattura_agganciata_per_importo' => 'Fattura agganciata per importo',
        'fattura_importo_diverso' => 'Totale fattura ≠ ordine',
        'fattura_sdi_rifiutata' => 'Fattura rifiutata dallo SdI',
        'fattura_inoltro_fallito' => 'Inoltro al cliente fallito',
        'fattura_non_pagata' => 'Fattura non pagata',
        'fattura_bozza_non_importata' => 'Bozza non importata',
        'nota_credito_non_collegata' => 'Nota di credito non importata',
        'nota_credito_collegata' => 'Nota di credito collegata',
        'ordine_senza_acquirente' => 'Ordine senza acquirente',
        'ordine_da_fatturare' => 'Ordine da fatturare',
        'ordine_senza_fattura_con_importo' => 'Ordine con importo, nulla da fatturare',
        'ordine_con_piu_fatture' => 'Ordine con più fatture',
        'ordine_senza_movimenti' => 'Ordine senza movimenti PV',
        'ordine_pr_diverso_movimenti' => 'PR ordine ≠ PV movimenti',
        'ordine_importo_senza_pr' => 'Importo senza PR',
        'movimento_ordine_mancante' => 'Movimenti con ordine mancante',
        'movimento_fattura_mancante' => 'Movimenti con fattura mancante',
        'preventivo_non_importato' => 'Preventivo non importato',
        'verifica_rank_diverso' => 'Verifica motore: qualifica diversa',
        'verifica_pr_diverso' => 'Verifica motore: PR diversi',
    ];

    protected function casts(): array
    {
        return ['odoo' => 'array', 'portal' => 'array', 'resolved_at' => 'immutable_datetime'];
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
