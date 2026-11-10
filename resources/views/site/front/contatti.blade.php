@extends('site.front.layout')
@section('title', __('Contatti Lamèss'))
@section('description', __('Contatta Lamèss: numero verde, email e sede del Consorzio Kadeo Group.'))
@section('content')
<section class="dark pagehead"><div class="wrap"><p class="crumbs"><a href="/">{{ __('Home') }}</a> {{ __('/ Contatti') }}</p><h1>{{ __('Contatti.') }}</h1><p>{{ __('Scrivici per informazioni, per un Lamèss Point o per diventare incaricato.') }}</p></div></section>
<section class="light band">
  <div class="wrap formgrid">
    <div><h2 style="font-size:2rem">{{ __('Scrivici.') }}</h2><p style="margin:14px 0 22px">{{ __('Scegli l’argomento: si apre il tuo programma di posta con l’oggetto già pronto, indirizzato a info@lamess.it.') }}</p><ul class="mailopts"><li><a class="btn" href="mailto:info@lamess.it?subject=Informazioni%20sul%20prodotto">{{ __('Informazioni sul prodotto') }}</a></li><li><a class="btn" href="mailto:info@lamess.it?subject=Richiesta%20Lam%C3%A8ss%20Point">{{ __('Apri un Lamèss Point') }}</a></li><li><a class="btn" href="mailto:info@lamess.it?subject=Diventa%20incaricato">{{ __('Diventa incaricato') }}</a></li><li><a class="btn" href="mailto:info@lamess.it?subject=Socio%20del%20Consorzio">{{ __('Socio del Consorzio') }}</a></li><li><a class="btn" href="mailto:info@lamess.it?subject=Assistenza%20su%20un%20ordine">{{ __('Assistenza su un ordine') }}</a></li></ul></div>
    <div>
      <h2 style="font-size:2rem">{{ __('Parla con noi.') }}</h2>
      <dl class="contacts">
        <dt>{{ __('Numero verde') }}</dt><dd>800 911 377</dd>
        <dt>{{ __('Email') }}</dt><dd>{{ __('info@lamess.it') }}</dd>
        <dt>{{ __('Sede') }}</dt><dd>{!! __('Consorzio Kadeo Group<br>Via Galileo Ferraris 63, 52100 Arezzo') !!}</dd>
        <dt>{{ __('PEC') }}</dt><dd>{{ __('kadeogroup@pec.it') }}</dd>
      </dl>
    </div>
  </div>
</section>
@endsection
