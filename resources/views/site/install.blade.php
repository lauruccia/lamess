@extends('site.layout')
@section('title', __('Installazione — Lamèss'))
@section('content')
@if($done)
<h1>{{ __('Installazione completata') }}</h1>
<div class="card">
<p>{!! __('Il super_admin <strong>:p</strong> è stato creato.', ['p' => e($done['email'])]) !!}</p>
<p><strong>{{ __('Ora, subito:') }}</strong></p>
<ol>
<li>{!! __('Dal file manager apri il file <code>.env</code> e <strong>cancella la riga <code>LAMESS_INSTALL_TOKEN</code></strong>: questa pagina smette di esistere.') !!}</li>
<li>{!! __('In cPanel → <em>Operazioni pianificate (Cron Jobs)</em> aggiungi questa riga (ogni minuto):') !!}<br><code style="word-break:break-all">{{ $done['cron'] }}</code><br><span class="muted">{{ __('Se il percorso di PHP è diverso, cPanel lo indica nella stessa pagina.') }}</span></li>
<li>{!! __('Entra nel pannello: <a href="/admin">/admin</a>. Al primo accesso ti verrà chiesto di collegare l\'app di autenticazione (2FA).') !!}</li>
</ol>
</div>
@else
<h1>{{ __('Installazione guidata') }}</h1>
<p class="muted">{!! __('Si fa una volta sola. Serve il token scritto nel file <code>.env</code> (LAMESS_INSTALL_TOKEN).') !!}</p>
<div class="card"><h2>{{ __('Controlli del server') }}</h2>
<ul>@foreach($checks as $c)<li>{{ $c['ok'] ? '✔' : '✘' }} {{ $c['label'] }} — <span class="muted">{{ $c['detail'] }}</span></li>@endforeach</ul></div>
<form method="post" action="/installazione" class="card">@csrf
<label>{{ __('Token di installazione') }}</label><input name="token" type="password" autocomplete="off" required>
<label>{{ __('Nome del super_admin') }}</label><input name="name" value="{{ old('name') }}" required>
<label>{{ __('Email del super_admin') }}</label><input name="email" type="email" value="{{ old('email') }}" required>
<label>{{ __('Password (almeno 12 caratteri, con lettere e numeri)') }}</label><input name="password" type="password" autocomplete="new-password" required>
<label>{{ __('Ripeti la password') }}</label><input name="password_confirmation" type="password" autocomplete="new-password" required>
@foreach($errors->all() as $e)<p class="err">{{ $e }}</p>@endforeach
<button>{{ __('Installa') }}</button></form>
@endif
@endsection
