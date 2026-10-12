<div>
 @forelse($rows as $r)
  <p>{{ $r->user?->name }} · {{ $r->user?->email }} — {{ $r->status === 'waitlist' ? 'in attesa' : 'iscritto' }}</p>
 @empty
  <p>Nessun iscritto.</p>
 @endforelse
</div>
