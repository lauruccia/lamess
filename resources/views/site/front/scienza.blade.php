@extends('site.front.layout')
@section('title', __('La scienza dietro Lamèss'))
@section('description', __('Le ricerche scientifiche sugli ingredienti di Lamèss, con fonti verificabili su PubMed e DOI.'))
@section('content')
<section class="dark pagehead"><div class="wrap"><p class="crumbs"><a href="/">{{ __('Home') }}</a> {{ __('/ La scienza') }}</p><h1>{{ __('La scienza dietro Lamèss.') }}</h1><p>{{ __('Gli studi sugli ingredienti, con dosi, numeri e fonti. Cosa mostrano e cosa non mostrano.') }}</p><nav class="toc" aria-label="{{ __('In questa pagina') }}"><a href="#come-leggere">{{ __('Come leggere gli studi') }}</a><a href="#collagene">{{ __('Collagene') }}</a><a href="#ialuronico">{{ __('Acido ialuronico') }}</a><a href="#costanza">{{ __('Costanza') }}</a><a href="#altri">{{ __('Altri ingredienti') }}</a><a href="#fonti">{{ __('Fonti') }}</a></nav></div></section>
<section class="light band" id="come-leggere">
  <div class="wrap">
    <div class="intro">
      <div><p class="kicker">{{ __('La bellezza incontra la ricerca') }}</p><h2>{{ __('Siamo partiti dagli studi, non dalle promesse.') }}</h2></div>
      <p>{{ __('Lamèss nasce dall’incontro tra il caffè e ingredienti conosciuti e studiati nella nutrizione e nella nutricosmetica. Al centro della formula ci sono tre ingredienti. Per collagene e acido ialuronico qui trovi una selezione degli studi più pertinenti, con dosi e risultati e il confronto con la dose di Lamèss.') }}</p>
    </div>
    <div class="pils" style="margin-top:56px">
      <div class="pil"><h3>{{ __('Peptidi di collagene VERISOL®') }}</h3><span class="dose">2,5 g</span><p>{{ __('per dose giornaliera. Peptidi bioattivi di collagene, oggetto di studi clinici randomizzati sull’uomo.') }}</p></div>
      <div class="pil"><h3>{{ __('Acido ialuronico') }}</h3><span class="dose">{{ __('190 mg') }}</span><p>{{ __('per dose giornaliera. Sostanza presente nell’organismo, con una forte capacità di legare l’acqua.') }}</p></div>
      <div class="pil"><h3>{{ __('Coenzima') }} <span class="num">Q10</span></h3><span class="dose">{{ __('20 mg') }}</span><p>{{ __('per dose giornaliera. Molecola presente nell’organismo, coinvolta nella produzione di energia delle cellule.') }}</p></div>
    </div>
    <p class="note-box" style="margin-top:44px">{!! __('<b>Come leggere questa pagina.</b> Gli studi citati riguardano i singoli ingredienti, a certe dosi, per certe durate e su certe persone. Non sono studi sul prodotto finito Lamèss Cosmetic Coffee e non ne dimostrano gli effetti. Quando la dose di Lamèss coincide con quella di uno studio lo diciamo, e lo diciamo anche quando è diversa.') !!}</p>
  </div>
</section>

<section class="white band">
  <div class="wrap">
    <h2 style="margin-bottom:12px;max-width:20ch">{{ __('Le dosi a confronto.') }}</h2>
    <p class="muted" style="margin-bottom:30px">{{ __('Dose per dose giornaliera di Lamèss e dosi usate negli studi selezionati.') }}</p>
    <div class="cmp ev"><table>
      <thead><tr><th>{{ __('Ingrediente') }}</th><th>{{ __('In Lamèss') }}</th><th>{{ __('Negli studi') }}</th><th>{{ __('Confronto') }}</th></tr></thead>
      <tbody>
        <tr><td>{{ __('Peptidi di collagene') }}</td><td>2,5 g</td><td>{{ __('2,5 g (rughe), 2,5 e 5 g (elasticità)') }}</td><td class="same">{{ __('Dose uguale') }}</td></tr>
        <tr><td>{{ __('Acido ialuronico') }}</td><td>{{ __('190 mg') }}</td><td>{{ __('120 mg (due studi), non riportata (un terzo)') }}</td><td class="diff">{{ __('Dose diversa: in Lamèss è più alta') }}</td></tr>
      </tbody></table></div>
  </div>
