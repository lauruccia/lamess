@php
    $initials = collect(preg_split('/\s+/', trim($c->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    $statusTone = ['active' => 'ok', 'suspended' => 'warn', 'terminated' => 'bad'][$c->status] ?? 'muted';
    $kyc = $c->kyc_status ?: 'none';
    $kycTone = $kyc === 'verified' ? 'ok' : ($kyc === 'rejected' ? 'bad' : 'warn');
@endphp
<style>
    .c360e { display:flex; flex-wrap:wrap; align-items:center; gap:1rem 1.25rem; }
    .c360e-av { flex:none; width:3.6rem; height:3.6rem; border-radius:50%; display:flex; align-items:center; justify-content:center; font-family:Marcellus,serif; font-size:1.3rem; color:#894C2D; background:rgba(194,102,58,.14); border:1px solid rgba(194,102,58,.35); }
    .c360e-body { flex:1 1 16rem; min-width:0; }
    .c360e-name { font-family:Marcellus,serif; font-size:1.55rem; line-height:1.15; margin:0; }
    .c360e-sub { font-size:.84rem; opacity:.72; margin-top:.25rem; display:flex; flex-wrap:wrap; gap:.2rem 1rem; }
    .c360e-sub code { font-size:.78rem; padding:.02rem .4rem; border-radius:.35rem; background:rgba(127,127,127,.14); }
    .c360e-sub a { color:#894C2D; text-decoration:none; } .c360e-sub a:hover { text-decoration:underline; }
    .c360e-pills { display:flex; flex-wrap:wrap; gap:.4rem; margin-top:.65rem; }
    .c360e-pill { font-size:.76rem; font-weight:600; padding:.18rem .65rem; border-radius:999px; border:1px solid transparent; }
    .c360e-pill.ok { background:#E8F3EC; color:#2F6F4F; } .c360e-pill.warn { background:#FBF0DC; color:#9A5B12; }
    .c360e-pill.bad { background:#FBE9E7; color:#B3261E; } .c360e-pill.muted { background:rgba(127,127,127,.14); }
    .c360e-pill.brand { background:rgba(194,102,58,.14); color:#894C2D; border-color:rgba(194,102,58,.35); }
    .c360e-tip { flex:0 1 19rem; font-size:.8rem; line-height:1.45; opacity:.78; padding:.65rem .85rem; border-left:3px solid #C2663A; background:rgba(194,102,58,.07); border-radius:0 .5rem .5rem 0; }
</style>
<div class="c360e">
    <div class="c360e-av" aria-hidden="true">{{ $initials }}</div>
    <div class="c360e-body">
        <h2 class="c360e-name">{{ $c->name }}</h2>
        <div class="c360e-sub">
            <span><code>{{ $c->code }}</code></span>
            <span>Incaricato dal {{ $c->since?->timezone('Europe/Rome')->format('d/m/Y') }}</span>
            @if($email)<span>{{ $email }}</span>@endif
            @if($sponsor)<span>Sponsor: <a href="{{ $sponsorUrl }}">{{ $sponsor->name }}</a> <code>{{ $sponsor->code }}</code></span>@endif
        </div>
        <div class="c360e-pills">
            <span class="c360e-pill {{ $statusTone }}">{{ \App\Support\StatusLabels::label($c->status) }}</span>
            @if($rank)<span class="c360e-pill brand">{{ $rank }}</span>@endif
            <span class="c360e-pill {{ $kycTone }}">{{ $kyc === 'none' ? 'KYC: nessun documento' : 'KYC: '.mb_strtolower((string) \App\Support\StatusLabels::label($kyc)) }}</span>
            @unless($c->onboarding_complete)<span class="c360e-pill warn">Onboarding da completare</span>@endunless
            @if($c->bonus_hold)<span class="c360e-pill bad">Compensi bloccati</span>@endif
            @if($c->is_founder)<span class="c360e-pill brand">Founder</span>@endif
        </div>
    </div>
    <div class="c360e-tip">Cambia i dati nelle sezioni qui sotto e premi <b>Salva modifiche</b>. Ogni modifica viene registrata nel log con chi l'ha fatta e i valori prima e dopo.</div>
</div>
