<header class="fi-header lm-listhead">
    <style>
        .lm-listhead { display:flex; flex-direction:column; align-items:stretch; width:100%; gap:.6rem; }
        .lm-listhead .fi-breadcrumbs { align-self:flex-start; }
        .lm-listhead-row { display:flex; align-items:center; gap:1.5rem; flex-wrap:wrap; }
        .lm-listhead-row h1 { margin:0; }
        .lm-tabbar { margin-top:.4rem; display:flex; gap:.15rem; padding:.3rem; border-radius:.75rem; background:#fff; border:1px solid rgba(187,180,175,.5); box-shadow:0 1px 2px rgba(0,0,0,.04); overflow-x:auto; }
        .dark .lm-tabbar { background:rgba(255,255,255,.04); }
        .lm-tab { flex:1 1 auto; display:inline-flex; align-items:center; justify-content:center; gap:.4rem; white-space:nowrap; padding:.42rem .7rem; border:0; border-radius:.5rem; background:transparent; font-size:.82rem; font-weight:500; color:inherit; opacity:.75; cursor:pointer; transition:background .12s, color .12s; }
        .lm-tab:hover { background:rgba(194,102,58,.07); opacity:1; }
        .lm-tab.on { background:rgba(194,102,58,.12); color:#894C2D; font-weight:600; opacity:1; }
        .lm-tab .n { font-size:.7rem; padding:0 .45rem; border-radius:999px; background:rgba(194,102,58,.14); color:#894C2D; }
    </style>
    @if($breadcrumbs)<x-filament::breadcrumbs :breadcrumbs="$breadcrumbs" />@endif
    <div class="lm-listhead-row">
        <h1 class="fi-header-heading">{{ $heading }}</h1>
        <div style="flex:1"></div>
        <div class="fi-header-actions-ctn"><x-filament::actions :actions="$actions" /></div>
    </div>
    @if(count($tabs))
        <nav class="lm-tabbar" aria-label="Filtra per stato" role="tablist">
            @foreach($tabs as $key => $tab)
                <button type="button" role="tab" aria-selected="{{ $active === (string) $key ? 'true' : 'false' }}" wire:click="$set('activeTab', '{{ $key }}')" wire:key="lm-tab-{{ $key }}" class="lm-tab {{ $active === (string) $key ? 'on' : '' }}">
                    {{ $tab->getLabel() }}
                    @if(($badge = $tab->getBadge()) !== null)<span class="n">{{ $badge }}</span>@endif
                </button>
            @endforeach
        </nav>
    @endif
</header>