</section>

<section class="light band" id="collagene">
  <div class="wrap">
    <p class="kicker">{{ __('Primo ingrediente') }}</p>
    <h2 style="max-width:18ch;margin-bottom:18px">{{ __('Peptidi specifici di collagene.') }}</h2>
    <p class="lead" style="margin-bottom:38px">{{ __('VERISOL® non è “collagene” in generale: è costituito da specifici peptidi bioattivi. La dose giornaliera di Lamèss apporta 2,5 g, la stessa quantità usata nello studio sulle rughe perioculari. Questo rende il confronto della dose pertinente, ma non trasforma lo studio in uno studio su Lamèss.') }}</p>
    <article class="study" id="studio-1"><dl><div><dt>{{ __('Partecipanti') }}</dt><dd>{{ __('114 donne, 45–65 anni') }}</dd></div><div><dt>{{ __('Dose') }}</dt><dd>{{ __('2,5 g al giorno di peptidi bioattivi di collagene (VERISOL®)') }}</dd></div><div><dt>{{ __('Durata') }}</dt><dd>{{ __('8 settimane, più 4 di osservazione') }}</dd></div><div><dt>{{ __('Disegno') }}</dt><dd>{{ __('Randomizzato, doppio cieco, placebo') }}</dd></div></dl><div><h4>{!! __('Peptidi di collagene e rughe perioculari <sup><a href="#r1">[1]</a></sup>') !!}</h4><p class="res">{{ __('Rispetto al placebo, il volume delle rughe perioculari è diminuito in modo statisticamente significativo dopo 4 e 8 settimane (20% a 8 settimane, secondo lo studio). L’effetto era ancora presente 4 settimane dopo la sospensione. In un sottogruppo, dopo 8 settimane, procollagene di tipo I (+65%) ed elastina (+18%) erano più alti rispetto al placebo.') }}</p><p class="cite">{!! __('Proksch E, Schunck M, Zague V, Segger D, Degwert J, Oesser S. Oral intake of specific bioactive collagen peptides reduces skin wrinkles and increases dermal matrix synthesis. <i>Skin Pharmacology and Physiology</i>. 2014;27(3):113–119.') !!}<span class="links"><a href="https://pubmed.ncbi.nlm.nih.gov/24401291/">{{ __('Scheda su PubMed') }}</a><a href="https://doi.org/10.1159/000355523">{{ __('DOI 10.1159/000355523') }}</a></span></p></div></article><article class="study" id="studio-2"><dl><div><dt>{{ __('Partecipanti') }}</dt><dd>{{ __('69 donne, 35–55 anni (23 per gruppo)') }}</dd></div><div><dt>{{ __('Dose') }}</dt><dd>{{ __('2,5 g o 5 g al giorno di collagene idrolizzato (peptidi specifici)') }}</dd></div><div><dt>{{ __('Durata') }}</dt><dd>{{ __('8 settimane, più 4 di osservazione') }}</dd></div><div><dt>{{ __('Disegno') }}</dt><dd>{{ __('Randomizzato, doppio cieco, placebo') }}</dd></div></dl><div><h4>{!! __('Peptidi di collagene ed elasticità della pelle <sup><a href="#r2">[2]</a></sup>') !!}</h4><p class="res">{{ __('L’elasticità cutanea, parametro principale, è migliorata in modo statisticamente significativo rispetto al placebo in entrambi i gruppi che assumevano collagene. Quattro settimane dopo l’ultima assunzione, nelle donne più anziane l’elasticità era ancora più alta del placebo. Nessun effetto collaterale segnalato.') }}</p><p class="lim">{{ __('Idratazione e perdita d’acqua mostravano una tendenza positiva in un sottogruppo, ma senza raggiungere la significatività statistica. Il riassunto dello studio non indica la denominazione commerciale del collagene.') }}</p><p class="cite">{!! __('Proksch E, Segger D, Degwert J, Schunck M, Zague V, Oesser S. Oral supplementation of specific collagen peptides has beneficial effects on human skin physiology: a double-blind, placebo-controlled study. <i>Skin Pharmacology and Physiology</i>. 2014;27(1):47–55.') !!}<span class="links"><a href="https://pubmed.ncbi.nlm.nih.gov/23949208/">{{ __('Scheda su PubMed') }}</a><a href="https://doi.org/10.1159/000351376">{{ __('DOI 10.1159/000351376') }}</a></span></p></div></article>
  </div>
