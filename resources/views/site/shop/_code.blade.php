<div class="codebox">
 @foreach($codes as $c)
  <form method="post" action="/carrello/codice/rimuovi" class="chip-code">@csrf<input type="hidden" name="code" value="{{ $c }}"><span>🏷 {{ $c }}</span><button class="linkbtn" aria-label="Rimuovi il codice {{ $c }}">✕</button></form>
 @endforeach
 <form method="post" action="/carrello/codice" class="code-form">@csrf
  <label for="code-{{ $uid ?? 'x' }}" class="sr">Codice sconto</label>
  <input id="code-{{ $uid ?? 'x' }}" name="code" value="{{ old('code') }}" placeholder="Codice sconto" autocomplete="off" autocapitalize="characters" maxlength="40">
  <button class="ghostbtn">Applica</button>
 </form>
 @error('code')<p class="err">{{ $message }}</p>@enderror
</div>
