@php
 $pct = static fn (mixed $v): string => rtrim(rtrim(number_format((float) $v, 5, ',', ''), '0'), ',').'%';
 $eur = static fn (mixed $v): string => number_format((float) $v, fmod((float) $v, 1.0) === 0.0 ? 0 : 2, ',', '.').' €';
 $k = $plan['kosmos'] ?? [];
 $ranks = collect($plan['ranks'] ?? []);
 $rankName = static fn (string $code): string => $ranks->firstWhere('code', $code)['name'] ?? $code;
 $st = $plan['settings'] ?? [];
 $anyOrder = ($k['client_rule'] ?? 'min_amount') === 'any_order';
 $levels = collect($k['indirect_levels'] ?? []);
 $maxLevel = max(1, $levels->count());
 $graceEnd = ! empty($k['rank_grace_until']) ? \Carbon\Carbon::parse($k['rank_grace_until']) : null;
 $since = ($version?->effective_from ?? $version?->activated_at);
@endphp
@if(! $plan || ! $kosmos)
 <p>Il piano compensi in vigore sarà pubblicato qui a breve. Per informazioni scrivi a <a href="mailto:info@lamess.it">info@lamess.it</a>.</p>
@else
<p class="lead">Questa pagina si genera dal piano compensi attualmente in vigore{{ $since ? ' (dal '.$since->format('d/m/Y').')' : '' }} e si aggiorna da sola a ogni nuova versione. Il documento che fa fede è il Regolamento sottoscritto con la Lettera di Incarico.</p>

<h2>1. Come si calcolano i compensi</h2>
<p>Ogni vendita genera un <strong>V.A.P.</strong> (valore a provvigione), cioè la quota dell'importo pagato dal cliente su cui si calcolano i compensi. Salvo diversa indicazione sul prodotto, è il <strong>{{ $pct($k['vap_share_pct'] ?? 0) }}</strong> dell'importo in euro, IVA inclusa. Sui prodotti scontati o in promozione il V.A.P. può essere diverso: vale quello indicato sul prodotto.</p>
<p>Un <strong>cliente</strong> è chi
 @if($anyOrder) fa almeno un ordine.
 @else fa almeno un ordine di <strong>{{ number_format((float) ($k['client_min_amount'] ?? 150), 2, ',', '.') }} €</strong> (IVA inclusa), oppure acquista un prodotto indicato come «rende cliente» (per esempio le Box e le confezioni).
 @endif
 Un cliente conta nel mese in cui acquista; se compra un prodotto con più mesi di validità (per esempio 12 mesi), conta per tutti i mesi di validità. I clienti si contano per persona: più ordini dello stesso cliente nel mese valgono un solo cliente.</p>
<p>Anche gli <strong>acquisti dell'incaricato</strong> valgono: l'incaricato è cliente di sé stesso, conta come un proprio cliente personale e genera i compensi indiretti per gli incaricati sopra di lui, come per un cliente di un suo incaricato diretto.</p>

<h2>2. Compensi diretti</h2>
<p>Sulle vendite ai tuoi clienti personali ricevi una percentuale del V.A.P. che cresce con il numero di clienti personali del mese.</p>
<table class="plan-table"><thead><tr><th>Clienti personali nel mese</th><th>Percentuale</th></tr></thead><tbody>
@foreach($k['direct_tiers'] ?? [] as $i => $t)
 @php($next = ($k['direct_tiers'][$i + 1]['min_clients'] ?? null))
 <tr><td>{{ $next === null ? 'da '.$t['min_clients'] : ($t['min_clients'] === 0 ? 'fino a '.($next - 1) : 'da '.$t['min_clients'].' a '.($next - 1)) }}</td><td>{{ $pct($t['pct']) }}</td></tr>
@endforeach
</tbody></table>

<h2>3. Compensi indiretti</h2>
<p>Ricevi una percentuale sulle vendite dei tuoi incaricati, fino al {{ $maxLevel }}° livello, se nel mese rispetti i requisiti del livello.</p>
<table class="plan-table"><thead><tr><th>Livello</th><th>Percentuale</th><th>Clienti personali richiesti</th><th>Pearl al 1° livello</th></tr></thead><tbody>
@foreach($levels as $l)
 <tr><td>{{ $l['level'] }}°</td><td>{{ $pct($l['pct']) }}</td><td>{{ $l['min_clients'] }}</td><td>{{ $l['min_pearl'] > 0 ? $l['min_pearl'] : '—' }}</td></tr>
@endforeach
</tbody></table>
@if(($plan['policies']['kosmos_compression'] ?? 'skip') === 'skip')
 <p><strong>Compressione della linea.</strong> Se un incaricato sopra chi vende non rispetta i requisiti del livello, viene saltato e il compenso passa al primo incaricato sopra di lui che li rispetta: i livelli si contano solo tra chi li rispetta e, su una stessa vendita, nessuno riceve due compensi.</p>
@else
 <p>Il livello è la distanza nella linea di sponsorizzazione: se un incaricato non rispetta i requisiti del suo livello, quel livello non viene pagato.</p>
@endif

@if(! empty($k['extended_pct']))
<h3>Indiretti estesi</h3>
<p>Le qualifiche
 @foreach(array_keys($k['extended_pct']) as $i => $code){{ $i > 0 ? ($loop->last ? ' e ' : ', ') : '' }}{{ $rankName($code) }}@endforeach
 ricevono inoltre una percentuale sulle vendite oltre il {{ $maxLevel }}° livello, fino al {{ $k['extended_stop_levels'] ?? 5 }}° livello del successivo incaricato con
 {{ ($plan['policies']['kosmos_extended_stop'] ?? 'same_or_higher') === 'same_rank' ? 'la stessa qualifica' : 'qualifica uguale o superiore' }}.</p>
