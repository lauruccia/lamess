<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Evento, corso o webinar con iscrizioni e lista d'attesa. */
class CorporateEvent extends Model
{
    protected $guarded = [];

    /** @var array<string, string> */
    public const KINDS = ['event' => 'Evento', 'course' => 'Corso', 'webinar' => 'Webinar'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'registration_deadline' => 'immutable_datetime', 'capacity' => 'integer'];
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function registeredCount(): int
    {
        return $this->registrations()->where('status', 'registered')->count();
    }
}
