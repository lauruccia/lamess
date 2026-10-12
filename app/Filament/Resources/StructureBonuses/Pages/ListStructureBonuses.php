<?php

declare(strict_types=1);

namespace App\Filament\Resources\StructureBonuses\Pages;

use App\Domain\Payouts\StructureBonusService;
use App\Filament\Resources\StructureBonuses\StructureBonusResource;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use App\Filament\Pages\Base\SuperadminListRecords;
use Illuminate\Support\Facades\Cache;

class ListStructureBonuses extends SuperadminListRecords
{
    protected static string $resource = StructureBonusResource::class;

    public function getSubheading(): ?string
    {
        $last = Cache::get(StructureBonusService::CACHE_KEY);
        $auto = (bool) config('lamess.structure_bonus_auto');
        $days = [1 => 'lunedì', 2 => 'martedì', 3 => 'mercoledì', 4 => 'giovedì', 5 => 'venerdì', 6 => 'sabato', 7 => 'domenica'];
        $when = $auto ? 'Calcolo automatico: ogni '.($days[(int) config('lamess.structure_bonus_weekday')] ?? '?').' alle '.str_pad((string) config('lamess.structure_bonus_hour'), 2, '0', STR_PAD_LEFT).':00.' : 'Calcolo automatico spento (impostazioni di sistema).';
        $done = $last ? ' Ultimo calcolo: '.CarbonImmutable::parse($last['at'])->setTimezone('Europe/Rome')->format('d/m/Y H:i').'.' : '';

        return $when.$done;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calculate')->label('Calcola ora')->icon('heroicon-o-calculator')
                ->visible(fn () => (bool) auth()->user()?->can('payouts.manage'))
                ->requiresConfirmation()->modalDescription('Calcola i Bonus di Struttura maturati finora e li scrive nel registro. I bonus già confermati non cambiano.')
                ->action(function (): void {
                    $r = app(StructureBonusService::class)->syncOpenPeriods();
                    Notification::make()->title("Calcolo fatto: {$r['new']} nuovi, {$r['updated']} aggiornati")->success()->send();
                }),
        ];
    }
}
