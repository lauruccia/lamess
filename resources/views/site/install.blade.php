@extends('site.layout')
@section('title','Installazione — Lamèss')
@section('content')
@if($done)
<h1>Installazione completata</h1>
<div class="card">
<p>Il super_admin <strong>{{ $done['email'] }}</strong> è stato creato.</p>
<p><strong>Ora, subito:</strong></p>
<ol>
<li>Dal file manager apri il file <code>.env</code> e <strong>cancella la riga <code>LAMESS_INSTALL_TOKEN</code></strong>: questa pagina smette di esistere.</li>
<li>In cPanel → <em>Operazioni pianificate (Cron Jobs)</em> aggiungi questa riga (ogni minuto):<br><code style="word-break:break-all">{{ $done['cron'] }}</code><br><span class="muted">Se il percorso di PHP è diverso, cPanel lo indica nella stessa pagina.</span></li>
<li>Entra nel pannello: <a href="/admin">/admin</a>. Al primo accesso ti verrà chiesto di collegare l'app di autenticazione (2FA).</li>
</ol>
</div>
@else
<h1>Installazione guidata</h1>
<p class="muted">Si fa una volta sola. Serve il token scritto nel file <code>.env</code> (LAMESS_INSTALL_TOKEN).</p>
<div class="card"><h2>Controlli del server</h2>
<ul>@foreach($checks as $c)<li>{{ $c['ok'] ? '✔' : '✘' }} {{ $c['label'] }} — <span class="muted">{{ $c['detail'] }}</span></li>@endforeach</ul></div>
<form method="post" action="/installazione" class="card">@csrf
<label>Token di installazione</label><input name="token" type="password" autocomplete="off" required>
<label>Nome del super_admin</label><input name="name" value="{{ old('name') }}" required>
<label>Email del super_admin</label><input name="email" type="email" value="{{ old('email') }}" required>
<label>Password (almeno 12 caratteri, con lettere e numeri)</label><input name="password" type="password" autocomplete="new-password" required>
<label>Ripeti la password</label><input name="password_confirmation" type="password" autocomplete="new-password" required>
@foreach($errors->all() as $e)<p class="err">{{ $e }}</p>@endforeach
<button>Installa</button></form>
@endif
@endsection
