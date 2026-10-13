<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Product;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Modulo di modifica generato dalla struttura della tabella: serve al super_admin per correggere a mano i dati
 * delle schermate che non hanno un modulo proprio. Non mostra chiavi, timestamp automatici e campi nascosti
 * del modello (password, token), così un salvataggio non può sovrascriverli.
 *
 * Per rendere il modulo leggibile a chi non conosce il database:
 *  - le etichette sono in italiano (GenericFormVocabulary);
 *  - i riferimenti ad altri record (utente, incaricato, ordine, prodotto…) sono menu con ricerca, non numeri;
 *  - stati e tipi noti sono menu a scelta con il testo italiano; per gli altri si suggeriscono i valori già in uso;
 *  - i campi JSON diventano tabella chiave/valore, elenco di etichette o righe prodotto/quantità; solo le strutture
 *    complesse restano come testo JSON.
 */
final class GenericForm
{
    private const SKIP = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'password'];

    private const INTEGER_TYPES = ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint'];

    private const DECIMAL_TYPES = ['decimal', 'float', 'double', 'real', 'numeric'];

    /** I fusi orari: le date sono salvate in UTC, qui si mostrano e si modificano all'ora italiana. */
    private const TIMEZONE = 'Europe/Rome';

    /** Colonne JSON con una forma nota: "tabella.colonna" => forma. */
    private const SHAPES = [
        'subscriptions.lines' => 'quantities',
        'subscriptions.for_others' => 'quantities_by_user',
    ];

    /**
     * @param  list<string>  $exclude  colonne da non mostrare
     * @return list<\Filament\Schemas\Components\Component>
     */
    public static function fields(Model $model, array $exclude = []): array
    {
        $casts = $model->getCasts();
        $table = $model->getTable();
        $skip = [...self::SKIP, ...$model->getHidden(), ...$exclude];
        $fields = [];
        foreach (Schema::getColumns($table) as $col) {
            $name = $col['name'];
            if (self::isSkipped($name, $skip)) {
                continue;
            }
            $type = strtolower((string) $col['type_name']);
            $cast = strtolower((string) ($casts[$name] ?? ''));
            $nullable = (bool) $col['nullable'];
            $required = ! $nullable && $col['default'] === null && ! $col['auto_increment'];

            $isBool = str_contains($cast, 'bool') || $type === 'bool' || $type === 'boolean' || ($type === 'tinyint' && str_contains((string) $col['type'], '(1)'));
            $isJson = in_array($cast, ['array', 'json', 'collection', 'object'], true) || $type === 'json';
            $isInteger = in_array($type, self::INTEGER_TYPES, true);
            $isDecimal = in_array($type, self::DECIMAL_TYPES, true);
            $isText = in_array($type, ['text', 'longtext', 'mediumtext', 'tinytext'], true);
            $relation = ! $isBool && ! $isJson ? GenericFormVocabulary::relation($name, $model, $isInteger) : null;
            $options = ! $isBool && ! $isJson && $relation === null ? GenericFormVocabulary::options($table, $name) : null;

            $field = match (true) {
                isset(self::SHAPES[$table.'.'.$name]) => self::shaped(self::SHAPES[$table.'.'.$name], $name, $required),
                $isBool => Toggle::make($name),
                $isJson => self::json($name, $model->getAttribute($name), $nullable),
                $relation !== null => self::relationSelect($name, $relation),
                $options !== null => self::optionSelect($name, $options),
                str_contains($cast, 'datetime') || in_array($type, ['datetime', 'timestamp'], true) => DateTimePicker::make($name)->seconds(false)->timezone(self::TIMEZONE),
                str_contains($cast, 'date') || $type === 'date' => DatePicker::make($name),
                $isText => Textarea::make($name)->rows(4)->columnSpanFull(),
                $isInteger => self::number($name, true),
                $isDecimal => self::number($name, false),
                GenericFormVocabulary::isSuggestible($name) => TextInput::make($name)->maxLength(self::length((string) $col['type']))->datalist(self::knownValues($table, $name)),
                default => TextInput::make($name)->maxLength(self::length((string) $col['type'])),
            };
            $fields[] = $field->label(GenericFormVocabulary::label($name))->required($required && ! $isBool);
        }

        return $fields;
    }

    /**
     * Prepara i dati letti dal record per il modulo: le colonne con forma nota diventano righe da modificare.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toForm(Model $model, array $data): array
    {
        foreach (self::SHAPES as $key => $shape) {
            [$table, $column] = explode('.', $key, 2);
            if ($table !== $model->getTable() || ! array_key_exists($column, $data)) {
                continue;
            }
            $data[$column] = match ($shape) {
                'quantities' => self::quantityRows($data[$column]),
                default => self::recipientRows($data[$column]),
            };
        }

        return $data;
    }

    /**
     * Riporta nella forma del database i dati modificati nel modulo (l'inverso di toForm).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fromForm(Model $model, array $data): array
    {
        foreach (self::SHAPES as $key => $shape) {
            [$table, $column] = explode('.', $key, 2);
            if ($table !== $model->getTable() || ! array_key_exists($column, $data)) {
                continue;
            }
            if ($shape === 'quantities') {
                $data[$column] = self::quantityMap($data[$column]);

                continue;
            }
            $map = self::recipientMap($data[$column]);
            $data[$column] = $map === [] ? null : $map;
        }

        return $data;
    }

    private static function isSkipped(string $name, array $skip): bool
    {
        return in_array($name, $skip, true)
            || str_contains($name, 'secret') || str_contains($name, 'token') || str_contains($name, 'recovery_codes')
            || $name === 'hash' || str_ends_with($name, '_hash');
    }

    /** Menu con ricerca per scegliere il record collegato (utente, incaricato, ordine…) invece di scrivere un numero. */
    private static function relationSelect(string $name, array $spec): Select
    {
        [$class, $columns, $separator] = $spec;

        return Select::make($name)
            ->searchable()
            ->searchPrompt('Scrivi per cercare…')
            ->noSearchResultsMessage('Nessun risultato')
            ->getSearchResultsUsing(fn (string $search): array => self::searchResults($class, $columns, $separator, $search))
            ->getOptionLabelUsing(fn ($value): ?string => self::optionLabel($class, $columns, $separator, $value));
    }

    /** @return array<int|string, string> */
    private static function searchResults(string $class, array $columns, string $separator, string $search): array
    {
        $query = $class::query()->orderByDesc('id')->limit(50);
        $search = trim($search);
        if ($search !== '') {
            $query->where(function ($q) use ($columns, $search): void {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', '%'.$search.'%');
                }
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        return $query->get()->mapWithKeys(fn (Model $m) => [$m->getKey() => self::display($m, $columns, $separator)])->all();
    }

    private static function optionLabel(string $class, array $columns, string $separator, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $record = $class::query()->find($value);

        return $record instanceof Model ? self::display($record, $columns, $separator) : '#'.$value;
    }

    private static function display(Model $record, array $columns, string $separator): string
    {
        $parts = [];
        foreach ($columns as $column) {
            $value = trim((string) $record->getAttribute($column));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $parts === [] ? '#'.$record->getKey() : implode($separator, $parts);
    }

    /** Menu a scelta con i testi italiani; un valore già salvato e non previsto resta selezionabile, così non si perde. */
    private static function optionSelect(string $name, array $options): Select
    {
        return Select::make($name)->native(false)
            ->options(function (?Model $record) use ($name, $options): array {
                $current = $record?->getAttribute($name);
                if (is_string($current) && $current !== '' && ! array_key_exists($current, $options)) {
                    $options[$current] = GenericFormVocabulary::optionLabel($current, $options);
                }

                return $options;
            });
    }

    /** Valori già in uso nella colonna, mostrati come suggerimenti: il campo resta libero. */
    private static function knownValues(string $table, string $column): array
    {
        try {
            return DB::table($table)->whereNotNull($column)->where($column, '!=', '')->distinct()->orderBy($column)->limit(25)->pluck($column)->map(fn ($v) => (string) $v)->all();
        } catch (Throwable) {
            return [];
        }
    }

    private static function number(string $name, bool $integer): TextInput
    {
        $field = TextInput::make($name)->numeric();
        if ($integer) {
            $field->integer();
        }
        $suffix = GenericFormVocabulary::numberSuffix($name);

        return $suffix === null ? $field : $field->suffix($suffix);
    }

    /**
     * Campo JSON: chiave/valore o elenco di etichette quando sono solo testi (così non cambiano di tipo salvando),
     * altrimenti testo JSON da modificare con attenzione.
     */
    private static function json(string $name, mixed $value, bool $nullable): TagsInput|KeyValue|Textarea
    {
        $empty = $nullable ? null : [];
        if (is_array($value) && $value !== [] && self::onlyStrings($value)) {
            $field = array_is_list($value)
                ? TagsInput::make($name)->columnSpanFull()
                : KeyValue::make($name)->keyLabel('Voce')->valueLabel('Valore')->addActionLabel('Aggiungi una riga')->reorderable(false)->columnSpanFull();

            return $field->dehydrateStateUsing(fn ($state) => blank($state) ? $empty : $state);
        }

        return Textarea::make($name)->rows(8)->columnSpanFull()->rule('json')
            ->helperText('Dati strutturati in formato JSON: modificali solo se sai cosa stai cambiando.')
            ->formatStateUsing(fn ($state) => is_array($state) || is_object($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $state)
            ->dehydrateStateUsing(fn ($state) => blank($state) ? null : (is_string($state) ? json_decode($state, true) : $state));
    }

    private static function onlyStrings(array $values): bool
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /** Colonne JSON con forma nota: righe "prodotto + quantità" (e, per gli ordini multipli, il destinatario). */
    private static function shaped(string $shape, string $name, bool $required): Repeater
    {
        if ($shape === 'quantities') {
            $field = Repeater::make($name)->schema(self::quantityFields())->addActionLabel('Aggiungi un prodotto');
        } else {
            $field = Repeater::make($name)->addActionLabel('Aggiungi un destinatario')->schema([
                self::relationSelect('user_id', GenericFormVocabulary::relation('user_id', new User, true))->label('Destinatario')->required(),
                Repeater::make('lines')->label('Prodotti')->schema(self::quantityFields())->columns(2)->columnSpanFull()->defaultItems(0)->addActionLabel('Aggiungi un prodotto')->minItems(1),
            ]);
        }

        return $field->columns(2)->columnSpanFull()->defaultItems(0)->minItems($required ? 1 : 0);
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    private static function quantityFields(): array
    {
        return [
            self::relationSelect('product_id', GenericFormVocabulary::relation('product_id', new Product, true))->label('Prodotto')->required(),
            TextInput::make('qty')->label('Quantità')->numeric()->integer()->minValue(1)->default(1)->required(),
        ];
    }

    /** @return list<array{product_id: int, qty: int}> */
    private static function quantityRows(mixed $map): array
    {
        $rows = [];
        foreach ((array) $map as $productId => $qty) {
            $rows[] = ['product_id' => (int) $productId, 'qty' => (int) $qty];
        }

        return $rows;
    }

    /** @return array<int, int> product_id => quantità */
    private static function quantityMap(mixed $rows): array
    {
        $map = [];
        foreach ((array) $rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $qty = (int) ($row['qty'] ?? 0);
            if ($productId > 0 && $qty > 0) {
                $map[$productId] = ($map[$productId] ?? 0) + $qty;
            }
        }

        return $map;
    }

    /** @return list<array{user_id: int, lines: list<array{product_id: int, qty: int}>}> */
    private static function recipientRows(mixed $map): array
    {
        $rows = [];
        foreach ((array) $map as $userId => $lines) {
            $rows[] = ['user_id' => (int) $userId, 'lines' => self::quantityRows($lines)];
        }

        return $rows;
    }

    /** @return array<int, array<int, int>> user_id destinatario => [product_id => quantità] */
    private static function recipientMap(mixed $rows): array
    {
        $map = [];
        foreach ((array) $rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            $lines = self::quantityMap($row['lines'] ?? []);
            if ($userId > 0 && $lines !== []) {
                foreach ($lines as $productId => $qty) {
                    $map[$userId][$productId] = ($map[$userId][$productId] ?? 0) + $qty;
                }
            }
        }

        return $map;
    }

    private static function length(string $type): ?int
    {
        return preg_match('/\((\d+)\)/', $type, $m) === 1 ? (int) $m[1] : null;
    }
}
