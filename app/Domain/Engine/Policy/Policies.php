<?php

declare(strict_types=1);

namespace App\Domain\Engine\Policy;

use InvalidArgumentException;

/**
 * Valori delle politiche di un piano. Immutabile; i valori mancanti prendono il default
 * del registro {@see PolicySpecs}.
 */
final class Policies
{
    /** @var array<string, string|bool> */
    private array $values;

    /** @param array<string, string|bool> $overrides */
    public function __construct(array $overrides = [])
    {
        $specs = PolicySpecs::all();
        $unknown = array_diff(array_keys($overrides), array_keys($specs));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Politiche sconosciute: '.implode(', ', $unknown));
        }
        $this->values = array_replace(PolicySpecs::defaults(), $overrides);
        foreach ($specs as $name => $spec) {
            if ($spec['options'] !== [] && ! array_key_exists((string) $this->values[$name], $spec['options'])) {
                throw new InvalidArgumentException("Valore '{$this->values[$name]}' non ammesso per {$name}");
            }
        }
    }

    /** @param array<string, string|bool>|null $data */
    public static function fromArray(?array $data): self
    {
        return new self($data ?? []);
    }

    public function get(string $name): string|bool
    {
        if (! array_key_exists($name, $this->values)) {
            throw new InvalidArgumentException("Politica sconosciuta: {$name}");
        }

        return $this->values[$name];
    }

    public function is(string $name, string $value): bool
    {
        return $this->get($name) === $value;
    }

    public function flag(string $name): bool
    {
        return (bool) $this->get($name);
    }

    /** @return array<string, string|bool> */
    public function toArray(): array
    {
        return $this->values;
    }

    /** @param array<string, string|bool> $overrides */
    public function with(array $overrides): self
    {
        return new self(array_replace($this->values, $overrides));
    }
}
