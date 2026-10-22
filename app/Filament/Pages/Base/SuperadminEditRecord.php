<?php

declare(strict_types=1);

namespace App\Filament\Pages\Base;

use App\Domain\Access\Superadmin;
use App\Domain\Audit\AuditLog;
use App\Filament\Support\GenericForm;
use App\Filament\Support\GenericFormVocabulary;
use App\Filament\Support\SuperadminActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

/**
 * Pagina di modifica diretta, riservata al super_admin, per le schermate che non ne hanno una propria.
 * Il modulo nasce dalla struttura della tabella (GenericForm); ogni salvataggio scrive nel log di audit
 * i valori prima e dopo.
 */
abstract class SuperadminEditRecord extends EditRecord
{
    public static bool $formActionsAreSticky = true;

    /** @var list<string> colonne da non mostrare nel modulo (es. relazioni che si cambiano solo con gli strumenti dedicati) */
    protected static array $excludeColumns = [];

    public static function canAccess(array $parameters = []): bool
    {
        return Superadmin::is();
    }

    public function form(Schema $schema): Schema
    {
        $model = static::getResource()::getModel();
        $record = $this->record instanceof Model ? $this->record : new $model;

        $sections = GenericForm::sections(GenericForm::fields($record, static::$excludeColumns));

        return $schema->components([View::make('partials.generic-edit-hero')->viewData(fn (): array => $this->heroData())->columnSpanFull(), ...$sections]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Modifica '.mb_strtolower(static::getResource()::getModelLabel());
    }

    /**
     * Intestazione riassuntiva: nome o numero del record, quando è stato creato, stato e tipo in chiaro.
     *
     * @return array<string, mixed>
     */
    protected function heroData(): array
    {
        /** @var Model $record */
        $record = $this->getRecord();
        $table = $record->getTable();
        $kind = mb_strtoupper(mb_substr(static::getResource()::getModelLabel(), 0, 1)).mb_substr(static::getResource()::getModelLabel(), 1);
        $ident = null;
        foreach (['number', 'code', 'name', 'title', 'subject', 'email', 'label'] as $column) {
            if (filled($record->getAttribute($column)) && is_scalar($record->getAttribute($column))) {
                $ident = (string) $record->getAttribute($column);
                break;
            }
        }
        $pills = [];
        foreach (['status', 'type', 'kind', 'severity', 'sink_status'] as $column) {
            $value = $record->getAttribute($column);
            if (is_string($value) && $value !== '') {
                $options = GenericFormVocabulary::options($table, $column) ?? [];
                $pills[] = GenericFormVocabulary::optionLabel($value, $options);
            }
        }
        $format = fn ($d): ?string => $d?->timezone('Europe/Rome')->format('d/m/Y H:i');

        return [
            'title' => $ident ?? $kind.' #'.$record->getKey(), 'kind' => $kind.' #'.$record->getKey(),
            'initials' => mb_strtoupper(mb_substr($kind, 0, 2)), 'pills' => $pills,
            'created' => $format($record->getAttribute('created_at')), 'updated' => $format($record->getAttribute('updated_at')),
        ];
    }

    /** Le colonne con forma nota (righe prodotto/quantità) si mostrano come righe da modificare. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return GenericForm::toForm($this->getRecord(), $data);
    }

    /** ...e prima di salvare tornano nella forma in cui le legge il resto del portale. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return GenericForm::fromForm($this->getRecord(), $data);
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
        $index = static::getResource()::getUrl('index');

        return [
            Action::make('back')->label('Torna all\'elenco')->icon('heroicon-o-arrow-left')->color('gray')->url($index),
            SuperadminActions::delete(static::getResource())->record($this->getRecord())->successRedirectUrl($index),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Salva modifiche');
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')->label('Annulla')->color('gray')->url(static::getResource()::getUrl('index'));
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Modifiche salvate';
    }
}
