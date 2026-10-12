<?php

declare(strict_types=1);

namespace App\Filament\Resources\Discounts\Pages;

use App\Filament\Resources\Discounts\DiscountResource;
use Filament\Actions\CreateAction;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListDiscounts extends SuperadminListRecords
{
    protected static string $resource = DiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuovo sconto')];
    }
}