<table class="plan-table"><thead><tr><th>Qualifica</th><th>Percentuale</th></tr></thead><tbody>
 @foreach($k['extended_pct'] as $code => $v)<tr><td>{{ $rankName($code) }}</td><td>{{ $pct($v) }}</td></tr>@endforeach
</tbody></table>
@endif

<h2>4. Qualifiche e requisiti</h2>
<p>Ogni mese ti viene riconosciuta la qualifica più alta di cui rispetti <strong>tutti</strong> i requisiti. Se non ne rispetti più almeno uno, retrocedi alla qualifica più alta di cui li rispetti ancora. Le qualifiche sono {{ $ranks->where('sequence', '>', 0)->pluck('name')->implode(', ') }}.</p>
<table class="plan-table"><thead><tr><th>Qualifica</th><th>Clienti personali</th><th>Incaricati almeno Pearl al 1° livello</th><th>Altri incaricati</th><th>Colonne</th></tr></thead><tbody>
@foreach($ranks->where('sequence', '>', 0) as $r)
 @php($off = $r['disabled_requirements'] ?? [])
 <tr>
  <td><strong>{{ $r['name'] }}</strong></td>
  <td>{{ ($r['min_clients'] ?? 0) > 0 && ! in_array('clients', $off, true) ? $r['min_clients'] : '—' }}</td>
  <td>{{ ($r['min_pearl_first_level'] ?? 0) > 0 && ! in_array('pearl', $off, true) ? $r['min_pearl_first_level'] : '—' }}</td>
  <td>@if(! empty($r['structure']) && ! in_array('structure', $off, true)){{ collect($r['structure'])->map(fn ($x) => $x['count'].' '.($x['count'] === 1 ? 'incaricato' : 'incaricati').' almeno '.$rankName($x['rank']))->implode(' e ') }} su {{ collect($r['structure'])->sum('count') }} colonne diverse@else — @endif</td>
  <td>{{ ($r['min_columns'] ?? 0) > 0 && ! in_array('columns', $off, true) ? $r['min_columns'].' colonne da '.$r['min_column_clients'].' clienti' : '—' }}</td>
 </tr>
@endforeach
</tbody></table>
<p class="small">«Incaricati almeno Pearl al 1° livello»: incaricati portati direttamente da te con qualifica Pearl o superiore. «Altri incaricati»: incaricati con almeno la qualifica indicata (vale anche una qualifica superiore), ciascuno su una colonna diversa. «Colonne»: una colonna è un tuo incaricato diretto con tutta la sua rete; i clienti della colonna sono quelli personali di tutti i suoi componenti.</p>
@if($graceEnd)
 <p><strong>Passaggio al nuovo piano.</strong> Fino al {{ $graceEnd->format('d/m/Y') }} gli incaricati già qualificati nel piano precedente mantengono la loro qualifica; al termine di questo periodo tutti vengono ricalcolati sul piano in vigore e chi non rispetta i requisiti retrocede.</p>
@endif

<h2>5. Bonus di Struttura</h2>
<p>Quando un nuovo incaricato porta <strong>{{ $k['structure_clients'] ?? 3 }} clienti personali entro {{ $k['structure_window_days'] ?? 30 }} giorni</strong> dall'attivazione diventa «PearlQ» e genera un bonus per chi sta sopra di lui. Ogni incaricato della sua linea riceve l'importo della propria qualifica meno l'importo della qualifica più alta già presente tra il nuovo incaricato e sé stesso, così il bonus non si paga due volte.</p>
<table class="plan-table"><thead><tr><th>Qualifica</th><th>Bonus</th></tr></thead><tbody>
@foreach($k['structure_bonus'] ?? [] as $code => $v)<tr><td>{{ $rankName($code) }}</td><td>{{ $eur($v) }}</td></tr>@endforeach
</tbody></table>
<p class="small">Il Bonus di Struttura si calcola ogni settimana e viene liquidato dopo la conferma del Consorzio.</p>

<h2>6. Pagamenti</h2>
<p>I compensi maturati nel mese vengono liquidati entro il giorno {{ $st['payout_day'] ?? 15 }} del mese successivo, se l'importo raggiunge il minimo di {{ $eur($st['min_payout'] ?? 15) }}; sotto il minimo si sommano al mese seguente. Per ricevere i pagamenti serve l'onboarding completo (documento e IBAN). Gli importi sono al lordo di eventuali ritenute e imposte previste dalla legge.</p>

<h2>Avvertenze</h2>
<p class="small">I compensi derivano esclusivamente dalle vendite di prodotti a clienti finali: non è dovuto alcun compenso per il solo reclutamento di nuovi incaricati e i guadagni dipendono dall'impegno commerciale di ciascuno, senza alcuna garanzia. Resi e annullamenti possono ridurre o annullare i compensi già calcolati. Il Consorzio può modificare il piano con le modalità previste dal Regolamento; la versione in vigore è sempre quella pubblicata in questa pagina.</p>
<p class="small">Versione del piano: {{ $version->name }}@if($since), in vigore dal {{ $since->format('d/m/Y') }}@endif.</p>
@endif
