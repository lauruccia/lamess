<?php

declare(strict_types=1);

namespace App\Domain\I18n;

use Illuminate\Support\Facades\File;

/**
 * Cerca nel codice i testi da tradurre: ogni `__('Testo italiano')` (e `@lang('…')`) nelle viste del sito e nel codice
 * dell'applicazione. Il back-office, le email dei pacchetti e i PDF fiscali restano in italiano e non si scandiscono.
 */
class StringScanner
{
    /** @var list<string> cartelle di viste che non si traducono */
    private const SKIP_VIEWS = ['filament', 'vendor', 'pdf'];

    /** @var list<string> cartelle del codice che non contengono testi per il pubblico */
    private const SKIP_APP = ['Filament', 'Console', 'Domain/I18n'];

    /** @return array<string, list<string>> testo italiano → file in cui compare (percorsi relativi al progetto) */
    public function scan(): array
    {
        $found = [];
        foreach ($this->files() as $path) {
            foreach ($this->stringsIn((string) file_get_contents($path)) as $text) {
                $found[$text][] = ltrim(str_replace(base_path(), '', $path), '/\\');
            }
        }
        ksort($found);

        return array_map(fn (array $files) => array_values(array_unique($files)), $found);
    }

    /** @return list<string> */
    public function stringsIn(string $code): array
    {
        $out = [];
        if (preg_match_all('/(?<![\w$])(?<!->)(?<!::)(?:__|trans|@lang)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")\s*[,)]/su', $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $text = isset($hit[2]) && $hit[2] !== '' ? stripcslashes($hit[2]) : str_replace(["\\'", '\\\\'], ["'", '\\'], $hit[1] ?? '');
                if ($this->isTranslatable($text)) {
                    $out[] = $text;
                }
            }
        }

        return $out;
    }

    /** Una chiave di gruppo («auth.failed») non è un testo italiano. */
    private function isTranslatable(string $text): bool
    {
        $text = trim($text);

        return $text !== '' && ! preg_match('/^[a-z_]+(\.[a-z_]+)+$/i', $text);
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = [];
        foreach (File::allFiles(resource_path('views')) as $f) {
            $rel = str_replace('\\', '/', $f->getRelativePathname());
            if (! str_ends_with($rel, '.php') || in_array(explode('/', $rel)[0], self::SKIP_VIEWS, true)) {
                continue;
            }
            $files[] = $f->getPathname();
        }
        foreach (File::allFiles(app_path()) as $f) {
            $rel = str_replace('\\', '/', $f->getRelativePathname());
            if (! str_ends_with($rel, '.php')) {
                continue;
            }
            foreach (self::SKIP_APP as $skip) {
                if (str_starts_with($rel, $skip.'/')) {
                    continue 2;
                }
            }
            $files[] = $f->getPathname();
        }

        return $files;
    }
}
