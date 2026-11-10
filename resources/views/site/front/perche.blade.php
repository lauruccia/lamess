@extends('site.front.layout')
@section('title', __('Perché Lamèss'))
@section('description', __('Perché scegliere Lamèss: un rituale quotidiano che unisce caffè e ingredienti selezionati per la bellezza e il benessere.'))
@section('content')
<section class="dark pagehead"><div class="wrap"><p class="crumbs"><a href="/">{{ __('Home') }}</a> {{ __('/ Perché Lamèss') }}</p><h1>{{ __('Perché scegliere Lamèss.') }}</h1><p>{{ __('Nove motivi concreti per scegliere ogni giorno Lamèss.') }}</p></div></section>
<section class="light band">
  <div class="wrap">
    <div class="reasons">
      <div class="reason"><h3>{{ __('Caffè e integrazione nello stesso gesto') }}</h3><div><p>{{ __('L’integrazione quotidiana funziona se la fai ogni giorno. Il caffè lo bevi già: mettendo tutto nella stessa bustina non devi ricordarti di niente in più.') }}</p></div></div>
      <div class="reason"><h3>{{ __('Un caffè vero') }}</h3><div><p>{{ __('La base è un caffè istantaneo 100% Arabica di classe tripla AAA. Il passaggio più delicato della formulazione è stato integrare gli ingredienti conservando gusto e profumo.') }}</p></div></div>
      <div class="reason"><h3>{{ __('Dosi chiare, scritte in etichetta') }}</h3><div><p>{{ __('Per la dose giornaliera trovi 2,5 g di collagene idrolizzato VERISOL®, 190 mg di acido ialuronico e 20 mg di coenzima Q10, insieme a vitamine E e C, selenio, beta-carotene e biotina.') }}</p></div></div>
      <div class="reason"><h3>{{ __('Una dose che ha alle spalle uno studio') }}</h3><div><p>{{ __('La quantità di VERISOL® coincide con quella usata in uno studio su 114 donne, in cui dopo otto settimane è stata osservata una riduzione del volume delle rughe perioculari. Lo studio riguarda il solo ingrediente, e non dimostra gli effetti del prodotto finito.') }}</p></div></div>
      <div class="reason"><h3>{{ __('Zero zuccheri') }}</h3><div><p>{{ __('Nessuno zucchero nella bustina: gusto autentico, senza alterazioni, adatto a stili di vita salutari.') }}</p></div></div>
      <div class="reason"><h3>{{ __('Si prende ovunque') }}</h3><div><p>{{ __('Ufficio, viaggio, hotel, camper, palestra. Non servono macchina, capsule o tempo: una bustina e acqua, calda o fredda.') }}</p></div></div>
      <div class="reason"><h3>{{ __('Formulazione protetta, marchio registrato') }}</h3><div><p>{{ __('La tutela brevettuale riguarda gli aspetti innovativi della formulazione, mentre il nome Lamèss è protetto dalla registrazione del marchio. Sono due tutele distinte.') }}</p></div></div>
      <div class="reason"><h3>{{ __('Un prodotto riconoscibile nel canale farmacia') }}</h3><div><p>{{ __('Lamèss Cosmetic Coffee è inserito nella banca dati Farmadati, che farmacie e parafarmacie usano per identificare i prodotti.') }}</p></div></div>
      <div class="reason"><h3>{{ __('Un abbonamento senza trappole') }}</h3><div><p>{{ __('Nessuna spedizione da pagare, indirizzo modificabile fino a 10 indirizzi salvati, un mese saltato se parti per le vacanze, e si interrompe quando vuoi.') }}</p></div></div>
    </div>
  </div>
</section>

<section class="white band">
  <div class="wrap">
    <h2 style="margin-bottom:12px;max-width:20ch">{{ __('Lamèss a confronto.') }}</h2>
    <p class="muted" style="margin-bottom:34px">{{ __('Quello che cambia nella routine di tutti i giorni.') }}</p>
    <div class="cmp"><table>
      <thead><tr><th></th><th>{{ __('Caffè tradizionale') }}</th><th>{{ __('Integratore classico') }}</th><th class="us">{{ __('Lamèss') }}</th></tr></thead>
      <tbody>
        <tr><td>{{ __('Il piacere del caffè') }}</td><td>{{ __('Sì') }}</td><td>{{ __('No') }}</td><td class="us">{{ __('Sì, 100% Arabica AAA') }}</td></tr>
        <tr><td>{{ __('Integrazione quotidiana') }}</td><td>{{ __('No') }}</td><td>{{ __('Sì') }}</td><td class="us">{{ __('Sì, dosi in etichetta') }}</td></tr>
        <tr><td>{{ __('Gesto in più da ricordare') }}</td><td>{{ __('Nessuno') }}</td><td>{{ __('Sì, ogni giorno') }}</td><td class="us">{{ __('Nessuno') }}</td></tr>
        <tr><td>{{ __('Si porta in tasca') }}</td><td>{{ __('Dipende') }}</td><td>{{ __('Sì') }}</td><td class="us">{{ __('Sì, bustina monodose') }}</td></tr>
        <tr><td>{{ __('Calda o fredda') }}</td><td>{{ __('Dipende') }}</td><td>{{ __('Non si applica') }}</td><td class="us">{{ __('Sì, come preferisci') }}</td></tr>
      </tbody></table></div>
  </div>
</section>
<section class="dark band" style="padding-block:clamp(56px,7vw,90px)"><div class="wrap cta-band"><h2>{{ __('Provalo con una box.') }}</h2>@if(\App\Support\ShopAccess::canBuy())<a class="btn solid" href="/negozio">{{ __('Vai allo shop') }}</a>@else<a class="btn solid" href="/accedi">{{ __('Accedi per acquistare') }}</a>@endif</div></section>
@endsection
