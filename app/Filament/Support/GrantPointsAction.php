<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Ledger\PointsGrant;
use App\Domain\Periods\NightlyRecalculation;
use App\Domain\Periods\PeriodStateException;
use App\Models\Consultant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * «Assegna punti»: funzione riservata all'azienda (permesso «periods.manage», il super admin lo ha sempre) per dare punti a un
 * incaricato senza un acquisto, con causale, e se serve anche al suo sponsor. Vedi {@see PointsGrant}.
 */
final class GrantPointsAction
{
    public static function make(): Action
    {
        $consultantLabel = static fn (Consultant $c): string => "{$c->code} — {$c->name}";
        $isClientEffect = static fn (Get $get): bool => in_array($get('effect'), [PointsGrant::EFFECT_CLIENT, PointsGrant::EFFECT_PURCHASE], true);
        $sponsorOf = static fn (Get $get): ?Consultant => filled($get('consultant_id')) ? Consultant::find(Consultant::find($get('consultant_id'))?->sponsor_id) : null;

        return Action::make('grantPoints')->authorize('periods.manage')
            ->label('Assegna punti')->icon('heroicon-o-gift')
            ->modalHeading('Assegna punti')->modalSubmitActionLabel('Assegna')->modalWidth('3xl')
            ->modalDescription('Dai punti a un incaricato senza che serva l\'acquisto di una box o di un kit (per esempio per la quota del consorzio). Resta tutto nel registro e nel log di audit.')
            ->schema([
                Select::make('consultant_id')->label('Beneficiario (incaricato)')->required()->searchable()->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $set('client_user_id', Consultant::find($get('consultant_id'))?->user_id))
                    ->getSearchResultsUsing(fn (string $search): array => Consultant::query()->where('status', '<>', 'terminated')
                        ->where(fn ($w) => $w->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                        ->orderBy('code')->limit(30)->get()->mapWithKeys(fn (Consultant $c): array => [$c->id => $consultantLabel($c)])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($c = Consultant::find($value)) ? $consultantLabel($c) : null),
                Grid::make(2)->schema([
                    TextInput::make('points')->label('Punti')->numeric()->required()->minValue(0.01)->step(0.01),
                    DateTimePicker::make('event_at')->label('Data (ora italiana)')->timezone('Europe/Rome')->seconds(false)->default(now())->required()
                        ->helperText('Conta nel periodo di questo mese: deve essere ancora aperto.'),
                ]),
                Textarea::make('reason')->label('Causale')->required()->maxLength(255)->rows(2)
                    ->placeholder('Per esempio: quota consorzio Kadeo versata dal socio'),
                Select::make('effect')->label('Effetto sul piano compensi')->native(false)->required()->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => blank($get('client_user_id')) ? $set('client_user_id', Consultant::find($get('consultant_id'))?->user_id) : null)
                    ->default(PointsGrant::EFFECT_SCORE)->options(PointsGrant::EFFECTS)
                    ->helperText('Con «solo punteggio» i punti si registrano e basta. Gli altri due fanno valere una persona come cliente del beneficiario per i mesi indicati.'),
                Select::make('client_user_id')->label('Chi vale come cliente')->searchable()
                    ->visible($isClientEffect)->required($isClientEffect)
                    ->helperText('La persona (cliente o incaricato) che il piano conta come cliente del beneficiario. Di default è il beneficiario stesso: cambiala se il cliente è un altro (per esempio il socio, quando il beneficiario è il suo sponsor).')
                    ->getSearchResultsUsing(fn (string $search): array => User::query()
                        ->where(fn ($w) => $w->whereHas('customer')->orWhereHas('consultant'))
                        ->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->orderBy('name')->limit(30)->get()->mapWithKeys(fn (User $u): array => [$u->id => "{$u->name} ({$u->email})"])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($u = User::find($value)) ? "{$u->name} ({$u->email})" : null),
                Grid::make(3)->visible($isClientEffect)->schema([
                    TextInput::make('months')->label('Validità cliente (mesi)')->numeric()->integer()->minValue(1)->maxValue(120)->default(1)->required($isClientEffect),
                    TextInput::make('clients')->label('Quanti clienti vale')->numeric()->integer()->minValue(1)->maxValue(100)->default(1)->required($isClientEffect),
                    TextInput::make('amount')->label('Importo in euro, IVA inclusa')->numeric()->minValue(0.01)->step(0.01)
                        ->visible(fn (Get $get): bool => $get('effect') === PointsGrant::EFFECT_PURCHASE)->required(fn (Get $get): bool => $get('effect') === PointsGrant::EFFECT_PURCHASE),
                ]),
                Toggle::make('also_sponsor')->label('Assegna anche allo sponsor del beneficiario')->live()
                    ->visible(fn (Get $get): bool => $sponsorOf($get) !== null)
                    ->helperText(fn (Get $get): ?string => ($s = $sponsorOf($get)) ? 'Sponsor: '.$consultantLabel($s) : null),
                TextInput::make('sponsor_points')->label('Punti allo sponsor (vuoto = gli stessi)')->numeric()->minValue(0.01)->step(0.01)
                    ->visible(fn (Get $get): bool => (bool) $get('also_sponsor') && $sponsorOf($get) !== null),
            ])
            ->action(fn (array $data, Action $action) => self::grant($data, $action));
    }

    /** @param  array<string, mixed>  $data */
    private static function grant(array $data, Action $action): void
    {
        $service = app(PointsGrant::class);
        $beneficiary = Consultant::findOrFail($data['consultant_id']);
        $client = filled($data['client_user_id'] ?? null) ? User::find($data['client_user_id']) : null;
        $at = new \DateTimeImmutable((string) $data['event_at'], new \DateTimeZone('UTC'));
        $effect = (string) $data['effect'];
        $args = [
            'reason' => (string) $data['reason'], 'effect' => $effect, 'client' => $client, 'amount' => filled($data['amount'] ?? null) ? (string) $data['amount'] : null,
            'months' => (int) ($data['months'] ?? 1), 'clients' => (int) ($data['clients'] ?? 1), 'at' => $at, 'by' => auth()->user(),
        ];

        try {
            $n = DB::transaction(function () use ($service, $beneficiary, $data, $args): int {
                $service->grant($beneficiary, (string) $data['points'], ...$args);
                $sponsor = ! empty($data['also_sponsor']) ? Consultant::find($beneficiary->sponsor_id) : null;
                if ($sponsor === null) {
                    return 1;
                }
                $service->grant($sponsor, filled($data['sponsor_points'] ?? null) ? (string) $data['sponsor_points'] : (string) $data['points'], ...$args);

                return 2;
            });
        } catch (InvalidArgumentException|PeriodStateException $e) {
            Notification::make()->title('Punti non assegnati')->body($e->getMessage())->danger()->send();
            $action->halt();
        }

        Notification::make()->title($n === 2 ? 'Punti assegnati al beneficiario e al suo sponsor' : 'Punti assegnati')->success()->send();
        self::refreshCalculation();
    }

    /**
     * Dopo un'assegnazione (o un annullamento) ricalcola il mese aperto, così schede, «cosa manca» e qualifiche vedono subito i punti
     * invece di aspettare il ricalcolo notturno. I punti sono già nel registro: se il calcolo non riesce (per esempio ne è in corso un altro)
     * l'operazione resta valida e si avvisa che l'aggiornamento arriverà con il ricalcolo della notte.
     */
    public static function refreshCalculation(): void
    {
        try {
            $r = app(NightlyRecalculation::class)->run(false);
        } catch (\Throwable $e) {
            report($e);
            $r = ['action' => 'failed', 'message' => $e->getMessage()];
        }
        if ($r['action'] === 'calculated') {
            Notification::make()->title('Calcolo aggiornato')->body('Le schede e i requisiti tengono già conto dei punti.')->success()->send();

            return;
        }
        Notification::make()->title('Calcolo non aggiornato')
            ->body('I punti sono registrati, ma le schede li mostreranno dopo il prossimo ricalcolo (stanotte). '.$r['message'])->warning()->send();
    }
}
