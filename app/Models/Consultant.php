<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Network\NetworkException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Consultant extends Model
{
    protected $guarded = [];

    /** Lo sponsor è immutabile (Reg. 2.10.a): si modifica solo tramite NetworkService (ricollegamento per cessazione). */
    public static bool $sponsorChangeAllowed = false;

    protected static function booted(): void
    {
        static::updating(static function (self $c): void {
            if ($c->isDirty('sponsor_id') && ! self::$sponsorChangeAllowed) {
                throw new NetworkException('Lo sponsor non può essere modificato (Reg. 2.10.a).');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kycDocuments(): HasMany
    {
        return $this->hasMany(KycDocument::class);
    }

    /** Ultima lettera IVD: precaricabile in lista per non fare una query per riga. */
    public function latestIvdContract(): HasOne
    {
        return $this->hasOne(IvdContract::class)->latestOfMany();
    }

    /** Data di ingresso usata dal motore: l'ingresso effettivo in rete se noto, altrimenti la firma della lettera. */
    public function membershipSince(): \DateTimeImmutable
    {
        return $this->network_since ?? $this->since;
    }

    protected function casts(): array
    {
        return ['since' => 'immutable_datetime', 'network_since' => 'immutable_datetime', 'terminated_at' => 'immutable_datetime', 'onboarding_complete' => 'boolean', 'bonus_hold' => 'boolean', 'onboarding_completed_at' => 'immutable_datetime', 'badge_valid_until' => 'immutable_date', 'rank_hold_until' => 'immutable_date'];
    }

    /** Il codice referral c'è sempre: se manca (account importati o creati dal pannello) lo si genera al primo uso. */
    public function ensureReferralCode(): string
    {
        if (blank($this->referral_code)) {
            do {
                $code = strtoupper(\Illuminate\Support\Str::random(8));
            } while (static::query()->where('referral_code', $code)->exists());
            $this->forceFill(['referral_code' => $code])->save();
        }

        return (string) $this->referral_code;
    }
}
