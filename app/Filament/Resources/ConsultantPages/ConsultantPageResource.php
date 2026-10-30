<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConsultantPages;

use App\Domain\Pages\PageException;
use App\Domain\Pages\PersonalPageService;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\ConsultantPages\Pages\ListConsultantPages;
use App\Filament\Support\ConsultantFilter;
use App\Models\ConsultantPage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Pagine personali pubbliche degli incaricati: approvazione delle modifiche, nascondere una pagina, correggere un testo. */
class ConsultantPageResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'compliance.view';
    }

    protected static function managePermission(): string
    {
        return 'compliance.manage';
    }

    protected static ?string $model = ConsultantPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Assistenza';

    protected static ?string $modelLabel = 'pagina personale';

    protected static ?string $pluralModelLabel = 'Pagine personali';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = ConsultantPage::where('review', 'pending')->count();

        return $n > 0 ? (string) $n : null;
    }

    private static function guard(callable $fn, string $ok): void
    {
        try {
            $fn();
            Notification::make()->title($ok)->success()->send();
        } catch (PageException $e) {
            Notification::make()->title('Non eseguibile')->body($e->getMessage())->danger()->send();
        }
    }

    public static function table(Table $table): Table
    {
        $svc = fn (): PersonalPageService => app(PersonalPageService::class);

        return $table->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('consultant'))
            ->columns([
                TextColumn::make('consultant.name')->label('Incaricato')->description(fn (ConsultantPage $p) => $p->consultant?->code)->searchable(['consultants.name', 'consultants.code'], isIndividual: false),
                TextColumn::make('slug')->label('Indirizzo')->state(fn (ConsultantPage $p) => '/i/'.$p->slug)->searchable(['slug'])->url(fn (ConsultantPage $p) => url('/i/'.$p->slug), shouldOpenInNewTab: true),
                TextColumn::make('review')->label('Revisione')->badge()
                    ->formatStateUsing(fn (string $state) => ['none' => 'Nessuna modifica', 'pending' => 'Da approvare', 'rejected' => 'Respinta'][$state] ?? $state)
                    ->color(fn (string $state) => ['pending' => 'warning', 'rejected' => 'danger'][$state] ?? 'gray'),
                IconColumn::make('online')->label('Online')->boolean()->state(fn (ConsultantPage $p) => $svc()->isPublic($p)),
                IconColumn::make('blocked')->label('Nascosta')->boolean()->trueColor('danger')->falseColor('gray'),
                TextColumn::make('bio')->label('Presentazione')->limit(70)->placeholder('—')->toggleable()->tooltip(fn (ConsultantPage $p) => $p->proposal['bio'] ?? $p->bio),
                TextColumn::make('views')->label('Visite')->numeric()->alignEnd()->sortable(),
                TextColumn::make('updated_at')->label('Aggiornata')->dateTime('d/m/Y H:i')->timezone('Europe/Rome')->sortable(),
            ])->filters([
                SelectFilter::make('review')->label('Revisione')->options(['pending' => 'Da approvare', 'rejected' => 'Respinta', 'none' => 'Nessuna modifica'])->default('pending'),
                SelectFilter::make('stato')->label('Stato')->options(['online' => 'Online', 'blocked' => 'Nascoste'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'online' => $query->where('enabled', true)->where('blocked', false)->whereNotNull('approved_at'),
                        'blocked' => $query->where('blocked', true),
                        default => $query,
                    }),
                ConsultantFilter::make('consultant_id'),
            ])->recordActions([
                Action::make('approve')->label('Approva')->iconButton()->tooltip('Approva le modifiche')->icon('heroicon-o-check')->color('success')->authorize('compliance.manage')
                    ->visible(fn (ConsultantPage $p) => is_array($p->proposal))
                    ->modalDescription(fn (ConsultantPage $p) => 'Presentazione proposta: '.($p->proposal['bio'] ?? '—'))
                    ->requiresConfirmation()
                    ->action(fn (ConsultantPage $p) => self::guard(fn () => $svc()->approve($p), 'Modifiche approvate')),
                Action::make('reject')->label('Non approvare')->iconButton()->tooltip('Respingi le modifiche')->icon('heroicon-o-x-mark')->color('danger')->authorize('compliance.manage')
                    ->visible(fn (ConsultantPage $p) => is_array($p->proposal))
                    ->schema([Textarea::make('note')->label('Motivo (lo legge l’incaricato)')->required()])
                    ->action(fn (ConsultantPage $p, array $data) => self::guard(fn () => $svc()->reject($p, (string) $data['note']), 'Modifiche respinte')),
                Action::make('edit')->label('Modifica testi')->iconButton()->tooltip('Correggi i testi')->icon('heroicon-o-pencil-square')->authorize('compliance.manage')
                    ->modalDescription('Le correzioni vanno online subito, senza passare dalla revisione. Se c’era una proposta in attesa, viene sostituita.')
                    ->fillForm(fn (ConsultantPage $p) => $svc()->content($p, true))
                    ->schema(self::editSchema())
                    ->action(fn (ConsultantPage $p, array $data) => self::guard(function () use ($svc, $p, $data): void {
                        $keepPhoto = ! ($data['remove_photo'] ?? false);
                        $svc()->save($p, $data, null, ! $keepPhoto, direct: true);
                    }, 'Pagina aggiornata')),
                Action::make('block')->label('Nascondi')->iconButton()->tooltip('Nascondi la pagina')->icon('heroicon-o-eye-slash')->color('danger')->authorize('compliance.manage')
                    ->visible(fn (ConsultantPage $p) => ! $p->blocked)
                    ->schema([Textarea::make('note')->label('Motivo (lo legge l’incaricato)')])
                    ->action(fn (ConsultantPage $p, array $data) => self::guard(fn () => $svc()->block($p, (string) ($data['note'] ?? '')), 'Pagina nascosta')),
                Action::make('unblock')->label('Riattiva')->iconButton()->tooltip('Consenti di nuovo la pagina')->icon('heroicon-o-eye')->color('success')->authorize('compliance.manage')
                    ->visible(fn (ConsultantPage $p) => $p->blocked)->requiresConfirmation()
                    ->action(fn (ConsultantPage $p) => self::guard(fn () => $svc()->unblock($p), 'Pagina di nuovo consentita')),
            ]);
    }

    /** @return list<mixed> */
    private static function editSchema(): array
    {
        return [
            TextInput::make('display_name')->label('Nome da mostrare')->required()->maxLength(120),
            TextInput::make('headline')->label('Qualifica')->maxLength(160),
            Textarea::make('bio')->label('Presentazione')->rows(4)->maxLength(900),
            Textarea::make('story')->label('La mia storia')->rows(5)->maxLength(3000),
            TextInput::make('whatsapp')->label('WhatsApp')->maxLength(30),
            TextInput::make('email_public')->label('Email pubblica')->email()->maxLength(190),
            TextInput::make('instagram')->label('Instagram')->url()->maxLength(300),
            TextInput::make('facebook')->label('Facebook')->url()->maxLength(300),
            TextInput::make('tiktok')->label('TikTok')->url()->maxLength(300),
            TextInput::make('linkedin')->label('LinkedIn')->url()->maxLength(300),
            Repeater::make('media')->label('Video e articoli consigliati')->maxItems(PersonalPageService::MEDIA_SLOTS)->defaultItems(0)->schema([
                Select::make('kind')->label('Tipo')->options(PersonalPageService::MEDIA_KINDS)->default('article')->required(),
                TextInput::make('title')->label('Titolo')->required()->maxLength(120),
                TextInput::make('url')->label('Link')->url()->required()->maxLength(300),
                TextInput::make('description')->label('Descrizione')->maxLength(300),
            ])->columns(2),
            Toggle::make('remove_photo')->label('Togli la foto'),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListConsultantPages::route('/')];
    }
}
