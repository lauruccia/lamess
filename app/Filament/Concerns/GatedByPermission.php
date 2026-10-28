<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Access\Superadmin;
use App\Domain\Features\Feature;
use App\Filament\Pages\Base\SuperadminEditRecord;

/**
 * Autorizzazione delle risorse del back-office tramite permessi (App\Domain\Access\Roles).
 * La risorsa dichiara il permesso di lettura e quello di gestione; il super_admin passa sempre.
 * Chi può gestire può anche vedere: «gestire» include «vedere» (PermissionCatalog), anche per chi ha ricevuto
 * solo il permesso di gestione prima che esistessero i permessi di sola lettura.
 */
trait GatedByPermission
{
    abstract protected static function viewPermission(): string;

    abstract protected static function managePermission(): string;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can(static::viewPermission()) || $user?->can(static::managePermission()))
            && Feature::enabled('backoffice.resource.'.str_replace('-', '_', static::getSlug()));
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->can(static::managePermission());
    }

    public static function canEdit($record): bool
    {
        // la pagina di modifica diretta (SuperadminEditRecord) è solo del super_admin
        if (static::editIsSuperadminOnly()) {
            return Superadmin::is();
        }

        return (bool) auth()->user()?->can(static::managePermission());
    }

    protected static function editIsSuperadminOnly(): bool
    {
        $page = static::getPages()['edit'] ?? null;

        return $page !== null && is_subclass_of($page->getPage(), SuperadminEditRecord::class);
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->can(static::managePermission());
    }
}
