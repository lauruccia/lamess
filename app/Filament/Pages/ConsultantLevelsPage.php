<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Features\Feature;
use App\Domain\Network\LevelList;
use App\Filament\Resources\Consultants\ConsultantResource;
use App\Models\Consultant;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Elenco degli incaricati in ordine di ramo, con un rientro per ogni livello. Sola lettura. */
class ConsultantLevelsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3BottomLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Rete';

    protected static ?string $navigationLabel = 'Elenco per livelli';

    protected static ?string $title = 'Elenco incaricati per livelli';

    protected static ?string $slug = 'elenco-livelli';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.consultant-levels';

    /** Incaricato da cui partire (vuoto = tutta la rete). */
    #[Url(as: 'da')]
    public ?int $rootId = null;

    /** Ultimo livello mostrato (vuoto = tutti). */
    #[Url(as: 'livelli')]
    public ?int $maxLevel = null;

    #[Url(as: 'stato')]
    public string $status = 'all';

    public string $rootSearch = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('network.view') && Feature::enabled('backoffice.page.elenco_livelli');
    }

    public function selectRoot(int $id): void
    {
        $this->rootId = Consultant::whereKey($id)->exists() ? $id : null;
        $this->rootSearch = '';
    }

    public function clearRoot(): void
    {
        $this->rootId = null;
        $this->rootSearch = '';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $maxLevel = $this->maxLevel !== null && $this->maxLevel >= 0 ? $this->maxLevel : null;
        $status = in_array($this->status, ['all', 'active', 'terminated', 'not_terminated'], true) ? $this->status : 'all';
        $search = trim($this->rootSearch);

        return [
            'list' => app(LevelList::class)->rows($this->rootId, $maxLevel, $status),
            'root' => $this->rootId ? Consultant::find($this->rootId, ['id', 'code', 'name']) : null,
            'suggestions' => $search === '' ? collect() : Consultant::query()
                ->where(fn ($w) => $w->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                ->orderBy('name')->limit(8)->get(['id', 'code', 'name']),
            'limit' => LevelList::LIMIT,
            'url' => fn (int $id): string => ConsultantResource::getUrl('view', ['record' => $id]),
        ];
    }
}
