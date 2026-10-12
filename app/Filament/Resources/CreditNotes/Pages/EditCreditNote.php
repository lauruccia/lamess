<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\CreditNotes\CreditNoteResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditCreditNote extends SuperadminEditRecord
{
    protected static string $resource = CreditNoteResource::class;
}
