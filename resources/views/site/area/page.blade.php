@extends('site.layout')
@section('title','La mia pagina — Lamèss')
@section('width','area')
@section('content')
@php
 $pending = $page->review === 'pending';
 $rejected = $page->review === 'rejected';
 $photo = $d['photo_path'] ? '/media/pagine/'.$page->id.'/'.basename((string) $d['photo_path']) : null;
 $media = array_pad(old('media', $d['media'] ?? []), \App\Domain\Pages\PersonalPageService::MEDIA_SLOTS, []);
@endphp
<div class="pg-head">
 <div>
  <h1>La mia pagina</h1>
  <p class="sub">La tua pagina personale Lamèss, da condividere con chi vuoi presentare il prodotto. Chi si registra da lì entra nella tua struttura, come dal tuo link referral.</p>
 </div>
 <div class="pg-actions"><a class="btn ghost" href="/area/pagina/anteprima" target="_blank" rel="noopener">Anteprima</a>@if($public)<a class="btn accent" href="{{ $url }}" target="_blank" rel="noopener">Apri la pagina</a>@endif</div>
</div>

@foreach($errors->all() as $m)<div class="note note-err"><div>{{ $m }}</div></div>@endforeach
@if(session('status'))<div class="note note-ok"><div>{{ session('status') }}</div></div>@endif
@if($page->blocked)<div class="note note-err"><div><strong>Pagina nascosta dal Consorzio</strong>{{ $page->blocked_note ?: 'Contatta l\'assistenza per riattivarla.' }}</div></div>@endif
@if($rejected)<div class="note note-err"><div><strong>Modifiche non approvate</strong>{{ $page->review_note }} Correggi i testi e salva di nuovo.</div></div>@endif
@if($pending)<div class="note"><div><strong>Modifiche in revisione</strong>Il Consorzio le sta controllando. Fino all'approvazione il pubblico vede {{ $page->approved_at ? 'la versione precedente' : 'nulla: la pagina non è ancora online' }}.</div></div>@endif

