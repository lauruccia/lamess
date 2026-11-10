@php
    $__langs = \App\Domain\I18n\Locales::active();
    $__cur = \App\Domain\I18n\Locales::current();
    $__path = '/'.ltrim(request()->path(), '/');
    $__public = \App\Domain\I18n\Locales::isPublicPath($__path);
    $__qs = request()->getQueryString();
    $__curLang = \App\Domain\I18n\Locales::find($__cur) ?? ['code' => $__cur, 'native_name' => strtoupper($__cur), 'flag' => null];
    $__flag = fn (?string $f) => $f && is_file(public_path('img/flags/'.$f.'.svg')) ? '<img src="/img/flags/'.e($f).'.svg" alt="" width="24" height="16" class="lang-flag">' : '<span class="lang-flag lang-flag-code">'.e(strtoupper(substr((string) $f, 0, 2))).'</span>';
@endphp
@if(count($__langs) > 1 && ! request()->attributes->get('i18n.forced'))
<div class="lang-sw">
 <details>
  <summary aria-label="{{ __('Lingua del sito') }}: {{ $__curLang['native_name'] }}">{!! $__flag($__curLang['flag']) !!}<span class="lang-code">{{ strtoupper($__curLang['code']) }}</span><svg class="lang-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
  <ul>
   @foreach($__langs as $__l)
    @php($__href = $__public ? '/'.$__l['code'].($__path === '/' ? '' : $__path).($__qs ? '?'.$__qs : '') : '/lingua/'.$__l['code'].'?to='.urlencode($__path.($__qs ? '?'.$__qs : '')))
    <li><a href="{{ $__href }}" lang="{{ $__l['code'] }}" hreflang="{{ $__l['code'] }}"@if($__l['code'] === $__cur) aria-current="true"@endif>{!! $__flag($__l['flag']) !!}<span>{{ $__l['native_name'] }}</span></a></li>
   @endforeach
  </ul>
 </details>
</div>
@endif
