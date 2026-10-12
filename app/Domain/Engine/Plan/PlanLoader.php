<?php

declare(strict_types=1);

namespace App\Domain\Engine\Plan;

use RuntimeException;

/** Carica un piano da file JSON (valori ufficiali) con eventuali override delle politiche. */
final class PlanLoader
{
    public const PRELAUNCH_FILE = 'plan-prelaunch-2026-07-07.json';

    public const INCARICATI_FILE = 'plan-incaricati-2026-10-08.json';

    /** @param array<string, string|bool> $policyOverrides */
    public static function prelaunch(array $policyOverrides = [], ?string $dir = null): PlanConfig
    {
        return self::fromFile(self::dir($dir).'/'.self::PRELAUNCH_FILE, $policyOverrides);
    }

    /** @param array<string, string|bool> $policyOverrides */
    public static function incaricati(array $policyOverrides = [], ?string $dir = null): PlanConfig
    {
        return self::fromFile(self::dir($dir).'/'.self::INCARICATI_FILE, $policyOverrides);
    }

    /** @param array<string, string|bool> $policyOverrides */
    public static function fromFile(string $path, array $policyOverrides = []): PlanConfig
    {
        if (! is_file($path)) {
            throw new RuntimeException("File piano non trovato: {$path}");
        }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $data['policies'] = array_replace($data['policies'] ?? [], $policyOverrides);

        return PlanConfig::fromArray($data);
    }

    private static function dir(?string $dir): string
    {
        return $dir ?? dirname(__DIR__, 4).'/config/lamess';
    }
}
