<?php

declare(strict_types=1);

namespace App\Filament\Resources\Periods\Pages;

use App\Domain\Payouts\PayoutService;
use App\Domain\Periods\PeriodService;
use App\Domain\Periods\PeriodStateException;
use App\Filament\Resources\Periods\PeriodResource;
use App\Models\Period;
use App\Models\Settlement;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

class ViewPeriod extends ViewRecord
{
    protected static string $resource = PeriodResource::class;

    private function run(Closure $fn, string $ok): void
    {
        try {
            $fn(app(PeriodService::class), $this->record);
            $this->record->refresh();
            Notification::make()->title($ok)->success()->send();
        } catch (PeriodStateException $e) {
            Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Errore nel calcolo')->body($e->getMessage())->danger()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        $open = fn (Period $p) => ! $p->isClosed();

        return [
            Action::make('live')->authorize('periods.manage')->label('Stima (live)')->icon('heroicon-o-calculator')->color('gray')
                ->visible(fn () => $open($this->record))
                ->action(fn () => $this->run(fn (PeriodService $s, Period $p) => $s->calculate($p, 'live'), 'Stima aggiornata (non definitiva)')),
            Action::make('runtime')->authorize('periods.manage')->label('Runtime ufficiale')->icon('heroicon-o-cog-6-tooth')
                ->visible(fn () => $open($this->record))
                ->action(fn () => $this->run(fn (PeriodService $s, Period $p) => $s->calculate($p, 'official'), 'Runtime completato: periodo in revisione')),
            Action::make('close')->authorize('periods.manage')->label('Chiudi periodo')->icon('heroicon-o-lock-closed')->color('danger')
                ->visible(fn () => $open($this->record))
                ->requiresConfirmation()
                ->modalDescription('Le righe verranno bloccate e il periodo non sarà più modificabile. Le correzioni si faranno come rettifiche nel periodo aperto successivo.')
                ->action(fn () => $this->run(fn (PeriodService $s, Period $p) => $s->close($p), 'Periodo chiuso')),
            Action::make('settle')->authorize('payouts.manage')->label('Genera liquidazioni')->icon('heroicon-o-banknotes')->color('gray')
                ->visible(fn () => $this->record->isClosed() && ! Settlement::where('period_id', $this->record->id)->exists())
                ->action(fn () => $this->run(fn (PeriodService $s, Period $p) => app(PayoutService::class)->generateSettlements($p), 'Liquidazioni generate')),
            Action::make('historical')->authorize('payouts.manage')->label('Segna come storico già pagato')->icon('heroicon-o-archive-box')->color('gray')
                ->visible(function (): bool {
                    $all = Settlement::where('period_id', $this->record->id);

                    return $this->record->isClosed()
                        && ! (clone $all)->whereNotNull('payout_id')->exists()
                        && ((clone $all)->doesntExist() || (clone $all)->where('status', 'open')->exists());
                })
                ->requiresConfirmation()
                ->modalDescription('Per i mesi già pagati fuori dal portale (ad esempio con Odoo): crea le liquidazioni e le segna come pagate, così non finiscono in nessun pagamento nuovo.')
                ->action(fn () => $this->run(fn (PeriodService $s, Period $p) => app(PayoutService::class)->markHistorical($p), 'Mese segnato come storico già pagato')),
            Action::make('export')->authorize('payouts.view')->label('Esporta provvigioni (CSV)')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn () => $this->record->isClosed())
                ->url(fn () => url('/admin-files/export/commissions/'.$this->record->code)),
            Action::make('reopen')->label('Riapri il mese')->icon('heroicon-o-lock-open')->color('danger')
                ->visible(fn () => $this->record->isClosed() && (auth()->user()?->isSuperAdmin() ?? false))
                ->schema([Textarea::make('reason')->label('Motivo della riapertura')->required()])
                ->modalDescription('Riservato al super_admin. Le righe tornano modificabili, le liquidazioni e i pagamenti non ancora pagati vengono annullati. Non si può riaprire un mese con importi già pagati.')
                ->action(function (array $data): void {
                    $this->run(fn (PeriodService $s, Period $p) => $s->reopen($p, $data['reason']), 'Mese riaperto: ora è in revisione');
                }),
        ];
    }
}
