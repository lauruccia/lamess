<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
KADEO GROUP – Tutti i diritti riservati.<br>CONSORZIO KADEO GROUP - VIA G FERRARIS 63 - AREZZO - P.IVA 02530190517 ● PEC: kadeogroup@pec.it
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
