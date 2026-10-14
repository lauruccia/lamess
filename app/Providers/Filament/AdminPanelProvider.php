<?php

namespace App\Providers\Filament;

use App\Filament\Pages\AuditVerificationPage;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\BackupPage;
use App\Filament\Pages\LegacyPlanPage;
use App\Filament\Pages\ConsultantLevelsPage;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FeaturesPage;
use App\Filament\Pages\GoLiveCheckPage;
use App\Filament\Pages\NetworkTreePage;
use App\Filament\Pages\IncaricatiSimulationPage;
use App\Filament\Pages\RankComparisonPage;
use App\Filament\Pages\SalesDashboardPage;
use App\Filament\Pages\ShopSettingsPage;
use App\Filament\Pages\SystemSettingsPage;
use App\Filament\Widgets\CommissionsByTypeChart;
use App\Filament\Widgets\KpiOverview;
use App\Filament\Widgets\RankDistributionChart;
use App\Filament\Widgets\SchedulerStatus;
use App\Support\PageHelp;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset(RequestPasswordReset::class)
            ->profile()
            ->multiFactorAuthentication(
                [AppAuthentication::make()->recoverable()],
                isRequired: fn () => (bool) config('lamess.admin_2fa_required'),
            )
            ->brandName('Lamèss — Back-office')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2.4rem')
            ->favicon(asset('favicon.ico').'?v=2')
            ->topNavigation()
            ->maxContentWidth('full')
            ->navigationGroups([
                NavigationGroup::make('Rete')->label('Rete'),
                NavigationGroup::make('Compensi')->label('Compensi'),
                NavigationGroup::make('Vendite')->label('Vendite'),
                NavigationGroup::make('Assistenza')->label('Assistenza'),
                NavigationGroup::make('Sistema')->label('Sistema'),
            ])
            ->font('Inter')
            // token grafici Lamèss: font Marcellus per i titoli, colori e tema scuro condivisi con il sito
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString('<link rel="stylesheet" href="'.e(asset('css/lamess-tokens.css')).'"><link rel="stylesheet" href="'.e(asset('css/lamess-admin.css')).'"><script src="'.e(asset('js/lamess-image-editor.js')).'?v=1" defer></script>'))
            ->renderHook(PanelsRenderHook::CONTENT_BEFORE, fn () => PageHelp::render())
            ->colors([
                'primary' => Color::hex('#C2663A'), // terracotta Lamèss
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->pages([
                Dashboard::class,
                NetworkTreePage::class,
                ConsultantLevelsPage::class,
                SystemSettingsPage::class,
                ShopSettingsPage::class,
                AuditVerificationPage::class,
                BackupPage::class,
                LegacyPlanPage::class,
                FeaturesPage::class,
                GoLiveCheckPage::class,
                RankComparisonPage::class,
                IncaricatiSimulationPage::class,
                SalesDashboardPage::class,
            ])
            ->widgets([
                SchedulerStatus::class,
                KpiOverview::class,
                RankDistributionChart::class,
                CommissionsByTypeChart::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
