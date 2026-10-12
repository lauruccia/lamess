<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\System\GoLiveCheck;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Controllo messa online — SOLO super_admin. */
class GoLiveCheckPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $navigationLabel = 'Controllo messa online';

    protected static ?string $title = 'Controllo messa online';

    protected static ?string $slug = 'controllo-messa-online';

    protected static ?int $navigationSort = 94;

    protected string $view = 'filament.pages.go-live-check';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $checks = app(GoLiveCheck::class)->run();

        return ['checks' => $checks, 'summary' => GoLiveCheck::summary($checks)];
    }
}
