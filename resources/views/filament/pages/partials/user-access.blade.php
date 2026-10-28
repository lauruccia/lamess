@php
    $chip = 'font-weight:600;font-size:.7rem;letter-spacing:.04em;text-transform:uppercase;padding:.05rem .45rem;border-radius:999px;margin-left:.3rem';
    $byArea = collect($r['permissions'])->groupBy('area_label');
@endphp
<div style="display:grid;gap:1rem;font-size:.9rem">
    <div>
        <strong>{{ $r['user']->name }}</strong> · {{ $r['user']->email }}
        <div style="margin-top:.2rem;opacity:.8">
            Profilo: <strong>{{ $r['profile'] !== null ? \App\Domain\Access\AccessProfiles::labelOf($r['profile']) : 'personalizzato' }}</strong> ·
            {{ $r['permission_count'] }} permessi ({{ count($r['sensitive']) }} sensibili) ·
            menu: {{ $r['menu_visible_count'] }} voci su {{ $r['menu_total'] }} ·
            2FA: {{ $r['two_factor'] ? 'collegata' : 'non collegata' }}
        </div>
    </div>

    @if($r['warnings'])
        <div style="padding:.7rem .9rem;border-radius:.5rem;background:#FFF4E0;color:#7A4B0C">
            @foreach($r['warnings'] as $w)<div wire:key="w-{{ $loop->index }}">• {{ $w }}</div>@endforeach
        </div>
    @endif

    <div>
        <div style="font-weight:700;margin-bottom:.4rem">Cosa può fare</div>
        @forelse($byArea as $area => $perms)
            <div style="margin-bottom:.6rem" wire:key="a-{{ $loop->index }}">
                <div style="font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;opacity:.65">{{ $area }}</div>
                @foreach($perms as $p)
                    <div style="margin-top:.2rem" wire:key="ap-{{ $p['key'] }}">
                        {{ $p['label'] }}
                        @if($p['sensitive'])<span style="{{ $chip }};background:#FBE9E7;color:#B3261E">sensibile</span>@endif
                        <div style="font-size:.78rem;opacity:.65">{{ $p['detail'] }}</div>
                    </div>
                @endforeach
            </div>
        @empty
            <div style="opacity:.7">Nessun permesso concesso.</div>
        @endforelse
    </div>

    <div>
        <div style="font-weight:700;margin-bottom:.4rem">Voci di menu che vede</div>
        @forelse($r['menu_visible'] as $group => $labels)
            <div wire:key="mv-{{ $group }}"><span style="opacity:.65">{{ $group }}:</span> {{ implode(', ', $labels) }}</div>
        @empty
            <div style="opacity:.7">Nessuna.</div>
        @endforelse
        @if($r['menu_hidden'])
            <div style="font-weight:700;margin:.8rem 0 .4rem">Voci nascoste</div>
            @foreach($r['menu_hidden'] as $group => $labels)
                <div style="opacity:.75" wire:key="mh-{{ $group }}"><span style="opacity:.65">{{ $group }}:</span> {{ implode(', ', $labels) }}</div>
            @endforeach
        @endif
    </div>

    <div><a href="{{ $editUrl }}" style="color:#894C2D;font-weight:600">Modifica permessi e menu →</a></div>
</div>
