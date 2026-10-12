<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoMaterials\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\PromoMaterials\PromoMaterialResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditPromoMaterial extends SuperadminEditRecord
{
    protected static string $resource = PromoMaterialResource::class;
}
