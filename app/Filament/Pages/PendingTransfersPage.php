<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Support\PeriodKey;
use App\Domain\Features\Feature;
use App\Domain\Orders\OrderException;
use App\Domain\Periods\PendingTransfers;
use App\Domain\Periods\PeriodStateException;
use App\Models\Order;
use App\Models\Period;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

/**
 * «Bonifici in sospeso»: passaggio da fare a fine mese, prima del runtime ufficiale.
 * Per ogni ordine pagato con bonifico e ancora in attesa l'admin sceglie se confermarlo nel mese dell'ordine,
 * spostarlo al mese successivo oppure annullarlo. Ogni scelta richiede un motivo e resta nel registro.
 */
class PendingTransfersPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $navigationLabel = 'Bonifici in sospeso';

    protected static ?string $title = 'Bonifici in sospeso a fine mese';

    protected static ?string $slug = 'bonifici-in-sospeso';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.pending-transfers';

    #[Url(as: 'mese')]
    public ?string $periodCode = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('periods.manage') && Feature::enabled('backoffice.page.bonifici_in_sospeso');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }
        $period = Period::where('status', '!=', Period::CLOSED)->orderBy('code')->first();
        $n = $period ? app(PendingTransfers::class)->count($period) : 0;

        return $n > 0 ? (string) $n : null;
    }

    /** Il mese scelto: se non indicato, il più vecchio ancora aperto (è quello che va chiuso per primo). */
    public function period(): ?Period
    {
        if ($this->periodCode) {
            $p = Period::where('code', $this->periodCode)->first();
            if ($p) {
                return $p;
            }
        }

        return Period::where('status', '!=', Period::CLOSED)->orderBy('code')->first() ?? Period::orderByDesc('code')->first();
    }

    /** @return Collection<int, Period> */
    public function periods(): Collection
    {
        return Period::query()->orderByDesc('code')->limit(12)->get(['id', 'code', 'status']);
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $period = $this->period();
        if ($period === null) {
            return ['period' => null, 'rows' => collect(), 'next' => null, 'ended' => false, 'closed' => false];
        }
        $rows = $period->isClosed() ? collect() : app(PendingTransfers::class)->query($period)->with('buyer')->orderBy('created_at')->get();

        return [
            'period' => $period, 'rows' => $rows, 'closed' => $period->isClosed(),
            'next' => PeriodKey::parse($period->code)->next()->code(),
            'ended' => now()->gte($period->ends_at),
        ];
    }

    /** Un'azione per ogni scelta: si apre dal pulsante della riga con l'id dell'ordine e chiede il motivo. */
    public function confirmAction(): Action
    {
        return $this->decision('confirm', 'Conferma nel mese', 'Bonifico arrivato', 'heroicon-o-check-circle', 'success',
            'L\'ordine vale nel mese della sua data. L\'incasso e la fattura restano alla data di oggi.',
            fn (Order $o, Period $p, array $d) => app(PendingTransfers::class)->confirmInPeriod($o, $p, (string) $d['reason'], ($d['reference'] ?? null) ?: null),
            'Bonifico confermato nel mese', withReference: true);
    }

    public function deferAction(): Action
    {
        return $this->decision('defer', 'Sposta al mese successivo', 'Rimanda al mese dopo', 'heroicon-o-arrow-right-circle', 'warning',
            'L\'ordine resta in attesa e non sarà annullato alla chiusura di questo mese. Conterà nel mese in cui lo confermerai.',
            fn (Order $o, Period $p, array $d) => app(PendingTransfers::class)->deferToNext($o, $p, (string) $d['reason']),
            'Bonifico rimandato al mese successivo');
    }

    public function cancelAction(): Action
    {
        return $this->decision('cancel', 'Annulla ordine', 'Annulla ordine', 'heroicon-o-x-circle', 'danger',
            'L\'ordine viene annullato: punti annullati, magazzino e codici sconto liberati. Se era stata incassata una quota KMoney viene riaccreditata.',
            fn (Order $o, Period $p, array $d) => app(PendingTransfers::class)->cancel($o, $p, (string) $d['reason']),
            'Ordine annullato');
    }

    /** @param Closure(Order, Period, array<string,mixed>): mixed $do */
    private function decision(string $name, string $heading, string $label, string $icon, string $color, string $help, Closure $do, string $ok, bool $withReference = false): Action
    {
        $fields = [Textarea::make('reason')->label('Motivo (resta nel registro)')->required()->minLength(5)->maxLength(500)];
        if ($withReference) {
            array_unshift($fields, TextInput::make('reference')->label('Riferimento del bonifico (CRO/TRN, facoltativo)')->maxLength(100));
        }

        return Action::make($name)->authorize('periods.manage')->label($label)->icon($icon)->color($color)
            ->modalHeading($heading)->modalDescription($help)->schema($fields)
            ->action(function (array $data, array $arguments) use ($do, $ok): void {
                try {
                    $order = Order::findOrFail((int) ($arguments['order'] ?? 0));
                    $period = $this->period() ?? throw new PeriodStateException('Nessun mese selezionato.');
                    $do($order, $period, $data);
                    Notification::make()->title($ok)->body("Ordine {$order->number}")->success()->send();
                } catch (PeriodStateException|OrderException $e) {
                    Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
                } catch (Throwable $e) {
                    report($e);
                    AuditLog::record('period.transfer_error', 'Errore nel controllo bonifici: '.$e->getMessage(), $this->period());
                    Notification::make()->title('Errore')->body($e->getMessage())->danger()->send();
                }
            });
    }
}
