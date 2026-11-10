@extends('site.front.layout')
@section('title', __('Prodotto e ingredienti'))
@section('description', __('Ingredienti, dosi e valori nutrizionali di Lamèss Cosmetic Coffee: caffè Arabica AAA, collagene VERISOL, acido ialuronico, Q10, vitamine.'))
@section('content')
<section class="dark pagehead"><div class="wrap"><p class="crumbs"><a href="/">{{ __('Home') }}</a> {{ __('/ Prodotto') }}</p><h1>{{ __('Il prodotto.') }}</h1><p>{{ __('Cosa c’è in una bustina, in che dose e come si prepara.') }}</p></div></section>
<section class="light band">
  <div class="wrap split">
    <img class="prod-img" src="/img/front/6d7674f3-4694-4e1a-a026-df050525cb7a.webp" alt="{{ __('Box Lamèss Cosmetic Coffee') }}">
    <div>
      <h2>{{ __('Cosmetic Coffee.') }}</h2>
      <p class="lead" style="margin-top:22px">{{ __('Un caffè istantaneo 100% Arabica di classe tripla AAA, con collagene VERISOL®, acido ialuronico, coenzima Q10 e una selezione di ingredienti della nutricosmetica.') }}</p>
      <dl class="facts">
        <div><dt>{{ __('50 buste') }}</dt><dd>{{ __('per box, 255 g') }}</dd></div>
        <div><dt>{{ __('2 buste al giorno') }}</dt><dd>{{ __('dose massima giornaliera') }}</dd></div>
        <div><dt>{{ __('Senza glutine') }}</dt><dd>{{ __('e senza lattosio') }}</dd></div>
        <div><dt>{{ __('Caffè Classe AAA') }}</dt><dd>{{ __('100% Arabica') }}</dd></div>
      </dl>
    </div>
  </div>
</section>

<section class="dark band" id="ingredienti">
  <div class="wrap">
    <div class="ing-head"><h2>{{ __('La tabella degli ingredienti.') }}</h2><p class="muted" style="max-width:40ch">{{ __('Valori per la dose massima di 2 buste, come in etichetta.') }}</p></div>
    <div class="tbl"><table>
      <thead><tr><th scope="col">{{ __('Ingrediente') }}</th><th scope="col">{{ __('Per dose giornaliera') }}</th><th scope="col">{{ __('VNR*') }}</th></tr></thead>
      <tbody>
        <tr><td>{{ __('Caffè istantaneo') }}</td><td>{{ __('7000 mg') }}</td><td></td></tr>
        <tr class="key"><td>{{ __('Collagene idrolizzato VERISOL®') }}</td><td>{{ __('2500 mg') }}</td><td></td></tr>
        <tr class="key"><td>{{ __('Acido ialuronico') }}</td><td>{{ __('190 mg') }}</td><td></td></tr>
        <tr><td>{{ __('Vitamina E') }}</td><td>{{ __('30 mg') }}</td><td>250%</td></tr>
        <tr><td>{{ __('Estratto secco di rosa canina, titolato al 70% in vitamina C') }}</td><td>{{ __('114,3 mg') }}</td><td></td></tr>
        <tr class="sub"><td>{{ __('di cui vitamina C') }}</td><td>{{ __('80 mg') }}</td><td>100%</td></tr>
        <tr class="key"><td>{{ __('Coenzima Q10') }}</td><td>{{ __('20 mg') }}</td><td></td></tr>
        <tr><td>{{ __('Selenio') }}</td><td>{{ __('50 mcg') }}</td><td>90,8%</td></tr>
        <tr><td>{{ __('Beta-carotene') }}</td><td>{{ __('3,7 mg') }}</td><td></td></tr>
        <tr class="sub"><td>{{ __('di cui vitamina A') }}</td><td>{{ __('624 mcg') }}</td><td>78%</td></tr>
        <tr><td>{{ __('Biotina') }}</td><td>{{ __('50 mcg') }}</td><td>100%</td></tr>
      </tbody></table></div>
    <p class="disclaimer">{{ __('*VNR: valori nutritivi di riferimento per adulti (Reg. UE 1169/2011).') }}</p>
  </div>
</section>

<section class="light band">
  <div class="wrap">
    <h2 style="margin-bottom:44px;max-width:18ch">{{ __('Ingrediente per ingrediente.') }}</h2>
    <div class="ingr">
      <article><h3>{{ __('Collagene VERISOL®') }}</h3><span class="dose">{{ __('2,5 g per dose giornaliera') }}</span><p>{!! __('Collagene idrolizzato, nella quantità usata in uno studio su 114 donne. Le fonti sono nella pagina <a href="/la-scienza">La scienza</a>.') !!}</p></article>
      <article><h3>{{ __('Acido ialuronico') }}</h3><span class="dose">{{ __('190 mg') }}</span><p>{{ __('Uno degli ingredienti cardine della nutricosmetica, presente nella dose dichiarata in etichetta.') }}</p></article>
      <article><h3>{{ __('Coenzima') }} <span class="num">Q10</span></h3><span class="dose">{{ __('20 mg') }}</span><p>{{ __('Presente nella formulazione insieme a collagene e acido ialuronico, in dose dichiarata.') }}</p></article>
      <article><h3>{{ __('Vitamina C da rosa canina') }}</h3><span class="dose">{{ __('80 mg, 100% VNR') }}</span><p>{{ __('Contribuisce alla normale formazione del collagene per la normale funzione della pelle.') }}</p></article>
      <article><h3>{{ __('Vitamina E e selenio') }}</h3><span class="dose">{{ __('30 mg e 50 mcg') }}</span><p>{{ __('Vitamina E e selenio contribuiscono alla protezione delle cellule dallo stress ossidativo.') }}</p></article>
      <article><h3>{{ __('Beta-carotene e biotina') }}</h3><span class="dose">{{ __('3,7 mg e 50 mcg') }}</span><p>{{ __('La biotina contribuisce al mantenimento di una pelle normale; il beta-carotene apporta vitamina A.') }}</p></article>
    </div>
    <p class="disclaimer" style="color:var(--brown);margin-top:26px">{!! __('Lo studio su VERISOL® riguarda il solo ingrediente e non dimostra gli effetti del prodotto finito. <a href="/la-scienza" style="color:var(--brown)">Tutti gli studi e le fonti</a>.') !!}</p>
  </div>
</section>

<section class="white band">
  <div class="wrap">
    <h2 style="margin-bottom:40px">{{ __('Come si prepara.') }}</h2>
    <div class="feat">
      <div><h3>{{ __('Calda') }}</h3><p>{{ __('Scioglila in acqua calda e mescola: il caffè resta caffè, con tutto il suo aroma.') }}</p></div>
      <div><h3>{{ __('Fredda') }}</h3><p>{{ __('Scioglila in acqua fredda, anche in borraccia: pronta da bere ovunque tu sia.') }}</p></div>
      <div><h3>{{ __('Ricette') }}</h3><p>{{ __('Ricette calde e fredde create con il nostro Coffee Specialist, anche per gli eventi.') }}</p></div>
    </div>
  </div>
</section>
<section class="dark band" style="padding-block:clamp(56px,7vw,90px)"><div class="wrap cta-band"><h2>{{ __('Pronto a provarlo?') }}</h2>@if(\App\Support\ShopAccess::canBuy())<a class="btn solid" href="/negozio">{{ __('Scegli la tua box') }}</a>@else<a class="btn solid" href="/accedi">{{ __('Accedi per acquistare') }}</a>@endif</div></section>
@endsection
