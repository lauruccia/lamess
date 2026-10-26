<x-filament-panels::page>
    <div class="fi-section" style="padding:1.25rem 1.5rem;font-size:.9rem">
        <p style="margin:0"><strong>Versione in vigore: {{ $label }}</strong>@if($version && $version->revision > 0) · {{ $version->revision }} {{ $version->revision === 1 ? 'correzione di refuso' : 'correzioni di refuso' }}@endif @if($version?->published_at) · pubblicata il {{ $version->published_at->setTimezone('Europe/Rome')->format('d/m/Y') }}@endif</p>
        <ul style="margin:.6rem 0 0;padding-left:1.2rem;list-style:disc;opacity:.85">
            <li><strong>Errore di battitura</strong> → «Salva correzione (refuso)»: stessa versione, nessuno deve rifirmare.</li>
            <li><strong>Cambia il contenuto</strong> (importi, durata, obblighi, articoli…) → «Salva modifica contrattuale»: nasce una nuova versione da far firmare.</li>
            <li>Poi premi <strong>«Invia da firmare»</strong>: la lettera va a tutti gli incaricati registrati che non l'hanno ancora firmata.</li>
        </ul>
        @unless($mailOn)<p style="margin:.6rem 0 0;color:#b0701a"><strong>Invio email spento:</strong> le richieste di firma restano nell'area riservata degli incaricati, ma non parte nessuna email.</p>@endunless
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:.75rem">
        <div class="fi-section" style="padding:1rem 1.25rem"><div style="font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;opacity:.65">Hanno firmato</div><div style="font-size:1.6rem;font-weight:600">{{ $counts['signed'] }}</div></div>
        <div class="fi-section" style="padding:1rem 1.25rem"><div style="font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;opacity:.65">Da firmare (inviata)</div><div style="font-size:1.6rem;font-weight:600">{{ $counts['pending'] }}</div></div>
        <div class="fi-section" style="padding:1rem 1.25rem"><div style="font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;opacity:.65">Non ancora inviata a</div><div style="font-size:1.6rem;font-weight:600">{{ $counts['eligible'] }}</div></div>
    </div>

    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <div class="fi-section" style="padding:0;overflow:hidden">
        <div style="padding:.9rem 1.25rem;font-weight:600">Firme della versione {{ $label }}</div>
        @if($signatures->isEmpty())
            <p style="padding:0 1.25rem 1.25rem;margin:0;opacity:.7">Nessuna richiesta inviata per questa versione.</p>
        @else
        <table style="width:100%;border-collapse:collapse;font-size:.9rem">
            <tr style="text-align:left;font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;opacity:.65"><th style="padding:.6rem 1.25rem">Incaricato</th><th>Stato</th><th>Inviata</th><th>Vista</th><th>Firmata</th><th></th></tr>
            @foreach($signatures as $s)
                <tr style="border-top:1px solid rgba(187,180,175,.35)">
                    <td style="padding:.6rem 1.25rem">{{ $s->consultant?->name }} <span style="opacity:.6">{{ $s->consultant?->code }}</span></td>
                    <td>{{ ['pending' => 'Da firmare', 'signed' => 'Firmata', 'cancelled' => 'Annullata'][$s->status] ?? $s->status }}</td>
                    <td>{{ $s->sent_at?->setTimezone('Europe/Rome')->format('d/m/Y') ?? '—' }}</td>
                    <td>{{ $s->viewed_at?->setTimezone('Europe/Rome')->format('d/m/Y') ?? '—' }}</td>
                    <td>{{ $s->signed_at?->setTimezone('Europe/Rome')->format('d/m/Y H:i') ?? '—' }}</td>
                    <td style="padding-right:1.25rem;text-align:right">@if($s->pdf_path)<a href="/admin-files/letter-signature/{{ $s->id }}" target="_blank" style="color:#894C2D;font-weight:600">Lettera firmata</a>@endif</td>
                </tr>
            @endforeach
        </table>
        @endif
    </div>

    <div class="fi-section" style="padding:0;overflow:hidden">
        <div style="padding:.9rem 1.25rem;font-weight:600">Storico delle modifiche</div>
        @if($edits->isEmpty())
            <p style="padding:0 1.25rem 1.25rem;margin:0;opacity:.7">Il testo non è mai stato modificato da qui: vale il testo di partenza.</p>
        @else
        <table style="width:100%;border-collapse:collapse;font-size:.9rem">
            <tr style="text-align:left;font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;opacity:.65"><th style="padding:.6rem 1.25rem">Quando</th><th>Versione</th><th>Tipo</th><th>Parole</th><th>Note</th><th>Chi</th></tr>
            @foreach($edits as $e)
                <tr style="border-top:1px solid rgba(187,180,175,.35)">
                    <td style="padding:.6rem 1.25rem">{{ $e->created_at->setTimezone('Europe/Rome')->format('d/m/Y H:i') }}</td>
                    <td>{{ $e->version?->version }}</td>
                    <td>{{ ['initial' => 'Testo di partenza', 'typo' => 'Correzione refuso', 'contractual' => 'Modifica contrattuale'][$e->kind] ?? $e->kind }}</td>
                    <td>{{ $e->words_changed ?: '—' }}</td>
                    <td>{{ $e->summary ?: '—' }}</td>
                    <td>{{ $e->actor }}</td>
                </tr>
            @endforeach
        </table>
        @endif
    </div>
</x-filament-panels::page>
