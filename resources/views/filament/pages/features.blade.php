<x-filament-panels::page>
    <div class="fi-section" style="padding:1.1rem 1.5rem">
        <p style="margin:0;font-size:.9rem;opacity:.85">
            Qui si accendono e si spengono tab, viste, link e pulsanti. <strong>Spegnere una funzionalità la nasconde e blocca anche l'indirizzo diretto</strong>;
            i dati e i calcoli non cambiano, e riaccendendola si ritrova tutto. Le voci con il lucchetto sono protette. «Eccezione» vale per un ruolo o un utente e prevale sulla regola per tutti.
        </p>
        <div style="margin-top:.8rem">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cerca una funzionalità…" class="fi-input" style="width:100%;max-width:26rem;padding:.45rem .7rem;border:1px solid rgba(187,180,175,.7);border-radius:.5rem;background:transparent">
        </div>
    </div>

    @forelse($groups as $group => $rows)
        <div class="fi-section" style="padding:0;overflow:hidden">
            <div style="padding:.8rem 1.25rem;font-family:Marcellus,serif;font-size:1.1rem;border-bottom:1px solid rgba(187,180,175,.35)">{{ $group }}</div>
            <table style="width:100%;border-collapse:collapse;font-size:.9rem">
                @foreach($rows as $r)
                    <tr style="border-bottom:1px solid rgba(187,180,175,.25);{{ $r['effective'] ? '' : 'opacity:.78' }}" wire:key="f-{{ $r['key'] }}">
                        <td style="padding:.7rem 1.25rem;width:4.5rem;vertical-align:top">
                            @if($r['system'])
                                <span title="Funzione protetta: non si può spegnere" style="font-size:1.1rem">🔒</span>
                            @else
                                <button type="button" wire:click="toggle('{{ $r['key'] }}')" role="switch" aria-checked="{{ $r['on'] ? 'true' : 'false' }}" aria-label="{{ $r['def']['label'] }}"
                                    @disabled($r['locked'] && ! $isSuper)
                                    style="width:2.6rem;height:1.45rem;border-radius:999px;border:0;position:relative;cursor:pointer;background:{{ $r['on'] ? '#C2663A' : 'rgba(127,127,127,.4)' }};{{ $r['locked'] && ! $isSuper ? 'cursor:not-allowed;opacity:.6' : '' }}">
                                    <span style="position:absolute;top:.17rem;{{ $r['on'] ? 'left:1.32rem' : 'left:.17rem' }};width:1.1rem;height:1.1rem;border-radius:50%;background:#fff;transition:left .15s"></span>
                                </button>
                            @endif
                        </td>
                        <td style="padding:.7rem .5rem;padding-left:{{ .5 + $r['depth'] * 1.4 }}rem">
                            <div style="font-weight:600">{{ $r['def']['label'] }}
                                <span style="font-weight:600;font-size:.68rem;letter-spacing:.04em;text-transform:uppercase;padding:.05rem .45rem;border-radius:999px;border:1px solid rgba(127,127,127,.5);opacity:.75;margin-left:.3rem">{{ $r['type'] }}</span>
                                @if($r['def']['super']) <span style="font-size:.68rem;font-weight:700;color:#894C2D;margin-left:.2rem">SOLO SUPER_ADMIN</span> @endif
                                @if($r['locked']) <span title="Bloccata dal super_admin: l'admin non può cambiarla" style="margin-left:.2rem">🔒 bloccata</span> @endif
                            </div>
                            @if(! empty($r['def']['help']))<div style="font-size:.82rem;opacity:.7;margin-top:.1rem">{{ $r['def']['help'] }}</div>@endif
                            @if($r['on'] && ! $r['effective'] && ! $r['system'])<div style="font-size:.8rem;color:#9A5B12;margin-top:.15rem">Accesa, ma spenta perché è spento l'elemento da cui dipende o c'è un'eccezione per te.</div>@endif
                            @if(! $r['on'] && $r['children'] > 0)<div style="font-size:.8rem;opacity:.7;margin-top:.15rem">Spenta: si spengono anche i {{ $r['children'] }} elementi collegati.</div>@endif
                            @foreach($r['exceptions'] as $e)
                                <div style="font-size:.8rem;margin-top:.2rem">
                                    <span style="padding:.05rem .5rem;border-radius:999px;background:{{ $e['enabled'] ? '#E8F3EC' : '#FBE9E7' }};color:{{ $e['enabled'] ? '#2F6F4F' : '#B3261E' }};font-weight:600">{{ $e['enabled'] ? 'attiva' : 'spenta' }} per {{ $scopeLabel($e['scope_type'], $e['scope_id']) }}</span>
                                    @unless($r['locked'] && ! $isSuper)
                                        <button type="button" wire:click="removeException('{{ $r['key'] }}','{{ $e['scope_type'] }}','{{ $e['scope_id'] }}')" style="margin-left:.3rem;opacity:.7;text-decoration:underline;cursor:pointer;background:none;border:0">togli</button>
                                    @endunless
                                </div>
                            @endforeach
                        </td>
                        <td style="padding:.7rem 1.25rem;text-align:right;white-space:nowrap;vertical-align:top;font-size:.82rem">
                            @unless($r['system'] || ($r['locked'] && ! $isSuper))
                                <button type="button" wire:click="mountAction('exception', { key: '{{ $r['key'] }}' })" style="color:#894C2D;font-weight:600;cursor:pointer;background:none;border:0">Eccezione…</button>
                                @if($r['customised'])
                                    <button type="button" wire:click="restore('{{ $r['key'] }}')" wire:confirm="Ripristinare il valore predefinito (anche le eccezioni)?" style="margin-left:.6rem;opacity:.75;cursor:pointer;background:none;border:0">Ripristina</button>
                                @endif
                            @endunless
                            @if($isSuper && ! $r['system'])
                                <button type="button" wire:click="toggleLock('{{ $r['key'] }}')" title="Blocca o sblocca: bloccata, l'admin non può cambiarla" style="margin-left:.6rem;cursor:pointer;background:none;border:0">{{ $r['locked'] ? 'Sblocca' : 'Blocca' }}</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @empty
        <div class="fi-section" style="padding:1.5rem;opacity:.7">Nessuna funzionalità corrisponde alla ricerca.</div>
    @endforelse
</x-filament-panels::page>
