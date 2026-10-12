<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Engine\Policy\PolicySpecs;
use Illuminate\Console\Command;

/** Genera la tabella dei parametri configurabili del motore (fonte unica: registro + piano). */
final class LamessParametri extends Command
{
    protected $signature = 'lamess:parametri {--md : Stampa in Markdown} {--write= : Scrive il Markdown nel file indicato}';

    protected $description = 'Elenca politiche (D01–D12) e impostazioni numeriche del piano Lamèss';

    public function handle(): int
    {
        $plan = PlanLoader::prelaunch();
        $md = $this->markdown($plan);
        if ($path = $this->option('write')) {
            file_put_contents((string) $path, $md);
            $this->info("Scritto {$path}");

            return self::SUCCESS;
        }
        if ($this->option('md')) {
            $this->line($md);

            return self::SUCCESS;
        }
        $rows = [];
        foreach (PolicySpecs::all() as $name => $s) {
            $rows[] = [$s['decision'], $name, (string) var_export($s['default'], true), $s['default_status']];
        }
        $this->table(['Decisione', 'Politica', 'Default', 'Stato'], $rows);

        return self::SUCCESS;
    }

    private function markdown(PlanConfig $plan): string
    {
        $out = "# Parametri del motore\n\n> Generato da `php artisan lamess:parametri --write=...`. Non modificare a mano.\n\n";
        $out .= "## Impostazioni numeriche\n\n| Chiave | Descrizione | Default | Fonte |\n|---|---|---|---|\n";
        foreach (PlanConfig::SETTINGS as $k => $v) {
            $out .= "| `{$k}` | {$v[0]} | `{$v[1]}` | {$v[2]} |\n";
        }
        $out .= "\n## Politiche\n\n| Decisione | Politica | Default | Stato | Opzioni | Fonte |\n|---|---|---|---|---|---|\n";
        foreach (PolicySpecs::all() as $name => $s) {
            $def = is_bool($s['default']) ? ($s['default'] ? 'sì' : 'no') : "`{$s['default']}`";
            $opts = $s['options'] === [] ? 'sì/no' : implode('; ', array_map(fn ($k, $v) => "`{$k}` = {$v}", array_keys($s['options']), $s['options']));
            $out .= "| {$s['decision']} | `{$name}` — {$s['label']} | {$def} | {$s['default_status']} | {$opts} | {$s['source']} |\n";
        }

        return $out;
    }
}
