<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Audit\AuditLog;
use App\Models\Consultant;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * «Mantieni qualifica»: la qualifica attuale di un incaricato (o di più incaricati già registrati) non si riduce per mancanza di clienti
 * fino alla data scelta dall'admin. Sono protetti i mesi che finiscono entro la data. Data vuota = protezione tolta. Ogni modifica è nel log.
 */
final class RankHoldAction
{
    /** @return list<\Filament\Forms\Components\Field> */
    public static function form(): array
    {
        return [
            DatePicker::make('rank_hold_until')->label('Mantieni la qualifica fino al')->native(false)->displayFormat('d/m/Y')->format('Y-m-d')
                ->helperText('Sono protetti i mesi che finiscono entro questa data: per proteggere tutto ottobre indica il 31/10. Vuoto = toglie la protezione.'),
            Textarea::make('reason')->label('Motivo')->required()->maxLength(500),
        ];
    }

    /** Imposta (o toglie, con `$until` nullo) la protezione e scrive il log. Restituisce quanti incaricati sono cambiati. */
    public static function apply(iterable $consultants, ?string $until, string $reason): int
    {
        $changed = 0;
        foreach ($consultants as $c) {
            /** @var Consultant $c */
            if ($c->status === 'terminated') {
                continue;
            }
            $before = $c->rank_hold_until?->format('Y-m-d');
            if ($before === $until) {
                continue;
            }
            $c->update(['rank_hold_until' => $until, 'rank_hold_reason' => $until === null ? null : $reason]);
            AuditLog::record(
                $until === null ? 'rank.hold_removed' : 'rank.hold',
                $until === null ? "Protezione della qualifica tolta: incaricato {$c->code}" : "Qualifica mantenuta fino al {$until}: incaricato {$c->code}",
                $c, ['fino_al' => ['prima' => $before, 'dopo' => $until]], $reason,
            );
            $changed++;
        }

        return $changed;
    }

    private static function until(array $data): ?string
    {
        return filled($data['rank_hold_until'] ?? null) ? (string) $data['rank_hold_until'] : null;
    }

    private static function notify(?string $until, int $n): void
    {
        Notification::make()
            ->title($until === null ? "Protezione tolta a {$n} incaricati" : "Qualifica mantenuta fino al ".CarbonImmutable::parse($until)->format('d/m/Y')." per {$n} incaricati")
            ->body('Vale dal prossimo ricalcolo del periodo (automatico ogni notte). I cessati sono esclusi.')->success()->send();
    }

    /** Azione sulla riga dell'elenco (menu «Azioni»). */
    public static function forRow(): Action
    {
        return Action::make('holdRankRow')->authorize('network.restructure')->label('Mantieni qualifica')->icon('heroicon-o-shield-check')->color('gray')
            ->visible(fn (Consultant $record): bool => $record->status !== 'terminated')
            ->modalDescription('La qualifica attuale non si riduce per mancanza di clienti fino alla data scelta (può comunque salire). Data vuota = toglie la protezione.')
            ->fillForm(fn (Consultant $record): array => ['rank_hold_until' => $record->rank_hold_until?->format('Y-m-d'), 'reason' => $record->rank_hold_reason])
            ->schema(self::form())
            ->action(function (array $data, Consultant $record): void {
                $until = self::until($data);
                self::apply([$record], $until, (string) $data['reason']);
                Notification::make()->title($until === null ? 'Protezione tolta' : 'Qualifica mantenuta fino al '.CarbonImmutable::parse($until)->format('d/m/Y'))->success()->send();
            });
    }

    /** Azione di massa sulle righe selezionate dell'elenco. */
    public static function bulk(): BulkAction
    {
        return BulkAction::make('holdRankBulk')->authorize('network.restructure')->label('Mantieni qualifica')->icon('heroicon-o-shield-check')->color('gray')
            ->modalDescription('Stessa data per tutti gli incaricati selezionati (i cessati sono saltati). La qualifica può comunque salire.')
            ->schema(self::form())->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                $until = self::until($data);
                self::notify($until, self::apply($records, $until, (string) $data['reason']));
            });
    }

    /** Azione in testata dell'elenco: stessa data a tutti gli incaricati già registrati (non cessati). */
    public static function all(): Action
    {
        return Action::make('holdRankAll')->authorize('network.restructure')->label('Mantieni qualifiche a tutti')->icon('heroicon-o-shield-check')->color('gray')
            ->modalHeading('Mantieni la qualifica attuale a tutti gli incaricati registrati')
            ->modalDescription('Vale per tutti gli incaricati non cessati già presenti. Chi ha già una data diversa la vede sostituita. Data vuota = toglie la protezione a tutti.')
            ->schema(self::form())->requiresConfirmation()
            ->action(function (array $data): void {
                $until = self::until($data);
                $n = 0;
                Consultant::query()->where('status', '<>', 'terminated')->chunkById(200, function ($chunk) use (&$n, $until, $data): void {
                    $n += self::apply($chunk, $until, (string) $data['reason']);
                });
                self::notify($until, $n);
            });
    }
}
