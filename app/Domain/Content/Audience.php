<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Models\User;

/** A chi è rivolto un contenuto: tutti, solo incaricati attivi, solo clienti/FAN. */
final class Audience
{
    public static function isActiveConsultant(User $user): bool
    {
        $c = $user->consultant;

        return $c !== null && $c->status === 'active';
    }

    public static function allows(string $audience, User $user): bool
    {
        return match ($audience) {
            'consultants' => self::isActiveConsultant($user),
            'fans' => $user->consultant === null,
            default => true,
        };
    }

    /** @return list<string> pubblici che l'utente può vedere */
    public static function visibleTo(User $user): array
    {
        return array_values(array_filter(['all', 'consultants', 'fans'], fn (string $a) => self::allows($a, $user)));
    }
}
