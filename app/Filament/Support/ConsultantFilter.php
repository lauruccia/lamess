<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Consultant;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtro «Incaricato» per le liste del back-office. Serve ai pulsanti-contatore della scheda incaricato, che aprono
 * la lista già filtrata (`?filters[consultant][value]=ID`); resta utilizzabile anche a mano dalla barra dei filtri.
 */
final class ConsultantFilter
{
    public static function make(string $column, string $name = 'consultant', string $label = 'Incaricato'): SelectFilter
    {
        $labelOf = static fn (Consultant $c): string => "{$c->code} — {$c->name}";

        return SelectFilter::make($name)->label($label)->searchable()
            ->getSearchResultsUsing(static fn (string $search): array => Consultant::query()
                ->where(fn ($w) => $w->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                ->orderBy('code')->limit(30)->get()->mapWithKeys(fn (Consultant $c) => [$c->id => $labelOf($c)])->all())
            ->getOptionLabelUsing(static fn ($value): ?string => ($c = Consultant::find($value)) ? $labelOf($c) : null)
            ->query(static fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where($column, (int) $data['value']) : $query);
    }
}
