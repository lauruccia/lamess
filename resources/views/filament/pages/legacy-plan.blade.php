<x-filament-panels::page>
    <div class="fi-section" style="padding:1.25rem 1.5rem;font-size:.9rem">
        <p style="margin:0;font-size:1rem">
            Stato attuale:
            @if($hidden)
                <strong style="color:#b45309">il piano precedente è NASCOSTO</strong> a tutti.
            @else
                <strong style="color:#15803d">il piano precedente è VISIBILE</strong>.
            @endif
        </p>
        <p style="margin:.6rem 0 0;opacity:.8">
            Con un solo pulsante (in alto a destra) spariscono dal portale tutti i riferimenti al piano precedente: punti e relative colonne,
            schede e menu dedicati, bonus del vecchio schema, riepiloghi nell'area degli incaricati e nei documenti.
            Gli importi già maturati restano nei totali, raccolti sotto «{{ \App\Domain\Plan\LegacyPlan::NEUTRAL_LABEL }}».
        </p>
        <p style="margin:.6rem 0 0;opacity:.8"><strong>Non si cancella e non si ricalcola nulla.</strong> Movimenti, periodi chiusi, commissioni e versioni del piano restano nel database e tornano visibili appena si rimostra.
            Ogni cambio finisce nel log di audit.</p>
    </div>
</x-filament-panels::page>
