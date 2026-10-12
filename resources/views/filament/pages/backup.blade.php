<x-filament-panels::page>
    <div class="fi-section" style="padding:1.25rem 1.5rem;font-size:.9rem">
        <p style="margin:0">Ogni notte alle 02:15 il portale salva una copia del database (ne tiene 14). <strong>Scarica ogni tanto una copia sul tuo computer</strong>: un backup che sta solo sul server non basta se il server si rompe. I file contengono dati personali: non condividerli.</p>
        <p style="margin:.6rem 0 0;opacity:.75">Per ripristinare: cPanel → phpMyAdmin → database → Importa → scegli il file <code>.sql.gz</code>. Vedi la guida «Piano di ritorno».</p>
    </div>
    <div class="fi-section" style="padding:0;overflow:hidden">
        @if(count($backups) === 0)
            <p style="padding:1.5rem;margin:0;opacity:.7">Nessun backup ancora. Premi «Crea backup ora».</p>
        @else
        <table style="width:100%;border-collapse:collapse;font-size:.9rem">
            <tr style="text-align:left;font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;opacity:.65"><th style="padding:.7rem 1.25rem">File</th><th>Creato il</th><th>Dimensione</th><th></th></tr>
            @foreach($backups as $b)
                <tr style="border-top:1px solid rgba(187,180,175,.35)">
                    <td style="padding:.7rem 1.25rem;font-family:monospace">{{ $b['name'] }}</td>
                    <td>{{ $b['at']->format('d/m/Y H:i') }}</td>
                    <td>{{ number_format($b['size'] / 1024, 0, ',', '.') }} KB</td>
                    <td style="padding-right:1.25rem;text-align:right"><a href="/admin-files/backup/{{ $b['name'] }}" style="color:#894C2D;font-weight:600">Scarica</a></td>
                </tr>
            @endforeach
        </table>
        @endif
    </div>
</x-filament-panels::page>