</section>

<section class="white band" id="ialuronico">
  <div class="wrap">
    <p class="kicker">{{ __('Secondo ingrediente') }}</p>
    <h2 style="max-width:18ch;margin-bottom:18px">{{ __('Acido ialuronico.') }}</h2>
    <p class="lead" style="margin-bottom:38px">{{ __('Lamèss ne contiene 190 mg per dose giornaliera. Oltre all’uso cosmetico, negli ultimi anni è stata studiata anche l’assunzione orale.') }}</p>
    <article class="study" id="studio-3"><dl><div><dt>{{ __('Partecipanti') }}</dt><dd>{{ __('60 persone, 22–59 anni, con rughe perioculari') }}</dd></div><div><dt>{{ __('Dose') }}</dt><dd>{{ __('120 mg al giorno di acido ialuronico (peso molecolare 2 k o 300 k)') }}</dd></div><div><dt>{{ __('Durata') }}</dt><dd>{{ __('12 settimane') }}</dd></div><div><dt>{{ __('Disegno') }}</dt><dd>{{ __('Randomizzato, doppio cieco, placebo') }}</dd></div></dl><div><h4>{!! __('Acido ialuronico orale e rughe <sup><a href="#r3">[3]</a></sup>') !!}</h4><p class="res">{{ __('I gruppi con acido ialuronico hanno mostrato valori migliori del placebo per volume e area delle rughe. Dopo 8 settimane il gruppo con peso molecolare 300 k mostrava una riduzione delle rughe statisticamente significativa rispetto al placebo.') }}</p><p class="lim">{{ __('Partecipanti giapponesi. La dose dello studio (120 mg) è diversa da quella di Lamèss (190 mg).') }}</p><p class="cite">{!! __('Oe M, Sakai S, Yoshida H, Okado N, Kaneda H, Masuda Y, Urushibata O. Oral hyaluronan relieves wrinkles: a double-blinded, placebo-controlled study over a 12-week period. <i>Clinical, Cosmetic and Investigational Dermatology</i>. 2017;10:267–273.') !!}<span class="links"><a href="https://pubmed.ncbi.nlm.nih.gov/28761365/">{{ __('Scheda su PubMed') }}</a><a href="https://doi.org/10.2147/CCID.S141845">{{ __('DOI 10.2147/CCID.S141845') }}</a></span></p></div></article><article class="study" id="studio-4"><dl><div><dt>{{ __('Partecipanti') }}</dt><dd>{{ __('40 persone sane, 35–64 anni') }}</dd></div><div><dt>{{ __('Dose') }}</dt><dd>{{ __('120 mg al giorno di acido ialuronico') }}</dd></div><div><dt>{{ __('Durata') }}</dt><dd>{{ __('12 settimane') }}</dd></div><div><dt>{{ __('Disegno') }}</dt><dd>{{ __('Randomizzato, doppio cieco, placebo') }}</dd></div></dl><div><h4>{!! __('Acido ialuronico orale, rughe e pelle secca <sup><a href="#r4">[4]</a></sup>') !!}</h4><p class="res">{{ __('Dopo 12 settimane il gruppo con acido ialuronico ha mostrato differenze significative rispetto al placebo nella valutazione delle rughe, nel contenuto d’acqua dello strato corneo, nella perdita d’acqua transepidermica e nell’elasticità. Nella variazione percentuale rispetto all’inizio, rughe, idratazione ed elasticità risultavano migliori già a 8 settimane.') }}</p><p class="lim">{{ __('Partecipanti asiatici. La dose dello studio (120 mg) è diversa da quella di Lamèss (190 mg).') }}</p><p class="cite">{!! __('Hsu TF, Su ZR, Hsieh YH, Wang MF, Oe M, Matsuoka R, Masuda Y. Oral hyaluronan relieves wrinkles and improves dry skin: a 12-week double-blinded, placebo-controlled study. <i>Nutrients</i>. 2021;13(7):2220.') !!}<span class="links"><a href="https://pubmed.ncbi.nlm.nih.gov/34203487/">{{ __('Scheda su PubMed') }}</a><a href="https://doi.org/10.3390/nu13072220">{{ __('DOI 10.3390/nu13072220') }}</a></span></p></div></article><article class="study" id="studio-5"><dl><div><dt>{{ __('Partecipanti') }}</dt><dd>{{ __('129 donne, giovani e anziane, con diversi tipi di pelle') }}</dd></div><div><dt>{{ __('Dose') }}</dt><dd>{{ __('Non riportata nel riassunto') }}</dd></div><div><dt>{{ __('Durata') }}</dt><dd>{{ __('Fino a 12 settimane') }}</dd></div><div><dt>{{ __('Disegno') }}</dt><dd>{{ __('Randomizzato, doppio cieco') }}</dd></div></dl><div><h4>{!! __('Acido ialuronico orale in età e tipi di pelle diversi <sup><a href="#r5">[5]</a></sup>') !!}</h4><p class="res">{{ __('L’idratazione è migliorata dopo 2–8 settimane sia nelle giovani sia nelle anziane, il tono della pelle dopo 4–8 settimane e lo spessore dell’epidermide dopo 12 settimane.') }}</p><p class="lim">{{ __('Per confrontare la dose con quella di Lamèss serve leggere l’articolo completo.') }}</p><p class="cite">{!! __('Gao YR, Wang RP, Zhang L, Fan Y, Luan J, Liu Z, Yuan C. Oral administration of hyaluronic acid to improve skin conditions via a randomized double-blind clinical test. <i>Skin Research and Technology</i>. 2023;29(11):e13531.') !!}<span class="links"><a href="https://pubmed.ncbi.nlm.nih.gov/38009035/">{{ __('Scheda su PubMed') }}</a><a href="https://doi.org/10.1111/srt.13531">{{ __('DOI 10.1111/srt.13531') }}</a></span></p></div></article><h3 style="margin:54px 0 6px">{{ __('Collagene, vitamina C e acido ialuronico insieme') }}</h3><p class="muted" style="margin-bottom:18px">{{ __('Uno studio che ha messo a confronto gli ingredienti combinati.') }}</p><article class="study" id="studio-6"><dl><div><dt>{{ __('Partecipanti') }}</dt><dd>{{ __('87 donne, 40–65 anni, che hanno completato lo studio') }}</dd></div><div><dt>{{ __('Dose') }}</dt><dd>{{ __('5 g di collagene idrolizzato con 80 mg di vitamina C, con o senza 30 mg di acido ialuronico') }}</dd></div><div><dt>{{ __('Durata') }}</dt><dd>{{ __('16 settimane') }}</dd></div><div><dt>{{ __('Disegno') }}</dt><dd>{{ __('Tre bracci, randomizzato, doppio cieco, placebo') }}</dd></div></dl><div><h4>{!! __('Collagene e vitamina C, da soli e con acido ialuronico <sup><a href="#r6">[6]</a></sup>') !!}</h4><p class="res">{{ __('Entrambi i prodotti hanno mostrato benefici su densità del derma, texture della pelle e gravità delle rughe.') }}</p><p class="lim">{{ __('Nessun effetto significativo su elasticità e idratazione. L’aggiunta di acido ialuronico al collagene non ha mostrato effetti superiori al solo collagene: lo studio non conferma un vantaggio aggiuntivo. Il collagene usato è diverso da VERISOL® e in dose diversa.') }}</p><p class="cite">{!! __('Žmitek K, Žmitek J, Hristov H, Rogl Butina M, Keršmanc P, Pogačnik T. The effects of dietary supplementation with collagen and vitamin C and their combination with hyaluronic acid on skin density, texture and other parameters: a randomised, double-blind, placebo-controlled trial. <i>Nutrients</i>. 2024;16(12):1908.') !!}<span class="links"><a href="https://pubmed.ncbi.nlm.nih.gov/38931263/">{{ __('Scheda su PubMed') }}</a><a href="https://doi.org/10.3390/nu16121908">{{ __('DOI 10.3390/nu16121908') }}</a></span></p></div></article>
  </div>
