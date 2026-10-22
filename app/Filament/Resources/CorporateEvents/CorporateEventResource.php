<?php

declare(strict_types=1);

namespace App\Filament\Resources\CorporateEvents;

use App\Domain\Content\EventService;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\CorporateEvents\Pages\CreateCorporateEvent;
use App\Filament\Resources\CorporateEvents\Pages\EditCorporateEvent;
use App\Filament\Resources\CorporateEvents\Pages\ListCorporateEvents;
use App\Filament\Support\FormSection;
use App\Models\CorporateEvent;
use App\Models\EventRegistration;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Eventi, corsi e webinar con iscrizioni. */
class CorporateEventResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'content.manage';
    }

    protected static function managePermission(): string
    {
        return 'content.manage';
    }

    protected static ?string $model = CorporateEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Assistenza';

    protected static ?string $modelLabel = 'evento';

    protected static ?string $pluralModelLabel = 'Eventi e corsi';

    protected static ?int $navigationSort = 12;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            FormSection::make('L\'evento', 'Come si chiama e di cosa si tratta.', [
                TextInput::make('title')->label('Titolo')->required()->maxLength(190),
                Select::make('kind')->label('Tipo')->options(CorporateEvent::KINDS)->default('event')->required(),
                Textarea::make('description')->label('Descrizione')->rows(4)->columnSpanFull(),
            ]),
            FormSection::make('Quando e dove', 'Date, luogo e, se serve, il collegamento online.', [
                DateTimePicker::make('starts_at')->label('Inizio')->native(false)->displayFormat('d/m/Y H:i')->seconds(false)->required(),
                DateTimePicker::make('ends_at')->label('Fine')->native(false)->displayFormat('d/m/Y H:i')->seconds(false)->after('starts_at'),
                TextInput::make('location')->label('Luogo'),
                TextInput::make('online_url')->label('Collegamento online')->url()->helperText('Lo vede solo chi è iscritto.'),
            ]),
            FormSection::make('Iscrizioni', 'Chi può partecipare e quanti posti ci sono.', [
                Select::make('audience')->label('A chi è rivolto')->options(['all' => 'Tutti', 'consultants' => 'Solo incaricati', 'fans' => 'Solo clienti e FAN'])->default('all')->required(),
                TextInput::make('capacity')->label('Posti')->numeric()->minValue(1)->helperText('Vuoto: illimitati. Oltre il limite si va in lista d\'attesa.'),
                DateTimePicker::make('registration_deadline')->label('Iscrizioni entro')->native(false)->displayFormat('d/m/Y H:i')->seconds(false),
            ]),
            FormSection::make('Pubblicazione', 'Una bozza non la vede nessuno.', [
                Select::make('status')->label('Stato')->options(['draft' => 'Bozza', 'published' => 'Pubblicato', 'cancelled' => 'Annullato'])->default('draft')->required(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('starts_at', 'desc')->columns([
            TextColumn::make('title')->label('Titolo')->searchable(),
            TextColumn::make('kind')->label('Tipo')->formatStateUsing(fn (string $state) => CorporateEvent::KINDS[$state] ?? $state)->badge(),
            TextColumn::make('starts_at')->label('Inizio')->dateTime('d/m/Y H:i', 'Europe/Rome')->sortable(),
            TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state) => ['draft' => 'Bozza', 'published' => 'Pubblicato', 'cancelled' => 'Annullato'][$state] ?? $state),
            TextColumn::make('seats')->label('Iscritti')->state(fn (CorporateEvent $e) => $e->registeredCount().($e->capacity ? " / {$e->capacity}" : '').' (attesa '.$e->registrations()->where('status', 'waitlist')->count().')'),
        ])->recordActions([
            Action::make('registrations')->label('Iscritti')->iconButton()->tooltip('Iscritti')->icon('heroicon-o-users')->modalSubmitAction(false)->modalCancelActionLabel('Chiudi')
                ->modalContent(fn (CorporateEvent $e) => view('filament.event-registrations', ['rows' => EventRegistration::where('corporate_event_id', $e->id)->whereIn('status', ['registered', 'waitlist'])->with('user')->orderBy('id')->get()])),
            Action::make('promote')->label('Riempi i posti liberi')->iconButton()->tooltip('Riempi i posti liberi')->icon('heroicon-o-arrow-up-circle')->color('gray')->requiresConfirmation()
                ->visible(fn (CorporateEvent $e) => $e->capacity !== null)
                ->action(function (CorporateEvent $e): void {
                    app(EventService::class)->promoteNext($e);
                    Notification::make()->title('Lista d\'attesa aggiornata')->success()->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCorporateEvents::route('/'), 'create' => CreateCorporateEvent::route('/create'), 'edit' => EditCorporateEvent::route('/{record}/edit')];
    }
}
