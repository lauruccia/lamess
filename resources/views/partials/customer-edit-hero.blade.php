@php
    $initials = collect(preg_split('/\s+/', trim((string) $name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
@endphp
<style>
    .c360e { display:flex; flex-wrap:wrap; align-items:center; gap:1rem 1.25rem; }
    .c360e-av { flex:none; width:3.6rem; height:3.6rem; border-radius:50%; display:flex; align-items:center; justify-content:center; font-family:Marcellus,serif; font-size:1.3rem; color:#894C2D; background:rgba(194,102,58,.14); border:1px solid rgba(194,102,58,.35); }
    .c360e-body { flex:1 1 16rem; min-width:0; }
    .c360e-name { font-family:Marcellus,serif; font-size:1.55rem; line-height:1.15; margin:0; }
    .c360e-sub { font-size:.84rem; opacity:.72; margin-top:.25rem; display:flex; flex-wrap:wrap; gap:.2rem 1rem; }
    .c360e-sub code { font-size:.78rem; padding:.02rem .4rem; border-radius:.35rem; background:rgba(127,127,127,.14); }
    .c360e-pills { display:flex; flex-wrap:wrap; gap:.4rem; margin-top:.65rem; }
    .c360e-pill { font-size:.76rem; font-weight:600; padding:.18rem .65rem; border-radius:999px; border:1px solid transparent; }
    .c360e-pill.ok { background:#E8F3EC; color:#2F6F4F; } .c360e-pill.warn { background:#FBF0DC; color:#9A5B12; }
    .c360e-pill.muted { background:rgba(127,127,127,.14); }
    .c360e-pill.brand { background:rgba(194,102,58,.14); color:#894C2D; border-color:rgba(194,102,58,.35); }
    .c360e-tip { flex:0 1 19rem; font-size:.8rem; line-height:1.45; opacity:.78; padding:.65rem .85rem; border-left:3px solid #C2663A; background:rgba(194,102,58,.07); border-radius:0 .5rem .5rem 0; }
</style>
<div class="c360e">
    <div class="c360e-av" aria-hidden="true">{{ $initials }}</div>
    <div class="c360e-body">
        <h2 class="c360e-name">{{ $name }}</h2>
        <div class="c360e-sub">
            <span>{{ $email }}</span>
            <span>Registrato il {{ $registeredAt }}</span>
            <span>Incaricato: @if($consultant)<code>{{ $consultant->code }}</code> {{ $consultant->name }}@else nessuno @endif</span>
        </div>
        <div class="c360e-pills">
            <span class="c360e-pill brand">{{ $type === 'company' ? 'Azienda' : 'Privato' }}</span>
            <span class="c360e-pill {{ $orders > 0 ? 'ok' : 'muted' }}">{{ $orders }} {{ $orders === 1 ? 'ordine pagato' : 'ordini pagati' }}@if($orders > 0) · {{ number_format($spent, 2, ',', '.') }} €@endif</span>
            @if($subscriptions > 0)<span class="c360e-pill ok">{{ $subscriptions }} {{ $subscriptions === 1 ? 'abbonamento attivo' : 'abbonamenti attivi' }}</span>@endif
            @if($isConsultant)<span class="c360e-pill warn">È anche incaricato</span>@endif
        </div>
    </div>
    <div class="c360e-tip">Cambia i dati nelle sezioni qui sotto e premi <b>Salva modifiche</b>. Ogni modifica viene registrata nel log con chi l'ha fatta e i valori prima e dopo.</div>
</div>
