<style>
    .gedh { display:flex; flex-wrap:wrap; align-items:center; gap:.9rem 1.25rem; }
    .gedh-ic { flex:none; width:3.2rem; height:3.2rem; border-radius:.9rem; display:flex; align-items:center; justify-content:center; font-family:Marcellus,serif; font-size:1.05rem; color:#894C2D; background:rgba(194,102,58,.14); border:1px solid rgba(194,102,58,.35); }
    .gedh-body { flex:1 1 16rem; min-width:0; }
    .gedh-name { font-family:Marcellus,serif; font-size:1.45rem; line-height:1.15; margin:0; overflow-wrap:anywhere; }
    .gedh-sub { font-size:.84rem; opacity:.72; margin-top:.25rem; display:flex; flex-wrap:wrap; gap:.2rem 1rem; }
    .gedh-pills { display:flex; flex-wrap:wrap; gap:.4rem; margin-top:.6rem; }
    .gedh-pill { font-size:.76rem; font-weight:600; padding:.18rem .65rem; border-radius:999px; background:rgba(194,102,58,.14); color:#894C2D; border:1px solid rgba(194,102,58,.35); }
    .gedh-tip { flex:0 1 19rem; font-size:.8rem; line-height:1.45; opacity:.78; padding:.65rem .85rem; border-left:3px solid #C2663A; background:rgba(194,102,58,.07); border-radius:0 .5rem .5rem 0; }
</style>
<div class="gedh">
    <div class="gedh-ic" aria-hidden="true">{{ $initials }}</div>
    <div class="gedh-body">
        <h2 class="gedh-name">{{ $title }}</h2>
        <div class="gedh-sub">
            <span>{{ $kind }}</span>
            @if($created)<span>Creato il {{ $created }}</span>@endif
            @if($updated)<span>Ultima modifica il {{ $updated }}</span>@endif
        </div>
        @if($pills !== [])
            <div class="gedh-pills">@foreach($pills as $pill)<span class="gedh-pill">{{ $pill }}</span>@endforeach</div>
        @endif
    </div>
    <div class="gedh-tip">Modifica diretta riservata al super admin. Controlla i campi nelle sezioni e premi <b>Salva modifiche</b>: ogni cambio è registrato nel log con i valori prima e dopo.</div>
</div>
