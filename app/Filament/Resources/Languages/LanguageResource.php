<?php

declare(strict_types=1);

namespace App\Filament\Resources\Languages;

use App\Domain\Access\Superadmin;
use App\Domain\Audit\AuditLog;
use App\Domain\I18n\Locales;
use App\Filament\Resources\Languages\Pages\CreateLanguage;
use App\Filament\Resources\Languages\Pages\EditLanguage;
use App\Filament\Resources\Languages\Pages\ListLanguages;
use App\Models\Language;
use App\Models\TranslationString;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;
use UnitEnum;

/** Lingue del sito — SOLO super_admin. L'italiano è la lingua sorgente: non si disattiva e non si elimina. */
class LanguageResource extends Resource
{
    protected static ?string $model = Language::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $modelLabel = 'lingua';

    protected static ?string $pluralModelLabel = 'Lingue';

    protected static ?string $navigationLabel = 'Lingue';

    protected static ?string $slug = 'lingue';

    protected static ?int $navigationSort = 60;

    public static function canViewAny(): bool
    {
        return Superadmin::is();
    }

    public static function canCreate(): bool
    {
        return Superadmin::is();
    }

    public static function canEdit($record): bool
    {
        return Superadmin::is();
    }

    public static function canDelete($record): bool
    {
        return Superadmin::is() && ! $record->is_default;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** @return array<string, string> bandiere disponibili: nome del file → etichetta */
    public static function flagOptions(): array
    {
        $out = [];
        foreach (glob(public_path('img/flags/*.svg')) ?: [] as $file) {
            $code = basename($file, '.svg');
            $out[$code] = strtoupper($code);
        }
        ksort($out);

        return $out;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Lingua')->columns(2)->schema([
                TextInput::make('code')->label('Codice (2 lettere, es. pt)')->required()->maxLength(5)
                    ->regex('/^[a-z]{2}(-[a-z]{2})?$/')->validationMessages(['regex' => 'Usa il codice della lingua in minuscolo: due lettere (es. pt) o due più due (es. pt-br).'])
                    ->unique(ignoreRecord: true)->disabled(fn (?Language $record) => $record !== null)->dehydrated(fn (?Language $record) => $record === null)
                    ->rules([Rule::notIn(['admin', 'area', 'lingua', 'media', 'css', 'js', 'img'])])
                    ->helperText('Compare nell\'indirizzo delle pagine (es. /pt/negozio). Non si può cambiare dopo averlo creato.'),
                TextInput::make('name')->label('Nome in italiano')->required()->maxLength(60)->placeholder('Es. Portoghese'),
                TextInput::make('native_name')->label('Nome nella lingua (come compare nel selettore)')->required()->maxLength(60)->placeholder('Es. Português'),
                Select::make('flag')->label('Bandiera')->options(fn () => self::flagOptions())->placeholder('Nessuna (mostra il codice)')->searchable(),
                Select::make('status')->label('Stato')->options([Language::DRAFT => 'Bozza (visibile solo al back-office)', Language::ACTIVE => 'Attiva (visibile a tutti)'])->required()->default(Language::DRAFT)
                    ->disabled(fn (?Language $record) => (bool) $record?->is_default)->dehydrated()
                    ->helperText('Attiva una lingua solo dopo che il traduttore ha controllato i testi: da attiva compare nel selettore del sito.'),
                TextInput::make('sort')->label('Posizione nell\'elenco')->numeric()->integer()->minValue(0)->default(10),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->columns([
            ImageColumn::make('flag')->label('')->state(fn (Language $r) => $r->flag && is_file(public_path('img/flags/'.$r->flag.'.svg')) ? url('img/flags/'.$r->flag.'.svg') : null)->width(30)->height(20),
            TextColumn::make('code')->label('Codice')->badge()->formatStateUsing(fn (string $state) => strtoupper($state)),
            TextColumn::make('native_name')->label('Lingua')->description(fn (Language $r) => $r->name),
            TextColumn::make('status')->label('Stato')->badge()->color(fn (string $state) => $state === Language::ACTIVE ? 'success' : 'gray')
                ->formatStateUsing(fn (string $state) => $state === Language::ACTIVE ? 'Attiva' : 'Bozza'),
            TextColumn::make('progress')->label('Testi tradotti')->state(function (Language $r): string {
                $q = TranslationString::query()->where('locale', $r->code)->where('orphan', false);
                $total = (clone $q)->count();
                if ($total === 0) {
                    return '—';
                }
                $approved = (clone $q)->where('status', TranslationString::APPROVED)->count();
                $auto = (clone $q)->where('status', TranslationString::AUTO)->count();

                return $r->is_default ? $total.' testi' : "{$approved} approvati · {$auto} da rivedere · ".($total - $approved - $auto).' mancanti';
            }),
            IconColumn::make('is_default')->label('Sorgente')->boolean(),
        ])->recordActions([
            Action::make('preview')->label('Anteprima')->icon('heroicon-o-eye')->iconButton()->tooltip('Apri il sito in questa lingua')
                ->url(fn (Language $r) => '/'.$r->code, shouldOpenInNewTab: true),
            EditAction::make()->iconButton()->tooltip('Modifica'),
            DeleteAction::make()->iconButton()->tooltip('Elimina')->visible(fn (Language $r) => static::canDelete($r))
                ->modalDescription('Si eliminano anche tutte le traduzioni di questa lingua. Il sito non cambia per l\'italiano.')
                ->after(function (Language $r): void {
                    TranslationString::where('locale', $r->code)->delete();
                    Locales::forgetLanguages();
                    AuditLog::record('lingue.eliminata', 'Lingua eliminata: '.$r->native_name.' ('.$r->code.')', null, ['codice' => $r->code]);
                    Notification::make()->title('Lingua eliminata')->success()->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLanguages::route('/'), 'create' => CreateLanguage::route('/create'), 'edit' => EditLanguage::route('/{record}/edit')];
    }
}
