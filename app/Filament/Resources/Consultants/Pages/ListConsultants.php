<?php

declare(strict_types=1);

namespace App\Filament\Resources\Consultants\Pages;

use App\Domain\Access\PasswordResetLinks;
use App\Domain\Features\Feature;
use App\Filament\Pages\Base\SuperadminListRecords;
use App\Filament\Pages\ConsultantLevelsPage;
use App\Filament\Resources\Consultants\ConsultantResource;
use App\Filament\Support\ConsultantList;
use App\Filament\Support\PasswordResetAction;
use App\Filament\Support\RankHoldAction;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class ListConsultants extends SuperadminListRecords
{
    protected static string $resource = ConsultantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('levels')->label('Elenco per livelli')->icon('heroicon-o-bars-3-bottom-left')->color('gray')
                ->visible(fn (): bool => ConsultantLevelsPage::canAccess())->url(fn (): string => ConsultantLevelsPage::getUrl()),
            RankHoldAction::all()->visible(fn (): bool => Feature::enabled('consultant360.action.hold_rank')),
            PasswordResetAction::all(PasswordResetLinks::AUDIENCE_CONSULTANTS),
            CreateAction::make()->label('Nuovo incaricato'),
        ];
    }

    /** Schede per stato con i conteggi (una sola query). Spegnibili da «Funzionalità e viste». */
    public function getTabs(): array
    {
        if (! Feature::enabled('backoffice.consultants_list.tabs')) {
            return [];
        }
        $n = app(ConsultantList::class)->counts();
        $tab = static fn (string $label, int $count, ?callable $q = null): Tab => Tab::make($label)->badge($count)
            ->modifyQueryUsing($q ?? static fn (Builder $query): Builder => $query);

        return [
            'all' => $tab('Tutti', $n['total']),
            'active' => $tab('Attivi', $n['active'], fn (Builder $query) => $query->where('consultants.status', 'active')),
            'pending' => $tab('In attesa', $n['pending'], fn (Builder $query) => $query->where('consultants.status', 'pending')),
            'suspended' => $tab('Sospesi', $n['suspended'], fn (Builder $query) => $query->where('consultants.status', 'suspended')),
            'terminated' => $tab('Cessati', $n['terminated'], fn (Builder $query) => $query->where('consultants.status', 'terminated')),
            'onboarding' => $tab('Da completare', $n['onboarding'], fn (Builder $query) => $query->where('consultants.status', '<>', 'terminated')->where('consultants.onboarding_complete', false)),
            'kyc' => $tab('KYC da verificare', $n['kyc'], fn (Builder $query) => $query->where('consultants.status', '<>', 'terminated')->where(fn ($w) => $w->whereNull('consultants.kyc_status')->orWhere('consultants.kyc_status', '<>', 'verified'))),
        ];
    }

    /** Intestazione propria: titolo, schede per stato al centro e pulsante «Nuovo incaricato» sulla stessa riga. */
    public function getHeader(): ?View
    {
        return view('filament.pages.consultants-header', [
            'breadcrumbs' => $this->getBreadcrumbs(), 'heading' => $this->getHeading(),
            'tabs' => $this->getCachedTabs(), 'active' => $this->activeTab, 'actions' => $this->getCachedHeaderActions(),
        ]);
    }

    /** Le schede stanno nell'intestazione: sopra la tabella non si ripetono. */
    public function getTabsContentComponent(): Tabs
    {
        return Tabs::make()->key('resourceTabs')->hidden();
    }
}
