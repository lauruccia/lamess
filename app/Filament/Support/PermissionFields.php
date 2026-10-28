<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\AccessProfiles;
use App\Domain\Access\PermissionCatalog;
use App\Domain\Access\Roles;
use App\Domain\Plan\LegacyPlan;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Campi del modulo per scegliere i permessi di un admin o di un profilo: un elenco di caselle per ogni area, con la
 * spiegazione di ciò che ogni permesso sblocca, e un menu per partire da un profilo. Lo stato del modulo è
 * `perm.{area}` (area => permessi); `PermissionCatalog::flatten()` lo riporta all'elenco piatto.
 * Spuntare un permesso accende anche quelli che richiede («gestire» include «vedere»).
 */
final class PermissionFields
{
    /** Menu «Parti da un profilo»: copia i permessi del profilo scelto nelle caselle. */
    public static function startFrom(): Select
    {
        return Select::make('preset')->label('Parti da un profilo')->dehydrated(false)->native(false)
            ->options(fn (): array => AccessProfiles::options())->placeholder('Scegli un profilo per pre-compilare i permessi')
            ->helperText('Il profilo è solo un punto di partenza: i permessi si copiano e poi si possono ritoccare.')
            ->live()->afterStateUpdated(fn ($state, Set $set) => $set('perm', PermissionCatalog::splitByArea($state ? AccessProfiles::permissions((string) $state) : [])));
    }

    /** @return list<CheckboxList> una lista di caselle per ogni area dei permessi */
    public static function lists(): array
    {
        $fields = [];
        foreach (PermissionCatalog::areas() as $area => $meta) {
            $perms = PermissionCatalog::grantableIn($area);
            if ($perms === []) {
                continue;
            }
            $options = [];
            $descriptions = [];
            foreach ($perms as $p) {
                $options[$p] = LegacyPlan::scrub(Roles::PERMISSIONS[$p]).(PermissionCatalog::isSensitive($p) ? ' · sensibile' : '');
                $descriptions[$p] = LegacyPlan::scrub(PermissionCatalog::describe($p));
            }
            $fields[] = CheckboxList::make('perm.'.$area)->label($meta['label'])->helperText($meta['help'])
                ->options($options)->descriptions($descriptions)->columns(1)->bulkToggleable()->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => $set('perm', PermissionCatalog::splitByArea(PermissionCatalog::close(PermissionCatalog::flatten($get('perm'))))));
        }

        return $fields;
    }
}
