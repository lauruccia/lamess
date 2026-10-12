Ciao {{ $name }},

in allegato trovi i documenti che ti abbiamo preparato:
@foreach($labels as $l)
- {{ $l }}
@endforeach
@if($note)

{{ $note }}
@endif

Puoi ritrovare i tuoi documenti anche nella tua area riservata: {{ url('/area') }}
