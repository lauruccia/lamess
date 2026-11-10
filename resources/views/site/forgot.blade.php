@extends('site.layout')
@section('title', __('Password dimenticata — Lamèss'))
@section('width','auth')
@section('content')
<h1>{{ __('Password dimenticata') }}</h1>
<p class="muted">{{ __('Scrivi l\'email del tuo account: ti mandiamo un link per sceglierne una nuova.') }}</p>
<form method="post" action="/password-dimenticata" class="card">@csrf
<label>{{ __('Email') }}</label><input name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
@foreach($errors->all() as $e)<p class="err">{{ $e }}</p>@endforeach
<button>{{ __('Invia il link') }}</button></form>
<p class="muted"><a href="/accedi">{{ __('Torna all\'accesso') }}</a></p>
@endsection
