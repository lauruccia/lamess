<x-filament-panels::page>
    @php
        $tabs = ['matrice' => 'Matrice dei permessi', 'profili' => 'Profili', 'utenti' => 'Accessi degli utenti'];
        $chip = 'font-weight:600;font-size:.7rem;letter-spacing:.04em;text-transform:uppercase;padding:.05rem .45rem;border-radius:999px;margin-left:.3rem';
    @endphp

    <div class="fi-section" style="padding:1.1rem 1.5rem">
        <p style="margin:0;font-size:.9rem;opacity:.85">
            Qui vedi <strong>chi può fare cosa</strong> nel back-office. I permessi si concedono a ogni admin dalla pagina <strong>Utenti</strong>;
            i <strong>profili</strong> sono modelli per partire in fretta. Un permesso «gestire» include sempre il corrispondente «vedere». Le voci di menu visibili si scelgono a parte, sempre in Utenti.
        </p>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.9rem" role="tablist">
            @foreach($tabs as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')" wire:key="tab-{{ $key }}"
                    style="padding:.45rem .9rem;border-radius:999px;border:1px solid rgba(187,180,175,.7);cursor:pointer;font-weight:600;font-size:.88rem;{{ $tab === $key ? 'background:#C2663A;color:#fff;border-color:#C2663A' : 'background:transparent' }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- ============================ MATRICE ============================ --}}
    @if($tab === 'matrice' && $matrix)
        <div class="fi-section" style="padding:.8rem 1.25rem;font-size:.85rem">
            <strong>Super admin</strong> ha sempre tutto. Le colonne sono i profili: ✔ = il permesso è nel profilo.
            <span style="{{ $chip }};background:#FBE9E7;color:#B3261E">sensibile</span> = permette di cambiare dati importanti (pagamenti, rete, piano…).
        </div>
        @foreach($matrix['areas'] as $area)
            <div class="fi-section" style="padding:0;overflow:hidden" wire:key="area-{{ $area['key'] }}">
                <div style="padding:.8rem 1.25rem;border-bottom:1px solid rgba(187,180,175,.35)">
                    <span style="font-family:Marcellus,serif;font-size:1.1rem">{{ $area['label'] }}</span>
                    <span style="font-size:.82rem;opacity:.7;margin-left:.5rem">{{ $area['help'] }}</span>
                </div>
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:.88rem">
                        <thead>
                            <tr style="border-bottom:1px solid rgba(187,180,175,.35);text-align:center;font-size:.75rem">
                                <th style="text-align:left;padding:.5rem 1.25rem;min-width:22rem">Permesso</th>
                                @foreach($matrix['profiles'] as $col)
                                    <th style="padding:.5rem .6rem;min-width:6.5rem;font-weight:600" wire:key="h-{{ $area['key'] }}-{{ $col['key'] }}">{{ $col['label'] }}@unless($col['builtin'])<div style="font-weight:400;opacity:.65">personalizzato</div>@endunless</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($area['rows'] as $row)
                                <tr style="border-bottom:1px solid rgba(187,180,175,.2)" wire:key="r-{{ $row['key'] }}">
                                    <td style="padding:.65rem 1.25rem;vertical-align:top">
                                        <div style="font-weight:600">{{ $row['label'] }}
                                            @if($row['sensitive'])<span style="{{ $chip }};background:#FBE9E7;color:#B3261E">sensibile</span>@endif
                                            @if($row['system_only'])<span style="{{ $chip }};border:1px solid rgba(127,127,127,.5)">🔒 riservato</span>@endif
                                        </div>
                                        <div style="font-size:.8rem;opacity:.72;margin-top:.1rem">{{ $row['detail'] }}</div>
                                        <div style="font-size:.72rem;opacity:.55;margin-top:.1rem"><code>{{ $row['key'] }}</code>@if($row['requires']) · include {{ implode(', ', $row['requires']) }}@endif</div>
                                    </td>
                                    @foreach($matrix['profiles'] as $col)
                                        <td style="text-align:center;padding:.65rem .6rem;vertical-align:top" wire:key="c-{{ $row['key'] }}-{{ $col['key'] }}">
                                            @if($row['system_only'])<span title="Solo super admin" style="opacity:.4">—</span>
                                            @elseif($row['in'][$col['key']])<span style="color:#2F6F4F;font-weight:700" aria-label="sì">✔</span>
                                            @else<span style="opacity:.25" aria-label="no">·</span>@endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    @endif

    {{-- ============================ PROFILI ============================ --}}
    @if($tab === 'profili')
        <div class="fi-section" style="padding:.9rem 1.25rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
            <span style="font-size:.88rem;opacity:.85">I profili preimpostati non si modificano: per cambiarli, <strong>copiali</strong> in un profilo personalizzato.</span>
            <button type="button" wire:click="mountAction('profile')" style="padding:.5rem 1rem;border-radius:.5rem;background:#C2663A;color:#fff;font-weight:600;border:0;cursor:pointer">Nuovo profilo</button>
        </div>
        @foreach($profiles as $key => $p)
            <div class="fi-section" style="padding:1rem 1.25rem" wire:key="p-{{ $key }}">
                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start">
                    <div>
                        <div style="font-weight:700;font-size:1rem">{{ $p['label'] }}
                            <span style="{{ $chip }};border:1px solid rgba(127,127,127,.5)">{{ $p['builtin'] ? 'preimpostato' : 'personalizzato' }}</span>
                        </div>
                        <div style="font-size:.8rem;opacity:.7;margin-top:.15rem">{{ count($p['permissions']) }} permessi</div>
                    </div>
                    <div style="font-size:.85rem;white-space:nowrap">
                        <button type="button" wire:click="mountAction('profile', { from: '{{ $key }}' })" style="color:#894C2D;font-weight:600;cursor:pointer;background:none;border:0">Copia</button>
                        @unless($p['builtin'])
                            <button type="button" wire:click="mountAction('profile', { key: '{{ $key }}' })" style="margin-left:.8rem;color:#894C2D;font-weight:600;cursor:pointer;background:none;border:0">Modifica</button>
                            <button type="button" wire:click="mountAction('deleteProfile', { key: '{{ $key }}' })" style="margin-left:.8rem;color:#B3261E;cursor:pointer;background:none;border:0">Cancella</button>
                        @endunless
                    </div>
                </div>
                <div style="margin-top:.6rem;display:flex;flex-wrap:wrap;gap:.3rem">
                    @foreach($p['permissions'] as $perm)
                        <span wire:key="pp-{{ $key }}-{{ $perm }}" title="{{ \App\Domain\Access\PermissionCatalog::PERMISSIONS[$perm]['detail'] ?? '' }}"
                            style="font-size:.75rem;padding:.1rem .55rem;border-radius:999px;border:1px solid rgba(127,127,127,.4);{{ \App\Domain\Access\PermissionCatalog::isSensitive($perm) ? 'background:#FBE9E7;color:#B3261E' : '' }}">{{ $perm }}</span>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif

    {{-- ============================ UTENTI ============================ --}}
    @if($tab === 'utenti')
        <div class="fi-section" style="padding:0;overflow:hidden">
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.88rem">
                    <thead>
                        <tr style="border-bottom:1px solid rgba(187,180,175,.35);text-align:left;font-size:.75rem">
                            <th style="padding:.6rem 1.25rem">Utente</th><th>Profilo</th><th style="text-align:center">Permessi</th><th style="text-align:center">Sensibili</th>
                            <th style="text-align:center">Menu visibile</th><th style="text-align:center">2FA</th><th>Avvisi</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $r)
                            <tr style="border-bottom:1px solid rgba(187,180,175,.2)" wire:key="u-{{ $r['user']->id }}">
                                <td style="padding:.65rem 1.25rem"><div style="font-weight:600">{{ $r['user']->name }}</div><div style="font-size:.78rem;opacity:.7">{{ $r['user']->email }}</div></td>
                                <td>{{ $r['profile'] !== null ? \App\Domain\Access\AccessProfiles::labelOf($r['profile']) : 'Personalizzato' }}</td>
                                <td style="text-align:center">{{ $r['permission_count'] }}</td>
                                <td style="text-align:center">{{ count($r['sensitive']) }}</td>
                                <td style="text-align:center">{{ $r['menu_visible_count'] }} / {{ $r['menu_total'] }}</td>
                                <td style="text-align:center">{{ $r['two_factor'] ? '✔' : '—' }}</td>
                                <td style="font-size:.8rem;color:{{ $r['warnings'] ? '#9A5B12' : 'inherit' }}">{{ $r['warnings'] ? count($r['warnings']).' da vedere' : 'nessuno' }}</td>
                                <td style="padding-right:1.25rem;text-align:right;white-space:nowrap">
                                    <button type="button" wire:click="mountAction('userAccess', { id: {{ $r['user']->id }} })" style="color:#894C2D;font-weight:600;cursor:pointer;background:none;border:0">Dettaglio</button>
                                    <a href="{{ $editUrl($r['user']) }}" style="margin-left:.8rem;color:#894C2D">Modifica</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" style="padding:1.5rem;opacity:.7">Nessun admin ha ancora accesso al back-office.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
