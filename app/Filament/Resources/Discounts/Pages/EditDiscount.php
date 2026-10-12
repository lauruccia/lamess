<?php

declare(strict_types=1);

namespace App\Filament\Resources\Discounts\Pages;

use App\Filament\Resources\Discounts\DiscountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDiscount extends EditRecord
{
    protected static string $resource = DiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->modalDescription('Gli ordini già fatti con questo sconto restano invariati. Per fermarlo senza cancellarlo, disattivalo.')];
    }
}