<div class="split">
 <form method="post" action="/area/pagina" enctype="multipart/form-data" class="stack">@csrf
  <section class="panel">
   <header><h2>Chi sei</h2></header>
   <div class="panel-body"><div class="form-grid">
    <div class="full"><label for="pg-name">Nome da mostrare</label><input id="pg-name" name="display_name" required maxlength="120" value="{{ old('display_name', $d['display_name'] ?: $defaultName) }}"></div>
    <div class="full"><label for="pg-head">Qualifica o sottotitolo</label><input id="pg-head" name="headline" maxlength="160" value="{{ old('headline', $d['headline']) }}" placeholder="Incaricato Lamèss"></div>
    <div class="full"><label for="pg-bio">Presentazione breve</label><textarea id="pg-bio" name="bio" rows="4" maxlength="900" placeholder="Due o tre righe su di te e su come puoi aiutare chi arriva sulla pagina.">{{ old('bio', $d['bio']) }}</textarea></div>
    <div class="full"><label for="pg-photo">Foto</label>
     @if($photo)<div style="display:flex;align-items:center;gap:14px;margin-bottom:8px"><img src="{{ $photo }}" alt="" width="72" height="90" style="object-fit:cover;border-radius:10px"><label style="display:flex;gap:8px;align-items:center;font-weight:400"><input type="checkbox" name="remove_photo" value="1" style="width:auto"> Togli la foto</label></div>@endif
     <input id="pg-photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp"><small class="muted">JPG, PNG o WebP, massimo 4 MB. Meglio una foto verticale, con il viso al centro.</small></div>
    <div class="full"><label for="pg-story">La mia storia con Lamèss <small class="muted">(facoltativo)</small></label><textarea id="pg-story" name="story" rows="6" maxlength="3000" placeholder="Perché hai scelto Lamèss e cosa racconti a chi ti chiede informazioni.">{{ old('story', $d['story']) }}</textarea></div>
   </div></div>
  </section>

  <section class="panel">
   <header><h2>Contatti e social</h2></header>
   <div class="panel-body"><div class="form-grid">
    <div><label for="pg-wa">WhatsApp</label><input id="pg-wa" name="whatsapp" type="tel" maxlength="30" value="{{ old('whatsapp', $d['whatsapp']) }}" placeholder="+39 333 1234567"></div>
    <div><label for="pg-mail">Email pubblica</label><input id="pg-mail" name="email_public" type="email" maxlength="190" value="{{ old('email_public', $d['email_public']) }}"></div>
    @foreach(\App\Domain\Pages\PersonalPageService::SOCIALS as $k => $label)
     <div><label for="pg-{{ $k }}">{{ $label }}</label><input id="pg-{{ $k }}" name="{{ $k }}" type="url" maxlength="300" value="{{ old($k, $d[$k]) }}" placeholder="https://"></div>
    @endforeach
   </div>
   <p class="muted" style="font-size:13.5px;margin:12px 0 0">Mostra solo i recapiti che vuoi rendere pubblici: quelli lasciati vuoti non compaiono.</p></div>
  </section>

  <section class="panel">
   <header><h2>Video e articoli consigliati</h2></header>
   <div class="panel-body">
    <p class="muted" style="margin-top:0;font-size:14px">Fino a {{ \App\Domain\Pages\PersonalPageService::MEDIA_SLOTS }} contenuti (un video, un articolo…). Per ognuno servono titolo e link.</p>
    @foreach($media as $i => $m)
     <fieldset style="border:1px solid var(--hair);border-radius:12px;padding:12px 14px;margin:0 0 12px"><legend style="padding:0 6px;font-size:13.5px">Contenuto {{ $i + 1 }}</legend>
      <div class="form-grid">
       <div><label>Tipo</label><select name="media[{{ $i }}][kind]">@foreach(\App\Domain\Pages\PersonalPageService::MEDIA_KINDS as $k => $l)<option value="{{ $k }}" @selected(($m['kind'] ?? 'article') === $k)>{{ $l }}</option>@endforeach</select></div>
       <div><label>Titolo</label><input name="media[{{ $i }}][title]" maxlength="120" value="{{ $m['title'] ?? '' }}"></div>
       <div class="full"><label>Link</label><input name="media[{{ $i }}][url]" type="url" maxlength="300" value="{{ $m['url'] ?? '' }}" placeholder="https://"></div>
       <div class="full"><label>Breve descrizione <small class="muted">(facoltativa)</small></label><input name="media[{{ $i }}][description]" maxlength="300" value="{{ $m['description'] ?? '' }}"></div>
      </div>
     </fieldset>
    @endforeach
   </div>
  </section>

  <section class="panel">
   <header><h2>Indirizzo della pagina</h2></header>
   <div class="panel-body">
    <label for="pg-slug">Indirizzo</label>
    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap"><span class="muted">{{ rtrim(preg_replace('#^https?://#', '', url('/i')), '/') }}/</span><input id="pg-slug" name="slug" required maxlength="40" value="{{ old('slug', $page->slug) }}" style="flex:1;min-width:180px" autocapitalize="none"></div>
    <small class="muted">Lettere minuscole, numeri e trattini. Se lo cambi, il vecchio indirizzo smette di funzionare: aggiorna i link che hai già condiviso.</small>
   </div>
  </section>

  <div class="form-actions"><button>{{ $approval ? 'Salva e invia al Consorzio' : 'Salva le modifiche' }}</button></div>
 </form>

 <aside class="stack">
  <section class="panel accent">
   <header><h2>Stato</h2>
    @if($page->blocked)<span class="st st-err">Nascosta</span>
    @elseif($public)<span class="st st-ok">Online</span>
    @elseif($page->enabled && $pending)<span class="st st-warn">In attesa di approvazione</span>
    @elseif($page->enabled)<span class="st st-warn">Online appena approvata</span>
    @else<span class="st">Non online</span>@endif
   </header>
   <div class="panel-body">
    <div class="copy"><input type="text" readonly value="{{ $url }}" id="pg-url" aria-label="Indirizzo della pagina"><button type="button" class="btn" data-copy="{{ $url }}">Copia</button></div>
    <p class="muted" style="font-size:13.5px">{{ $approval ? 'Ogni modifica viene controllata dal Consorzio prima di andare online.' : 'Le modifiche vanno online subito.' }} Visite finora: <strong>{{ $page->views }}</strong>.</p>
    @unless($page->blocked)
     @if($page->enabled)
      <form method="post" action="/area/pagina/online" onsubmit="return confirm('Togliere la pagina dal pubblico? Chi ha il link non la vedrà più.')">@csrf<input type="hidden" name="online" value="0"><button class="btn ghost">Togli dal pubblico</button></form>
     @else
      <form method="post" action="/area/pagina/online">@csrf<input type="hidden" name="online" value="1"><button class="btn accent">Metti online la pagina</button></form>
     @endif
    @endunless
   </div>
  </section>
  <section class="panel">
   <header><h2>Cosa è tuo e cosa no</h2></header>
   <div class="panel-body"><ul style="margin:0;padding-left:18px;line-height:1.7;font-size:14.5px">
    <li>Tuoi: nome, foto, presentazione, storia, contatti, social, contenuti consigliati.</li>
    <li>Del Consorzio: descrizione del prodotto, domande frequenti e avvertenze, uguali per tutti.</li>
    <li>Non scrivere promesse di risultato né indicazioni di tipo sanitario: il prodotto non è un medicinale.</li>
   </ul></div>
  </section>
 </aside>
</div>
<script>
document.querySelectorAll('[data-copy]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var label = btn.textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(btn.getAttribute('data-copy')) : Promise.reject()).then(function () { btn.textContent = 'Copiato'; }, function () { var i = document.getElementById('pg-url'); if (i) { i.select(); } btn.textContent = 'Seleziona e copia'; });
    setTimeout(function () { btn.textContent = label; }, 1800);
  });
});
</script>
@endsection
