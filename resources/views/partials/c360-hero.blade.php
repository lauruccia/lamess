@php
    $initials = collect(preg_split('/\s+/', trim($c->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    $statusTone = ['active' => 'ok', 'suspended' => 'warn', 'terminated' => 'bad'][$c->status] ?? 'muted';
    $kyc = $c->kyc_status ?: 'unverified';
    $kycTone = $kyc === 'verified' ? 'ok' : ($kyc === 'rejected' ? 'bad' : 'warn');
    $kycLabel = $kyc === 'none' ? 'KYC: nessun documento' : 'KYC: '.mb_strtolower((string) \App\Support\StatusLabels::label($kyc));
    $actTone = $active === null ? 'muted' : ($active ? 'ok' : 'warn');
    $actLabel = $active === null ? 'Attività: —' : ($active ? 'Attivo questo mese' : 'Non attivo questo mese');
@endphp
<style>
    .c360h { display:flex; flex-direction:column; gap:1rem; }
    .c360h-top { display:flex; gap:1rem; align-items:center; }
    .c360h-av { flex:none; width:3.4rem; height:3.4rem; border-radius:50%; display:flex; align-items:center; justify-content:center; font-family:Marcellus,serif; font-size:1.25rem; color:#894C2D; background:rgba(194,102,58,.14); border:1px solid rgba(194,102,58,.35); }
    .c360h-name { font-family:Marcellus,serif; font-size:1.65rem; line-height:1.15; margin:0; }
    .c360h-sub { font-size:.84rem; opacity:.7; margin-top:.2rem; display:flex; flex-wrap:wrap; gap:.2rem .9rem; }
    .c360h-sub code { font-size:.78rem; padding:.02rem .4rem; border-radius:.35rem; background:rgba(127,127,127,.14); }
    .c360h-pills { display:flex; flex-wrap:wrap; gap:.4rem; }
    .c360h-pill { font-size:.76rem; font-weight:600; padding:.18rem .65rem; border-radius:999px; border:1px solid transparent; }
    .c360h-pill.ok { background:#E8F3EC; color:#2F6F4F; } .c360h-pill.warn { background:#FBF0DC; color:#9A5B12; }
    .c360h-pill.bad { background:#FBE9E7; color:#B3261E; } .c360h-pill.muted { background:rgba(127,127,127,.14); }
    .c360h-pill.brand { background:rgba(194,102,58,.14); color:#894C2D; border-color:rgba(194,102,58,.35); }
    .c360h-facts { display:grid; grid-template-columns:repeat(auto-fit,minmax(10.5rem,1fr)); gap:.9rem 1.5rem; padding-top:1rem; border-top:1px solid rgba(187,180,175,.35); }
    .c360h-facts dt { font-size:.68rem; letter-spacing:.06em; text-transform:uppercase; opacity:.6; margin:0 0 .15rem; }
    .c360h-facts dd { margin:0; font-size:.95rem; font-weight:600; }
    .c360h-facts dd a { color:#894C2D; text-decoration:none; } .c360h-facts dd a:hover { text-decoration:underline; }
    .c360h-facts dd small { font-weight:400; opacity:.65; }
    .c360h-cnt { display:flex; flex-wrap:nowrap; overflow-x:auto; gap:.35rem; padding-top:1rem; border-top:1px solid rgba(187,180,175,.35); }
    .c360h-cnt a { display:inline-flex; align-items:baseline; gap:.3rem; flex:1 1 auto; justify-content:center; white-space:nowrap; padding:.28rem .45rem; border:1px solid rgba(187,180,175,.7); border-radius:.55rem; text-decoration:none; color:inherit; font-size:.76rem; transition:border-color .12s, background .12s; }
    .c360h-cnt a:hover, .c360h-cnt a:focus-visible { border-color:#C2663A; background:rgba(194,102,58,.07); }
    .c360h-cnt b { font-size:1rem; }
    .c360h-cnt small { opacity:.55; font-size:.7rem; }
</style>
<div class="c360h">
    <div class="c360h-top">
        <div class="c360h-av" aria-hidden="true">{{ $initials }}</div>
        <div style="min-width:0">
            <h2 class="c360h-name">{{ $c->name }}</h2>
            <div class="c360h-sub">
                <span><code>{{ $c->code }}</code></span>
                <span>Incaricato dal {{ $c->since?->timezone('Europe/Rome')->format('d/m/Y') }}</span>
                @if($c->referral_code)<span>Referral <code>{{ $c->referral_code }}</code></span>@endif
            </div>
        </div>
    </div>
    <div class="c360h-pills">
        <span class="c360h-pill {{ $statusTone }}">{{ \App\Support\StatusLabels::label($c->status) }}</span>
        <span class="c360h-pill brand">{{ $rank }}</span>
        @if($c->rank_hold_until)<span class="c360h-pill warn" title="{{ $c->rank_hold_reason }}">Qualifica mantenuta fino al {{ $c->rank_hold_until->format('d/m/Y') }}</span>@endif
        @if($showActivity ?? true)<span class="c360h-pill {{ $actTone }}">{{ $actLabel }}</span>@endif
        <span class="c360h-pill {{ $kycTone }}">{{ $kycLabel }}</span>
        @unless($c->onboarding_complete)<span class="c360h-pill warn">Onboarding da completare</span>@endunless
    </div>
    <dl class="c360h-facts">
        <div><dt>Sponsor attuale</dt><dd>@if($sponsor)<a href="{{ $sponsorUrl }}">{{ $sponsor->name }}</a> <small>{{ $sponsor->code }}</small>@else — <small>(radice)</small>@endif</dd></div>
        @if($original && $original->id !== optional($sponsor)->id)
            <div><dt>Sponsor originale</dt><dd><a href="{{ $originalUrl }}">{{ $original->name }}</a> <small>{{ $original->code }}</small></dd></div>
        @endif
        <div><dt>Da pagare</dt><dd>{{ $balance }}</dd></div>
        @if($c->terminated_at)
            <div><dt>Cessato il</dt><dd>{{ $c->terminated_at->timezone('Europe/Rome')->format('d/m/Y') }}@if($c->termination_reason) <small>{{ $c->termination_reason }}</small>@endif</dd></div>
        @endif
    </dl>
    @if(! empty($counters))
        <nav class="c360h-cnt" aria-label="Sezioni dell’incaricato">
            @foreach($counters as $k)
                <a href="{{ $k['url'] }}" title="Apri la lista: {{ $k['label'] }}@if($k['hint']) ({{ $k['hint'] }})@endif"><b>{{ number_format($k['count'], 0, ',', '.') }}</b> {{ $k['label'] }}</a>
            @endforeach
        </nav>
    @endif
</div>
