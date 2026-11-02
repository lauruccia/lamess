@extends('site.front.layout')
@section('title', 'Prodotto e ingredienti')
@section('description', 'Ingredienti, dosi e valori nutrizionali di Lamèss Cosmetic Coffee: caffè Arabica AAA, collagene VERISOL, acido ialuronico, Q10, vitamine.')
@section('content')
@verbatim
<section class="dark pagehead"><div class="wrap"><p class="crumbs"><a href="/">Home</a> / Prodotto</p><h1>Il prodotto.</h1><p>Cosa c’è in una bustina, in che dose e come si prepara.</p></div></section>
<section class="light band">
  <div class="wrap split">
    <img class="prod-img" src="/img/front/6d7674f3-4694-4e1a-a026-df050525cb7a.webp" alt="Box Lamèss Cosmetic Coffee">
    <div>
      <h2>Cosmetic Coffee.</h2>
      <p class="lead" style="margin-top:22px">Un caffè istantaneo 100% Arabica di classe tripla AAA, con collagene VERISOL®, acido ialuronico, coenzima Q10 e una selezione di ingredienti della nutricosmetica.</p>
      <dl class="facts">
        <div><dt>50 buste</dt><dd>per box, 255 g</dd></div>
        <div><dt>2 buste al giorno</dt><dd>dose massima giornaliera</dd></div>
        <div><dt>Senza glutine</dt><dd>e senza lattosio</dd></div>
        <div><dt>Caffè Classe AAA</dt><dd>100% Arabica</dd></div>
      </dl>
    </div>
  </div>
</section>

<section class="dark band" id="ingredienti">
  <div class="wrap">
    <div class="ing-head"><h2>La tabella degli ingredienti.</h2><p class="muted" style="max-width:40ch">Valori per la dose massima di 2 buste, come in etichetta.</p></div>
    <div class="tbl"><table>
      <thead><tr><th scope="col">Ingrediente</th><th scope="col">Per dose giornaliera</th><th scope="col">VNR*</th></tr></thead>
      <tbody>
        <tr><td>Caffè istantaneo</td><td>7000 mg</td><td></td></tr>
        <tr class="key"><td>Collagene idrolizzato VERISOL®</td><td>2500 mg</td><td></td></tr>
        <tr class="key"><td>Acido ialuronico</td><td>190 mg</td><td></td></tr>
        <tr><td>Vitamina E</td><td>30 mg</td><td>250%</td></tr>
        <tr><td>Estratto secco di rosa canina, titolato al 70% in vitamina C</td><td>114,3 mg</td><td></td></tr>
        <tr class="sub"><td>di cui vitamina C</td><td>80 mg</td><td>100%</td></tr>
        <tr class="key"><td>Coenzima Q10</td><td>20 mg</td><td></td></tr>
        <tr><td>Selenio</td><td>50 mcg</td><td>90,8%</td></tr>
        <tr><td>Beta-carotene</td><td>3,7 mg</td><td></td></tr>
        <tr class="sub"><td>di cui vitamina A</td><td>624 mcg</td><td>78%</td></tr>
        <tr><td>Biotina</td><td>50 mcg</td><td>100%</td></tr>
      </tbody></table></div>
    <p class="disclaimer">*VNR: valori nutritivi di riferimento per adulti (Reg. UE 1169/2011).</p>
  </div>
</section>

<section class="light band">
  <div class="wrap">
    <h2 style="margin-bottom:44px;max-width:18ch">Ingrediente per ingrediente.</h2>
    <div class="ingr">
      <article><h3>Collagene VERISOL®</h3><span class="dose">2,5 g per dose giornaliera</span><p>Collagene idrolizzato, nella quantità usata in uno studio su 114 donne. Le fonti sono nella pagina <a href="/la-scienza">La scienza</a>.</p></article>
      <article><h3>Acido ialuronico</h3><span class="dose">190 mg</span><p>Uno degli ingredienti cardine della nutricosmetica, presente nella dose dichiarata in etichetta.</p></article>
      <article><h3>Coenzima <span class="num">Q10</span></h3><span class="dose">20 mg</span><p>Presente nella formulazione insieme a collagene e acido ialuronico, in dose dichiarata.</p></article>
      <article><h3>Vitamina C da rosa canina</h3><span class="dose">80 mg, 100% VNR</span><p>Contribuisce alla normale formazione del collagene per la normale funzione della pelle.</p></article>
      <article><h3>Vitamina E e selenio</h3><span class="dose">30 mg e 50 mcg</span><p>Vitamina E e selenio contribuiscono alla protezione delle cellule dallo stress ossidativo.</p></article>
      <article><h3>Beta-carotene e biotina</h3><span class="dose">3,7 mg e 50 mcg</span><p>La biotina contribuisce al mantenimento di una pelle normale; il beta-carotene apporta vitamina A.</p></article>
    </div>
    <p class="disclaimer" style="color:var(--brown);margin-top:26px">Lo studio su VERISOL® riguarda il solo ingrediente e non dimostra gli effetti del prodotto finito. <a href="/la-scienza" style="color:var(--brown)">Tutti gli studi e le fonti</a>.</p>
  </div>
</section>

<section class="white band">
  <div class="wrap">
    <h2 style="margin-bottom:40px">Come si prepara.</h2>
    <div class="feat">
      <div><h3>Calda</h3><p>Scioglila in acqua calda e mescola: il caffè resta caffè, con tutto il suo aroma.</p></div>
      <div><h3>Fredda</h3><p>Scioglila in acqua fredda, anche in borraccia: pronta da bere ovunque tu sia.</p></div>
      <div><h3>Ricette</h3><p>Ricette calde e fredde create con il nostro Coffee Specialist, anche per gli eventi.</p></div>
    </div>
  </div>
</section>
<section class="dark band" style="padding-block:clamp(56px,7vw,90px)"><div class="wrap cta-band"><h2>Pronto a provarlo?</h2><a class="btn solid" href="/negozio">Scegli la tua box</a></div></section>
@endverbatim
@endsection
