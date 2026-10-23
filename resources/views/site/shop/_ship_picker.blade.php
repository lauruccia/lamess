{{-- Scelta dell'indirizzo di spedizione. Variabili: $addresses (rubrica), $max, $selected (facoltativa) --}}
@php
    $sel = (string) old('ship_to', $selected ?? ($addresses->firstWhere('is_default', true)?->id ?? 'billing'));
    $full = $addresses->count() >= $max;
@endphp
<div class="ship-picker">
 <label for="ship_to">Dove spediamo</label>
 <select name="ship_to" id="ship_to" data-country-reload onchange="this.parentNode.querySelector('[data-ship-new]').hidden = this.value !== 'new'">
  <option value="billing" @selected($sel === 'billing')>Lo stesso indirizzo di fatturazione</option>
  @foreach($addresses as $a)<option value="{{ $a->id }}" data-country="{{ $a->country_code ?: 'IT' }}" @selected($sel === (string) $a->id)>{{ $a->oneLine() }}{{ $a->is_default ? ' · predefinito' : '' }}</option>@endforeach
  <option value="new" @selected($sel === 'new')>Un nuovo indirizzo…</option>
 </select>
 <div data-ship-new @if($sel !== 'new') hidden @endif style="margin-top:10px">
  <div class="row"><div><label>Nome dell'indirizzo (es. Casa, Mare)</label><input name="ship_label" maxlength="60" value="{{ old('ship_label') }}"></div><div><label>Destinatario (se diverso da te)</label><input name="ship_recipient" maxlength="190" value="{{ old('ship_recipient') }}"></div></div>
  <label>Indirizzo <b class="req" title="Obbligatorio">*</b></label><input name="ship_address" value="{{ old('ship_address') }}" autocomplete="off">
  <div class="row"><div><label>Città <b class="req" title="Obbligatorio">*</b></label><input name="ship_city" value="{{ old('ship_city') }}"></div><div><label>CAP <b class="req" title="Obbligatorio">*</b></label><input name="ship_zip" inputmode="numeric" value="{{ old('ship_zip') }}"></div></div>
  <div class="row"><div><label>Provincia</label><input name="ship_province" maxlength="3" value="{{ old('ship_province') }}"></div><div><label>Nazione</label><select name="ship_country" data-country-reload>@foreach(($countries ?? ['IT' => 'Italia']) as $code => $name)<option value="{{ $code }}" @selected(old('ship_country', $country ?? 'IT') === $code)>{{ $name }}</option>@endforeach</select></div></div>
  <div class="row"><div><label>Telefono per il corriere</label><input name="ship_phone" maxlength="30" value="{{ old('ship_phone') }}"></div></div>
  @if($full)
   <p class="muted" style="font-size:13px">Hai già {{ $max }} indirizzi salvati: questo vale solo per questo ordine. Per salvarne uno nuovo elimina un indirizzo da «I miei dati».</p>
  @else
   <label class="chk"><input type="checkbox" name="ship_save" value="1" @checked(old('ship_save', '1') == '1')> Salva questo indirizzo ({{ $addresses->count() }} di {{ $max }})</label>
  @endif
 </div>
</div>
