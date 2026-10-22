<?php

declare(strict_types=1);

namespace App\Filament\Resources\NewsPosts\Pages;

use App\Filament\Pages\Base\StyledEditPage;
use App\Filament\Resources\NewsPosts\NewsPostResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNewsPost extends EditRecord
{
    use StyledEditPage;

    protected static string $resource = NewsPostResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
