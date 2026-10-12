<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListCreditNotes extends SuperadminListRecords
{
    protected static string $resource = CreditNoteResource::class;
}