</section>

<section class="dark band" id="costanza" style="--ink:#13110E">
  <div class="wrap split" style="align-items:start">
    <div>
      <p class="kicker">{{ __('La costanza') }}</p>
      <h2>{{ __('Il tempo conta più di una singola dose.') }}</h2>
      <p class="lead muted" style="margin-top:20px">{{ __('In tutti gli studi i parametri sono valutati dopo settimane di assunzione regolare, non dopo un giorno. Da qui l’idea di Lamèss: non aggiungere un’altra capsula alla giornata, ma inserire l’integrazione in un rituale difficile da dimenticare, il caffè.') }}</p>
    </div>
    <div class="dur" style="background:var(--cream);padding:28px 26px;border-radius:6px;color:var(--ink)">
      <div class="scale"><span></span><div><span style="left:0">0</span><span style="left:25%">4</span><span style="left:50%">8</span><span style="left:75%">12</span><span style="left:100%">16</span></div></div>
      <div class="r"><span>{{ __('[1] Collagene, rughe') }}</span><div class="wb"><i style="width:50.00%"></i><i class="fu" style="left:50.00%;width:25.00%"></i></div></div><div class="r"><span>{{ __('[2] Collagene, elasticità') }}</span><div class="wb"><i style="width:50.00%"></i><i class="fu" style="left:50.00%;width:25.00%"></i></div></div><div class="r"><span>{{ __('[3] Acido ialuronico') }}</span><div class="wb"><i style="width:75.00%"></i></div></div><div class="r"><span>{{ __('[4] Acido ialuronico') }}</span><div class="wb"><i style="width:75.00%"></i></div></div><div class="r"><span>{{ __('[5] Acido ialuronico') }}</span><div class="wb"><i style="width:75.00%"></i></div></div><div class="r"><span>{{ __('[6] Collagene e vitamina C') }}</span><div class="wb"><i style="width:100.00%"></i></div></div>
      <p style="font-size:13px;color:var(--brown);max-width:none;margin-top:6px">{{ __('Scala in settimane. Barra scura: durata dell’assunzione. Barra chiara: osservazione dopo la sospensione.') }}</p>
    </div>
  </div>
