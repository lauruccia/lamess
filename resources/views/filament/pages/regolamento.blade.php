<x-filament-panels::page>
    <div class="fi-section" style="padding:1.25rem 1.5rem;font-size:.9rem">
        <p style="margin:0"><strong>Versione in vigore: {{ $version }}</strong>@if($row && $row->revision > 0) · {{ $row->revision }} {{ $row->revision === 1 ? 'correzione di refuso' : 'correzioni di refuso' }}@endif</p>
        <ul style="margin:.6rem 0 0;padding-left:1.2rem;list-style:disc;opacity:.85">
            <li>È l'<strong>Allegato 1</strong> della Lettera di Incarico: chi firma lo legge e lo accetta con una casella dedicata (con gli articoli da approvare ex artt. 1341-1342 c.c.).</li>
            <li>Il testo è anche la pagina pubblica <strong>/regolamento</strong> e viene allegato al PDF della lettera firmata, con versione e impronta nelle prove di firma.</li>
            <li><strong>Refuso</strong> → «Salva correzione». <strong>Cambia il contenuto</strong> → «Pubblica nuova versione».</li>
        </ul>
    </div>

    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <div class="fi-section" style="padding:0;overflow:hidden">
        <div style="padding:.9rem 1.25rem;font-weight:600">Storico delle modifiche</div>
        @if($edits->isEmpty())
            <p style="padding:0 1.25rem 1.25rem;margin:0;opacity:.7">Il testo non è mai stato modificato da qui: vale il testo di partenza.</p>
        @else
        <table style="width:100%;border-collapse:collapse;font-size:.9rem">
            <tr style="text-align:left;font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;opacity:.65"><th style="padding:.6rem 1.25rem">Quando</th><th>Versione</th><th>Tipo</th><th>Note</th><th>Chi</th></tr>
            @foreach($edits as $e)
                <tr style="border-top:1px solid rgba(187,180,175,.35)">
                    <td style="padding:.6rem 1.25rem">{{ $e->created_at->setTimezone('Europe/Rome')->format('d/m/Y H:i') }}</td>
                    <td>{{ $e->version }}</td>
                    <td>{{ ['initial' => 'Testo di partenza', 'typo' => 'Correzione refuso', 'new_version' => 'Nuova versione'][$e->kind] ?? $e->kind }}</td>
                    <td>{{ $e->summary ?: '—' }}</td>
                    <td>{{ $e->actor }}</td>
                </tr>
            @endforeach
        </table>
        @endif
    </div>
</x-filament-panels::page>
