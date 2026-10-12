@extends('site.layout')
@section('title','Accedi — Lamèss')
@section('width','auth')
@section('side_title','Bentornata, bentornato')
@section('side_text','Accedi per vedere ordini, abbonamenti e, se sei incaricato, la tua rete.')
@section('content')
<h1>Accedi</h1>
<form method="post" action="/accedi" class="card">@csrf
<label>Email</label><input name="email" type="email" value="{{ old('email') }}" autocomplete="email">
<label>Password</label><input name="password" type="password" autocomplete="current-password">
<label class="chk"><input type="checkbox" name="remember" value="1"> Ricordami</label>
@foreach($errors->all() as $e)<p class="err">{{ $e }}</p>@endforeach
<button>Accedi</button></form>
<p class="muted"><a href="/password-dimenticata">Password dimenticata?</a></p>
<p class="muted">Non hai un account? <a href="/registrazione">Registrati</a></p>
@endsection
