<?php

declare(strict_types=1);

namespace App\Filament\Pages\Base;

use App\Domain\Access\Superadmin;
use App\Domain\Audit\AuditLog;
use App\Filament\Support\GenericForm;
use App\Filament\Support\SuperadminActions;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Pagina di modifica diretta, riservata al super_admin, per le schermate che non ne hanno una propria.
 * Il modulo nasce dalla struttura della tabella (GenericForm); ogni salvataggio scrive nel log di audit
 * i valori prima e dopo.
 */
abstract class SuperadminEditRecord extends EditRecord
{
    /** @var list<string> colonne da non mostrare nel modulo (es. relazioni che si cambiano solo con gli strumenti dedicati) */
    protected static array $excludeColumns = [];

    public static function canAccess(array $parameters = []): bool
    {
        return Superadmin::is();
    }

    public function form(Schema $schema): Schema
    {
        $model = static::getResource()::getModel();

        return $schema->components(GenericForm::fields(new $model, static::$excludeColumns))->columns(2);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->forceFill($data);
        $changes = [];
        foreach ($record->getDirty() as $field => $new) {
            $changes[$field] = ['prima' => $record->getOriginal($field), 'dopo' => $new];
        }
        $record->save();
        if ($changes !== []) {
            AuditLog::record('superadmin.updated', 'Modifica diretta (super_admin): '.class_basename($record).' #'.$record->getKey(), $record, $changes);
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [SuperadminActions::delete(static::getResource())->record($this->getRecord())->successRedirectUrl(static::getResource()::getUrl('index'))];
    }
}
