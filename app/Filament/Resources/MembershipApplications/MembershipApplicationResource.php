<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipApplications;

use App\Domain\Customers\ApplicationService;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\MembershipApplications\Pages\ListMembershipApplications;
use App\Models\Consultant;
use App\Models\MembershipApplication;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use UnitEnum;

/** Candidature dalla pagina pubblica «Candidati»: l'amministrazione approva (con sponsor facoltativo) o rifiuta. */
class MembershipApplicationResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'network.view';
    }

    protected static function managePermission(): string
    {
        return 'network.manage';
    }

    protected static ?string $model = MembershipApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Rete';

    protected static ?string $modelLabel = 'candidatura';

    protected static ?string $pluralModelLabel = 'Candidature';

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
        $n = MembershipApplication::where('status', 'pending')->count();

        return $n > 0 ? (string) $n : null;
    }

    private static function guard(callable $fn, string $ok): void
    {
        try {
            $a = $fn();
            $note = $a instanceof MembershipApplication ? $a->email_note : null;
            $n = Notification::make()->title($ok);
            $note !== null ? $n->body($note)->warning() : $n->success();
            $n->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Non eseguibile')->body($e->getMessage())->danger()->send();
        }
    }

    public static function table(Table $table): Table
    {
        $svc = fn (): ApplicationService => app(ApplicationService::class);

        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['sponsor', 'reviewer']))
            ->columns([
                TextColumn::make('last_name')->label('Persona')->state(fn (MembershipApplication $a) => $a->fullName())->description(fn (MembershipApplication $a) => $a->email)
                    ->searchable(['first_name', 'last_name', 'email', 'phone']),
                TextColumn::make('phone')->label('Telefono')->toggleable(),
                TextColumn::make('city')->label('Città')->state(fn (MembershipApplication $a) => "{$a->city} ({$a->province})")->toggleable(),
                TextColumn::make('interest')->label('Interesse')->badge()->formatStateUsing(fn (string $state) => MembershipApplication::INTERESTS[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'consultant' ? 'warning' : 'gray'),
                TextColumn::make('message')->label('Presentazione')->limit(60)->placeholder('—')->tooltip(fn (MembershipApplication $a) => $a->message)->toggleable(),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state) => MembershipApplication::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => ['pending' => 'warning', 'approved' => 'info', 'registered' => 'success', 'rejected' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('sponsor.name')->label('Sponsor')->placeholder('—')->description(fn (MembershipApplication $a) => $a->sponsor?->code)->toggleable(),
                TextColumn::make('email_note')->label('Email')->state(fn (MembershipApplication $a) => $a->email_sent_at !== null ? 'Inviata il '.$a->email_sent_at->setTimezone('Europe/Rome')->format('d/m/Y H:i') : ($a->email_note ?? ($a->status === 'pending' ? '—' : 'Non inviata')))
                    ->wrap()->toggleable(),
                TextColumn::make('created_at')->label('Ricevuta il')->dateTime('d/m/Y H:i')->timezone('Europe/Rome')->sortable(),
                TextColumn::make('reviewed_at')->label('Valutata il')->dateTime('d/m/Y H:i')->timezone('Europe/Rome')->placeholder('—')->description(fn (MembershipApplication $a) => $a->reviewer?->name)->toggleable(),
            ])->filters([
                SelectFilter::make('status')->label('Stato')->options(MembershipApplication::STATUS_LABELS)->default('pending'),
                SelectFilter::make('interest')->label('Interesse')->options(MembershipApplication::INTERESTS),
            ])->recordActions([
                Action::make('approve')->label('Approva')->icon('heroicon-o-check')->color('success')->authorize('network.manage')
                    ->visible(fn (MembershipApplication $a) => $a->status === 'pending')
                    ->modalDescription('La persona riceve via email il link per registrarsi (vale 30 giorni). Lo sponsor è facoltativo: senza, vale l\'incaricato «casa», se impostato.')
                    ->schema([
                        Select::make('sponsor_id')->label('Sponsor (incaricato)')->searchable()->placeholder('Nessuno / incaricato «casa»')
                            ->getSearchResultsUsing(fn (string $search) => Consultant::query()->where('status', '!=', 'terminated')
                                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn (Consultant $c) => [$c->id => "{$c->name} [{$c->code}]"])->all())
                            ->getOptionLabelUsing(fn ($value) => ($c = Consultant::find($value)) ? "{$c->name} [{$c->code}]" : null),
                        Textarea::make('note')->label('Messaggio per la persona (facoltativo)')->rows(3)->maxLength(1000),
                    ])
                    ->action(fn (MembershipApplication $a, array $data) => self::guard(fn () => $svc()->approve($a, filled($data['sponsor_id'] ?? null) ? Consultant::find($data['sponsor_id']) : null, $data['note'] ?? null, auth()->user()), 'Candidatura approvata')),
                Action::make('reject')->label('Rifiuta')->icon('heroicon-o-x-mark')->color('danger')->authorize('network.manage')
                    ->visible(fn (MembershipApplication $a) => $a->status === 'pending')
                    ->modalDescription('La persona riceve una email cortese. Il motivo è facoltativo e lo legge lei.')
                    ->schema([Textarea::make('reason')->label('Motivo (facoltativo)')->rows(3)->maxLength(1000)])
                    ->action(fn (MembershipApplication $a, array $data) => self::guard(fn () => $svc()->reject($a, $data['reason'] ?? null, auth()->user()), 'Candidatura rifiutata')),
                Action::make('resend')->label('Rimanda email')->icon('heroicon-o-envelope')->color('gray')->authorize('network.manage')
                    ->visible(fn (MembershipApplication $a) => in_array($a->status, ['approved', 'rejected'], true))
                    ->requiresConfirmation()->modalDescription('Rimanda l\'email con l\'esito. Per le approvate il link viene prorogato di 30 giorni.')
                    ->action(fn (MembershipApplication $a) => self::guard(fn () => $svc()->resend($a), 'Email rimandata')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMembershipApplications::route('/')];
    }
}
