@if(! empty($counters))
<style>
    .c360-counters { display:flex; flex-wrap:wrap; gap:.5rem; }
    .c360-counter { display:flex; flex-direction:column; min-width:7.2rem; padding:.45rem .8rem; border:1px solid rgba(187,180,175,.7); border-radius:.6rem; text-decoration:none; color:inherit; line-height:1.2; transition:border-color .12s, background .12s; }
    .c360-counter:hover, .c360-counter:focus-visible { border-color:#C2663A; background:rgba(194,102,58,.07); }
    .c360-counter b { font-size:1.15rem; font-weight:700; }
    .c360-counter span { font-size:.78rem; opacity:.75; }
    .c360-counter small { font-size:.68rem; opacity:.55; }
</style>
<nav class="c360-counters" aria-label="Sezioni dell’incaricato">
    @foreach($counters as $c)
        <a class="c360-counter" href="{{ $c['url'] }}" title="Apri la lista: {{ $c['label'] }}">
            <b>{{ number_format($c['count'], 0, ',', '.') }}</b>
            <span>{{ $c['label'] }}</span>
            @if($c['hint'])<small>{{ $c['hint'] }}</small>@endif
        </a>
    @endforeach
</nav>
@endif
