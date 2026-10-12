<style>
    .c360-tl { list-style:none; margin:0; padding:0; font-size:.86rem; max-height:15.5rem; overflow:auto; padding-right:.3rem; }
    .c360-tl li { position:relative; padding:.45rem 0 .45rem 1rem; border-left:2px solid rgba(187,180,175,.6); margin-left:.3rem; }
    .c360-tl li::before { content:''; position:absolute; left:-.34rem; top:.78rem; width:.62rem; height:.62rem; border-radius:50%; background:#BBB4AF; }
    .c360-tl li.note::before { background:#C2663A; }
    .c360-tl li.pinned { background:rgba(194,102,58,.08); border-left-color:#C2663A; }
    .c360-tl .meta { font-size:.74rem; opacity:.65; }
    .c360-tl .kind { display:inline-block; font-size:.66rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; padding:.02rem .4rem; border-radius:999px; border:1px solid rgba(127,127,127,.45); margin-right:.3rem; }
    .c360-tl .txt { white-space:pre-line; }
</style>
@if(empty($events))
    <p style="opacity:.7;font-size:.9rem">Ancora nessun evento.</p>
@else
<ol class="c360-tl" aria-label="Storico dell’incaricato">
    @foreach($events as $e)
        <li class="{{ $e['kind'] === 'Nota' ? 'note' : '' }} {{ $e['pinned'] ? 'pinned' : '' }}">
            <div class="meta"><span class="kind">{{ $e['kind'] }}</span>{{ $e['at']?->format('d/m/Y H:i') ?? '—' }}@if($e['by']) · {{ $e['by'] }}@endif @if($e['pinned']) · 📌 fissata @endif</div>
            <div class="txt">{{ $e['text'] }}</div>
        </li>
    @endforeach
</ol>
@endif