</section>

<section class="white band" id="altri">
  <div class="wrap">
    <h2 style="margin-bottom:12px;max-width:20ch">{{ __('Gli altri ingredienti.') }}</h2>
    <p class="muted" style="margin-bottom:30px">{{ __('Le indicazioni sono quelle sulla salute autorizzate nell’Unione Europea (Regolamento UE n. 432/2012), con le relative condizioni d’uso: ogni indicazione si usa solo per un alimento che sia almeno fonte della vitamina o del minerale (almeno il 15% del valore di riferimento per porzione). Le dosi di Lamèss superano questa soglia per tutti i cinque nutrienti. Per collagene, acido ialuronico e coenzima Q10 non esistono indicazioni sulla salute autorizzate: di questi ingredienti riportiamo solo gli studi.') }}</p>
    <div class="claims">
      <div class="c"><div><b>{{ __('Vitamina C da rosa canina') }}</b><small>{{ __('80 mg, 100% VNR') }}</small></div><p>{{ __('Contribuisce alla normale formazione del collagene per la normale funzione della pelle.') }}</p></div>
      <div class="c"><div><b>{{ __('Vitamina E') }}</b><small>{{ __('30 mg, 250% VNR') }}</small></div><p>{{ __('Contribuisce alla protezione delle cellule dallo stress ossidativo.') }}</p></div>
      <div class="c"><div><b>{{ __('Beta-carotene, fonte di vitamina A') }}</b><small>{{ __('3,7 mg; vitamina A 624 mcg, 78% VNR') }}</small></div><p>{{ __('La vitamina A contribuisce al mantenimento di una pelle normale.') }}</p></div>
      <div class="c"><div><b>{{ __('Biotina') }}</b><small>{{ __('50 mcg, 100% VNR') }}</small></div><p>{{ __('Contribuisce al mantenimento di pelle e capelli normali.') }}</p></div>
      <div class="c"><div><b>{{ __('Selenio') }}</b><small>{{ __('50 mcg, 90,8% VNR') }}</small></div><p>{{ __('Contribuisce alla protezione delle cellule dallo stress ossidativo e al mantenimento di capelli e unghie normali.') }}</p></div>
    </div>
  </div>
