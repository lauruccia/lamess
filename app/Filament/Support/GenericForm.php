<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Modulo di modifica generato dalla struttura della tabella: serve al super_admin per correggere a mano i dati
 * delle schermate che non hanno un modulo proprio. Non mostra chiavi, timestamp automatici e campi nascosti
 * del modello (password, token), così un salvataggio non può sovrascriverli.
 */
final class GenericForm
{
    private const SKIP = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'password'];

    /**
     * @param  list<string>  $exclude  colonne da non mostrare
     * @return list<\Filament\Schemas\Components\Component>
     */
    public static function fields(Model $model, array $exclude = []): array
    {
        $casts = $model->getCasts();
        $hidden = $model->getHidden();
        $fields = [];
        foreach (Schema::getColumns($model->getTable()) as $col) {
            $name = $col['name'];
            if (in_array($name, [...self::SKIP, ...$hidden, ...$exclude], true) || str_contains($name, 'secret')) {
                continue;
            }
            $type = strtolower((string) $col['type_name']);
            $cast = strtolower((string) ($casts[$name] ?? ''));
            $label = Str::headline($name);
            $required = ! $col['nullable'] && $col['default'] === null && ! $col['auto_increment'];

            $isBool = str_contains($cast, 'bool') || $type === 'bool' || $type === 'boolean' || ($type === 'tinyint' && str_contains((string) $col['type'], '(1)'));
            $isJson = in_array($cast, ['array', 'json', 'collection', 'object'], true) || $type === 'json';

            $field = match (true) {
                $isBool => Toggle::make($name),
                $isJson => self::json($name),
                str_contains($cast, 'datetime') || in_array($type, ['datetime', 'timestamp'], true) => DateTimePicker::make($name)->seconds(false),
                str_contains($cast, 'date') || $type === 'date' => DatePicker::make($name),
                in_array($type, ['text', 'longtext', 'mediumtext', 'tinytext'], true) => Textarea::make($name)->rows(4)->columnSpanFull(),
                in_array($type, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint'], true) => TextInput::make($name)->numeric()->integer(),
                in_array($type, ['decimal', 'float', 'double', 'real', 'numeric'], true) => TextInput::make($name)->numeric(),
                default => TextInput::make($name)->maxLength(self::length((string) $col['type'])),
            };
            $fields[] = $field->label($label)->required($required && ! $isBool);
        }

        return $fields;
    }

    private static function json(string $name): Textarea
    {
        return Textarea::make($name)->rows(8)->columnSpanFull()->rule('json')
            ->formatStateUsing(fn ($state) => is_array($state) || is_object($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $state)
            ->dehydrateStateUsing(fn ($state) => blank($state) ? null : (is_string($state) ? json_decode($state, true) : $state));
    }

    private static function length(string $type): ?int
    {
        return preg_match('/\((\d+)\)/', $type, $m) === 1 ? (int) $m[1] : null;
    }
}
