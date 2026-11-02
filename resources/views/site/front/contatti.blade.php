@extends('site.front.layout')
@section('title', 'Contatti Lamèss')
@section('description', 'Contatta Lamèss: numero verde, email e sede del Consorzio Kadeo Group.')
@section('content')
@verbatim
<section class="dark pagehead"><div class="wrap"><p class="crumbs"><a href="/">Home</a> / Contatti</p><h1>Contatti.</h1><p>Scrivici per informazioni, per un Lamèss Point o per diventare incaricato.</p></div></section>
<section class="light band">
  <div class="wrap formgrid">
    <div><h2 style="font-size:2rem">Scrivici.</h2><p style="margin:14px 0 22px">Scegli l’argomento: si apre il tuo programma di posta con l’oggetto già pronto, indirizzato a info@lamess.it.</p><ul class="mailopts"><li><a class="btn" href="mailto:info@lamess.it?subject=Informazioni%20sul%20prodotto">Informazioni sul prodotto</a></li><li><a class="btn" href="mailto:info@lamess.it?subject=Richiesta%20Lam%C3%A8ss%20Point">Apri un Lamèss Point</a></li><li><a class="btn" href="mailto:info@lamess.it?subject=Diventa%20incaricato">Diventa incaricato</a></li><li><a class="btn" href="mailto:info@lamess.it?subject=Socio%20del%20Consorzio">Socio del Consorzio</a></li><li><a class="btn" href="mailto:info@lamess.it?subject=Assistenza%20su%20un%20ordine">Assistenza su un ordine</a></li></ul></div>
    <div>
      <h2 style="font-size:2rem">Parla con noi.</h2>
      <dl class="contacts">
        <dt>Numero verde</dt><dd>800 911 377</dd>
        <dt>Email</dt><dd>info@lamess.it</dd>
        <dt>Sede</dt><dd>Consorzio Kadeo Group<br>Via Galileo Ferraris 63, 52100 Arezzo</dd>
        <dt>PEC</dt><dd>kadeogroup@pec.it</dd>
      </dl>
    </div>
  </div>
</section>
@endverbatim
@endsection
