<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Career\CommissionExplainer;
use App\Domain\Features\Feature;
use App\Filament\Resources\Consultants\ConsultantResource;
use App\Models\Consultant;
use App\Models\Period;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * «Dettaglio compensi» di un incaricato in un mese: ogni compenso con la frase che dice da cosa è dovuto
 * (ordine, cliente, livello, regola, motivo di un'eventuale trattenuta). Si apre da «Compensi del mese». Sola lettura.
 */
class CompensoDettaglioPage extends Page
{
    protected static ?string $title = 'Dettaglio compensi';

    protected static ?string $slug = 'compenso-dettaglio';

    protected string $view = 'filament.pages.compenso-dettaglio';

    #[Url(as: 'incaricato')]
    public ?int $consultantId = null;

    #[Url(as: 'mese')]
    public ?string $periodCode = null;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('payouts.view') && Feature::enabled('backoffice.page.compensi_mese');
    }

    public static function urlFor(int $consultantId, ?string $periodCode): string
    {
        return static::getUrl(array_filter(['incaricato' => $consultantId, 'mese' => $periodCode]));
    }

    public function consultant(): ?Consultant
    {
        return $this->consultantId ? Consultant::find($this->consultantId) : null;
    }

    public function period(): ?Period
    {
        $q = Period::query()->orderByDesc('code');

        return $this->periodCode ? ((clone $q)->where('code', $this->periodCode)->first() ?? $q->first()) : $q->first();
    }

    /** @return \Illuminate\Support\Collection<int, Period> */
    public function periods(): \Illuminate\Support\Collection
    {
        return Period::query()->orderByDesc('code')->limit(24)->get(['id', 'code', 'status']);
    }

    /** @return array<string, mixed>|null */
    public function data(): ?array
    {
        $c = $this->consultant();
        $p = $this->period();

        return ($c === null || $p === null) ? null : app(CommissionExplainer::class)->for($c, $p);
    }

    public function consultantUrl(): ?string
    {
        return $this->consultantId ? ConsultantResource::getUrl('view', ['record' => $this->consultantId]) : null;
    }
}