</section>

<section class="light band">
  <div class="wrap split" style="align-items:start">
    <div>
      <p class="kicker">{{ __('Il caffè') }}</p>
      <h2>{{ __('Arabica 100%, classe tripla AAA.') }}</h2>
      <p style="margin-top:20px">{{ __('Un caffè istantaneo scelto per qualità, gusto e solubilità. Dà a Lamèss il gusto e l’aroma del caffè e permette di riunire in un solo rituale tutti gli ingredienti. Il caffè contiene naturalmente caffeina e composti bioattivi come i polifenoli, oggetto di un’ampia letteratura scientifica.') }}</p>
    </div>
    <div>
      <p class="kicker">{{ __('La sinergia') }}</p>
      <h2 style="font-size:clamp(1.6rem,3vw,2.3rem)">{{ __('Ingredienti diversi, un solo gesto.') }}</h2>
      <p style="margin-top:20px">{{ __('Non affermiamo che un ingrediente ne potenzi un altro: servirebbe uno studio clinico sulla formulazione completa. Lamèss riunisce nello stesso prodotto ingredienti con ruoli diversi, ognuno con le proprie dosi e le proprie evidenze. Lo studio [6] ha confrontato collagene e vitamina C con e senza acido ialuronico e non ha trovato un beneficio aggiuntivo dell’acido ialuronico.') }}</p>
    </div>
  </div>
</section>

<section class="white band" id="trasparenza">
  <div class="wrap">
    <h2 style="margin-bottom:24px">{{ __('Trasparenza scientifica.') }}</h2>
    <div class="note-box">
      <p>{{ __('Le pubblicazioni riportate riguardano singoli ingredienti o specifiche formulazioni, usati a determinati dosaggi, per determinate durate e su specifiche popolazioni. Non sono studi clinici sul prodotto finito Lamèss Cosmetic Coffee. Una corrispondenza di dose non implica che il prodotto finito dia gli stessi risultati osservati nello studio.') }}</p>
      <p style="margin-top:12px">{{ __('Lamèss è un integratore alimentare e non un medicinale. Non sostituisce una dieta varia ed equilibrata e uno stile di vita sano.') }}</p>
    </div>
    <h3 style="margin:40px 0 10px">{{ __('Formulazione e brevetto') }}</h3>
    <p>{{ __('Il brevetto tutela il patrimonio di ricerca e proprietà intellettuale alla base del progetto: per questo non vengono resi pubblici tutti i dettagli tecnici della formulazione.') }}</p>
  </div>
