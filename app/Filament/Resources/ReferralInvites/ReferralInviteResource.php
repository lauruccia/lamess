<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralInvites;

use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\ReferralInvites\Pages\ListReferralInvites;
use App\Filament\Support\ConsultantFilter;
use App\Models\ReferralInvite;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Inviti referral di tutta la rete: chi ha invitato chi, e a che punto è (sola lettura). */
class ReferralInviteResource extends Resource
{
    use GatedByPermission;

    /** Filtro «Stato»: gli stati salvati più i due in cui si divide «registrato». */
    public const STATE_FILTER = [
        'created' => 'Creato', 'sent' => 'Inviato', 'opened' => 'Aperto', 'registered_no_order' => 'Registrato, non ha ancora ordinato',
        'ordered' => 'Ha ordinato', 'expired' => 'Scaduto (non registrato)', 'failed' => 'Email non partita',
    ];

    protected static function viewPermission(): string
    {
        return 'network.view';
    }

    protected static function managePermission(): string
    {
        return 'network.manage';
    }

    protected static ?string $model = ReferralInvite::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Rete';

    protected static ?string $modelLabel = 'invito';

    protected static ?string $pluralModelLabel = 'Inviti referral';

    protected static ?int $navigationSort = 9;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->withFirstPaidAt()->with(['consultant', 'registeredUser']))
            ->columns([
                TextColumn::make('consultant.name')->label('Incaricato')->description(fn (ReferralInvite $r) => $r->consultant?->code)->searchable(['consultants.name', 'consultants.code'], isIndividual: false)->sortable(),
                TextColumn::make('recipient_name')->label('Persona')->state(fn (ReferralInvite $r) => $r->recipientLabel())->searchable(['recipient_name', 'email', 'phone']),
                TextColumn::make('email')->label('Email')->placeholder('—')->limit(32)->tooltip(fn ($state) => $state)->toggleable(),
                TextColumn::make('phone')->label('Telefono')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('channel')->label('Canale')->badge()->formatStateUsing(fn (string $state) => ReferralInvite::CHANNELS[$state] ?? $state),
                TextColumn::make('status')->label('Stato')->badge()->state(fn (ReferralInvite $r) => $r->displayLabel())
                    ->color(fn (ReferralInvite $r) => match ($r->displayTone()) {
                        'ok' => 'success', 'warn' => 'warning', 'err' => 'danger', 'info' => 'info', default => 'gray'
                    }),
                TextColumn::make('sent_at')->label('Inviato il')->dateTime('d/m/Y H:i')->timezone('Europe/Rome')->placeholder('—')->sortable(),
                TextColumn::make('reminders_count')->label('Promemoria')->numeric()->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('opened_at')->label('Aperto il')->dateTime('d/m/Y H:i')->timezone('Europe/Rome')->placeholder('—')->sortable(),
                TextColumn::make('opens_count')->label('Aperture')->numeric()->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('registered_at')->label('Registrato il')->dateTime('d/m/Y H:i')->timezone('Europe/Rome')->placeholder('—')->sortable(),
                TextColumn::make('first_paid_at')->label('Primo ordine')->dateTime('d/m/Y')->timezone('Europe/Rome')->placeholder('—'),
                TextColumn::make('expires_at')->label('Scade il')->date('d/m/Y')->timezone('Europe/Rome')->placeholder('—')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('failure_reason')->label('Nota')->placeholder('—')->limit(40)->tooltip(fn ($state) => $state)->toggleable(isToggledHiddenByDefault: true),
            ])->filters([
                ConsultantFilter::make('consultant_id'),
                SelectFilter::make('state')->label('Stato')->options(self::STATE_FILTER)
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'ordered' => $query->ordered(),
                        'registered_no_order' => $query->registeredWithoutOrder(),
                        'created', 'sent', 'opened', 'expired', 'failed' => $query->where('status', $data['value']),
                        default => $query,
                    }),
                SelectFilter::make('channel')->label('Canale')->options(ReferralInvite::CHANNELS),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListReferralInvites::route('/')];
    }
}
