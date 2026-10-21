<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/** Salva un modello e restituisce i campi cambiati, con il nome in italiano, per il log di audit. */
trait SavesWithAuditDiff
{
    /**
     * @param  array<string, string>  $labels
     * @return array<string, array{prima: mixed, dopo: mixed}>
     */
    private function saveWithDiff(Model $model, array $labels): array
    {
        $changes = [];
        foreach ($model->getDirty() as $field => $new) {
            $changes[$labels[$field] ?? $field] = ['prima' => $this->plain($model->getOriginal($field)), 'dopo' => $this->plain($model->getAttribute($field))];
        }
        if ($model->isDirty() || ! $model->exists) {
            $model->save();
        }

        return $changes;
    }

    private function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i'),
            is_bool($value) => $value ? 'sì' : 'no',
            default => $value,
        };
    }
}
