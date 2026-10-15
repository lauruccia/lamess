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

        $out .= $this->incaricati();

        return $out;
    }

    /** Frazione (0,15) come punti percentuali leggibili (15). */
    private function pct(mixed $fraction): string
    {
        return rtrim(rtrim(number_format((float) (string) $fraction * 100, 3, ',', ''), '0'), ',');
    }

    /** Parametri del Piano Incaricati (motore kosmos_v1), letti dal file di piano. */
    private function incaricati(): string
    {
        $plan = PlanLoader::incaricati();
        $k = $plan->kosmos;
        if ($k === null) {
            return '';
        }
        $out = "\n## Piano Incaricati (motore `kosmos_v1`)\n\nValori del file `config/lamess/plan-incaricati-2026-10-08.json`; tutti modificabili da Compensi → Piano compensi.\n\n";
        $out .= "| Parametro | Valore |\n|---|---|\n";
        $out .= "| Quota V.A.P. | {$this->pct($k->vapShare)}% dell'importo IVA inclusa (ogni prodotto può avere un V.A.P. proprio) |\n";
        $out .= "| Chi è un cliente | `{$k->clientRule}`".($k->clientRule === 'min_amount' ? ", ordine singolo di almeno {$k->clientMinAmount} €" : '')." |\n";
        $out .= '| Scaglioni dei diretti | '.implode(' · ', array_map(fn ($t) => "da {$t['min_clients']} clienti → ".$this->pct($t['rate']).'%', $k->directTiers))." |\n";
        $out .= '| Indiretti | '.implode(' · ', array_map(fn ($l) => "L{$l['level']} ".$this->pct($l['rate'])."% ({$l['min_clients']} clienti, {$l['min_pearl']} Pearl)", $k->indirectLevels))." |\n";
        $out .= '| Indiretti estesi | '.implode(' · ', array_map(fn ($r, $v) => "{$r} ".$this->pct($v).'%', array_keys($k->extendedRates), $k->extendedRates))." — stop al {$k->extendedStopLevels}° livello |\n";
        $out .= "| Bonus di Struttura | {$k->structureClients} clienti entro {$k->structureWindowDays} giorni; importi per qualifica nel piano |\n";
        $out .= "\nPolitiche del piano: vedi le righe K01–K06 della tabella precedente.\n";

        return $out;
    }
}
