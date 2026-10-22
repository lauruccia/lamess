<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

/** Sezione dei moduli di modifica: titolo e spiegazione a sinistra, campi a destra, riga di separazione sotto. */
final class FormSection
{
    /** @param  list<Component>  $fields */
    public static function make(string $title, ?string $description, array $fields): Section
    {
        return Section::make($title)->description($description)->aside()->contained(false)->columns(2)->columnSpanFull()
            ->extraAttributes(['style' => 'border-bottom:1px solid rgba(187,180,175,.35);padding-bottom:1.25rem'])->schema($fields);
    }
}
