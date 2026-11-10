<?php

declare(strict_types=1);

namespace App\Domain\I18n;

use App\Models\Language;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Elenco delle lingue del sito (dal database, con cache). Se la tabella non esiste ancora (migrazione non applicata
 * a mano sul server) il sito resta solo in italiano e non si rompe.
 */
final class Locales
{
    private const LANGUAGES_KEY = 'i18n.languages.v1';

    /** @return list<array{code: string, name: string, native_name: string, flag: ?string, status: string, is_default: bool, sort: int}> */
    public static function all(): array
    {
        try {
            return Cache::rememberForever(self::LANGUAGES_KEY, function (): array {
                if (! Schema::hasTable('languages')) {
                    return self::builtIn();
                }
                $rows = Language::query()->orderBy('sort')->orderBy('code')->get()
                    ->map(fn (Language $l) => [
                        'code' => $l->code, 'name' => $l->name, 'native_name' => $l->native_name, 'flag' => $l->flag,
                        'status' => $l->status, 'is_default' => $l->is_default, 'sort' => $l->sort,
                    ])->all();

                return $rows === [] ? self::builtIn() : $rows;
            });
        } catch (\Throwable) {
            return self::builtIn();
        }
    }

    /** @return list<array{code: string, name: string, native_name: string, flag: ?string, status: string, is_default: bool, sort: int}> */
    private static function builtIn(): array
    {
        return [['code' => self::default(), 'name' => 'Italian', 'native_name' => 'Italiano', 'flag' => 'it', 'status' => Language::ACTIVE, 'is_default' => true, 'sort' => 1]];
    }

    public static function default(): string
    {
        return (string) config('i18n.default', 'it');
    }

    /** @return list<array{code: string, name: string, native_name: string, flag: ?string, status: string, is_default: bool, sort: int}> */
    public static function active(): array
    {
        return array_values(array_filter(self::all(), fn (array $l) => $l['status'] === Language::ACTIVE));
    }

    /** @return list<string> */
    public static function activeCodes(): array
    {
        return array_column(self::active(), 'code');
    }

    /** @return list<string> */
    public static function allCodes(): array
    {
        return array_column(self::all(), 'code');
    }

    public static function isActive(string $code): bool
    {
        return in_array($code, self::activeCodes(), true) || $code === self::default();
    }

    /** @return array{code: string, name: string, native_name: string, flag: ?string, status: string, is_default: bool, sort: int}|null */
    public static function find(string $code): ?array
    {
        foreach (self::all() as $l) {
            if ($l['code'] === $code) {
                return $l;
            }
        }

        return null;
    }

    public static function current(): string
    {
        return app()->getLocale();
    }

    public static function isDefault(?string $code = null): bool
    {
        return ($code ?? self::current()) === self::default();
    }

    /** Percorso (con prefisso di lingua) di un percorso pubblico: «/negozio» in inglese → «/en/negozio». */
    public static function url(string $path, ?string $code = null): string
    {
        $code ??= self::current();
        $path = '/'.ltrim($path, '/');
        if ($code === self::default() || ! self::isPublicPath($path)) {
            return $path;
        }

        return '/'.$code.($path === '/' ? '' : $path);
    }

    /** Il primo segmento è una pagina pubblica che accetta il prefisso (la home «/» compresa). */
    public static function isPublicPath(string $path): bool
    {
        $first = explode('/', trim(parse_url($path, PHP_URL_PATH) ?: '/', '/'))[0];

        return $first === '' || in_array($first, (array) config('i18n.public_paths', []), true);
    }

    public static function forgetLanguages(): void
    {
        Cache::forget(self::LANGUAGES_KEY);
    }

    public static function forgetStrings(?string $locale = null): void
    {
        foreach ($locale !== null ? [$locale] : self::allCodes() as $code) {
            Cache::forget('i18n.strings.'.$code);
        }
    }
}
