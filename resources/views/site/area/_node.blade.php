@foreach($ids as $id)
 @php($c = $nodes[$id])
 @php($s = $stats[$id] ?? null)
 @php($kids = $children[$id] ?? [])
 <li class="{{ $kids ? '' : 'leaf' }}" data-name="{{ mb_strtolower($c->name.' '.$c->code) }}">
  @if($kids)<details><summary>@else<div>@endif
   <span class="node">
    <span class="tog"></span>
    <span><span class="nm">{{ $c->name }}</span> <span class="cd">{{ $c->code }}</span></span>
    @if($c->status === 'suspended')<span class="badge grey">sospeso</span>@elseif($c->status === 'terminated')<span class="badge grey">cessato</span>@elseif($s && ! $kosmos)<span class="badge {{ $s['active'] ? 'green' : 'grey' }}">{{ $s['active'] ? 'attivo' : 'non attivo' }}</span>@endif
    @if($s && $s['rank'])<span class="badge">{{ $s['rank'] }}</span>@endif
    @if($kosmos)
     @if($s)<span class="kv" title="Clienti personali">{{ $s['clients'] }} {{ $s['clients'] === 1 ? 'cliente' : 'clienti' }}</span>@endif
     @if($kids)
      <span class="kv" title="Persone e clienti in tutto il suo ramo">ramo: {{ $sub[$id]['persons'] - 1 }} {{ $sub[$id]['persons'] - 1 === 1 ? 'persona' : 'persone' }} · {{ $sub[$id]['clients'] - ($s['clients'] ?? 0) }} clienti</span>
      @if($s && $s['pearl_first'] > 0)<span class="kv" title="Diretti con qualifica Pearl o superiore">{{ $s['pearl_first'] }} Pearl diretti</span>@endif
     @endif
     @if(($custs[$id]['waiting'] ?? 0) > 0)<span class="kv" title="Iscritti che non hanno ancora fatto il primo ordine" style="color:#b0701a">{{ $custs[$id]['waiting'] }} senza ordine</span>@endif
     @if($s && ! empty($s['next_name']) && ! empty($s['missing']))<span class="kv" title="Cosa manca per la prossima qualifica">verso {{ $s['next_name'] }}: {{ implode(' · ', $s['missing']) }}</span>@endif
     @if(($volume[$id] ?? 0) > 0)<span class="kv" title="Venduto ai suoi clienti nel mese, IVA inclusa">€ {{ \App\Support\Money::eur($volume[$id]) }} nel mese</span>@endif
    @elseif($s && \App\Domain\Plan\LegacyPlan::visible())
     <span class="kv">PVF {{ number_format((float) $s['pvf'], 0, ',', '.') }}</span><span class="kv">PR {{ number_format((float) $s['pr'], 0, ',', '.') }}</span>
    @endif
    @if($c->since)<span class="kv" title="Data di ingresso">dal {{ \App\Support\Ui::date($c->since) }}</span>@endif
    @if($kids)<span class="kv">{{ count($kids) }} {{ count($kids) === 1 ? 'diretto' : 'diretti' }}</span>@endif
   </span>
  @if($kids)</summary><ul>@include('site.area._node', ['ids' => $kids])</ul></details>@else</div>@endif
 </li>
@endforeach
