<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\Tickets\TicketResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditTicket extends SuperadminEditRecord
{
    protected static string $resource = TicketResource::class;
}
