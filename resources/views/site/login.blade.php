@extends('site.layout')
@section('title', __('Accedi — Lamèss'))
@section('width','auth')
@section('side_title','Bentornata, bentornato')
@section('side_text','Accedi per vedere ordini, abbonamenti e, se sei incaricato, la tua rete.')
@section('content')
<h1>{{ __('Accedi') }}</h1>
<form method="post" action="/accedi" class="card">@csrf
<label>{{ __('Email') }}</label><input name="email" type="email" value="{{ old('email') }}" autocomplete="email">
<label>{{ __('Password') }}</label><input name="password" type="password" autocomplete="current-password">
<label class="chk"><input type="checkbox" name="remember" value="1"> {{ __('Ricordami') }}</label>
@foreach($errors->all() as $e)<p class="err">{{ $e }}</p>@endforeach
<button>{{ __('Accedi') }}</button></form>
<p class="muted"><a href="/password-dimenticata">{{ __('Password dimenticata?') }}</a></p>
@if(\App\Domain\Customers\RegistrationGate::linksVisible())<p class="muted">{!! __('Non hai un account? <a href="/registrazione">Registrati</a>') !!}</p>@else<p class="muted">{!! __('La registrazione è riservata a chi riceve un invito da un incaricato Lamèss. <a href="/candidati">Candidati</a>') !!}</p>@endif
@endsection