</section>

<section class="light band" id="fonti">
  <div class="wrap">
    <h2 style="margin-bottom:12px">{{ __('Fonti.') }}</h2>
    <p class="muted" style="margin-bottom:30px">{{ __('Ogni voce rimanda alla scheda su PubMed e al DOI della pubblicazione.') }}</p>
    <ol class="refs">
      <li id="r1">{!! __('Proksch E, Schunck M, Zague V, Segger D, Degwert J, Oesser S. Oral intake of specific bioactive collagen peptides reduces skin wrinkles and increases dermal matrix synthesis. <i>Skin Pharmacol Physiol</i>. 2014;27(3):113–119. <a href="https://pubmed.ncbi.nlm.nih.gov/24401291/">PubMed</a> · <a href="https://doi.org/10.1159/000355523">DOI</a>') !!}</li>
      <li id="r2">{!! __('Proksch E, Segger D, Degwert J, Schunck M, Zague V, Oesser S. Oral supplementation of specific collagen peptides has beneficial effects on human skin physiology: a double-blind, placebo-controlled study. <i>Skin Pharmacol Physiol</i>. 2014;27(1):47–55. <a href="https://pubmed.ncbi.nlm.nih.gov/23949208/">PubMed</a> · <a href="https://doi.org/10.1159/000351376">DOI</a>') !!}</li>
      <li id="r3">{!! __('Oe M, Sakai S, Yoshida H, Okado N, Kaneda H, Masuda Y, Urushibata O. Oral hyaluronan relieves wrinkles: a double-blinded, placebo-controlled study over a 12-week period. <i>Clin Cosmet Investig Dermatol</i>. 2017;10:267–273. <a href="https://pubmed.ncbi.nlm.nih.gov/28761365/">PubMed</a> · <a href="https://doi.org/10.2147/CCID.S141845">DOI</a>') !!}</li>
      <li id="r4">{!! __('Hsu TF, Su ZR, Hsieh YH, Wang MF, Oe M, Matsuoka R, Masuda Y. Oral hyaluronan relieves wrinkles and improves dry skin: a 12-week double-blinded, placebo-controlled study. <i>Nutrients</i>. 2021;13(7):2220. <a href="https://pubmed.ncbi.nlm.nih.gov/34203487/">PubMed</a> · <a href="https://doi.org/10.3390/nu13072220">DOI</a>') !!}</li>
      <li id="r5">{!! __('Gao YR, Wang RP, Zhang L, Fan Y, Luan J, Liu Z, Yuan C. Oral administration of hyaluronic acid to improve skin conditions via a randomized double-blind clinical test. <i>Skin Res Technol</i>. 2023;29(11):e13531. <a href="https://pubmed.ncbi.nlm.nih.gov/38009035/">PubMed</a> · <a href="https://doi.org/10.1111/srt.13531">DOI</a>') !!}</li>
      <li id="r6">{!! __('Žmitek K, Žmitek J, Hristov H, Rogl Butina M, Keršmanc P, Pogačnik T. The effects of dietary supplementation with collagen and vitamin C and their combination with hyaluronic acid on skin density, texture and other parameters: a randomised, double-blind, placebo-controlled trial. <i>Nutrients</i>. 2024;16(12):1908. <a href="https://pubmed.ncbi.nlm.nih.gov/38931263/">PubMed</a> · <a href="https://doi.org/10.3390/nu16121908">DOI</a>') !!}</li>
    </ol>
  </div>
</section>
@feature('site.front.faq')<section class="dark band" style="padding-block:clamp(56px,7vw,90px)"><div class="wrap cta-band"><h2>{{ __('Hai altre domande sugli ingredienti?') }}</h2><a class="btn solid" href="/domande">{{ __('Leggi le domande') }}</a></div></section>@endfeature
@endsection
