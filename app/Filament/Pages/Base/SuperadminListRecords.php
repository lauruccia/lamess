<?php

declare(strict_types=1);

namespace App\Filament\Pages\Base;

use App\Filament\Support\SuperadminActions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

/**
 * Elenco di una risorsa con in più, solo per il super_admin, «Modifica» ed «Elimina» su ogni riga e l'eliminazione
 * multipla. Le azioni si aggiungono dopo quelle della risorsa (che restano com'erano).
 */
abstract class SuperadminListRecords extends ListRecords
{
    protected function makeTable(): Table
    {
        return SuperadminActions::decorate(parent::makeTable(), static::getResource());
    }
}
