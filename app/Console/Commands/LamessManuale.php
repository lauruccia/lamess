<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Ricostruisce il dossier HTML unico dai manuali Markdown in docs/. */
final class LamessManuale extends Command
{
    protected $signature = 'lamess:manuale {--out=docs/dossier.html}';

    protected $description = 'Genera il dossier HTML dei manuali (utente, admin, sviluppatori, specifica)';

    private const FILES = [
        'manuale-utente' => 'Manuale utente',
        'manuale-admin' => 'Manuale amministratore',
        'manuale-sviluppatori' => 'Manuale sviluppatori',
        'installazione-cpanel' => 'Installazione su cPanel',
        'messa-online' => 'Messa online e piano di ritorno',
        'guida-breve-admin' => 'Guida breve per l\'amministratore',
        'specifica-motore' => 'Specifica del motore',
        'architettura' => 'Architettura',
        'decisioni-aperte' => 'Decisioni aperte',
        'parametri' => 'Parametri',
        'registro-aggiornamenti' => 'Registro aggiornamenti',
    ];

    public function handle(): int
    {
        $toc = '';
        $body = '';
        foreach (self::FILES as $slug => $title) {
            $md = (string) file_get_contents(base_path("docs/{$slug}.md"));
            $toc .= "<li><a href=\"#{$slug}\">{$title}</a></li>";
            $body .= "<section id=\"{$slug}\">".Str::markdown($md, ['html_input' => 'strip'])."</section>\n";
        }
        $html = '<!doctype html><html lang="it"><head><meta charset="utf-8"><title>Lamèss — Dossier</title>'
            .'<style>body{font:15px/1.5 system-ui;max-width:900px;margin:2rem auto;padding:0 1rem}table{border-collapse:collapse;width:100%}'
            .'td,th{border:1px solid #ccc;padding:4px 8px;font-size:13px}code{background:#f3f3f3;padding:0 3px}section{margin-top:3rem;border-top:2px solid #333}</style></head><body>'
            .'<h1>Lamèss — Dossier</h1><p>Generato il '.date('d/m/Y H:i')."</p><ul>{$toc}</ul>{$body}</body></html>";
        file_put_contents(base_path((string) $this->option('out')), $html);
        $this->info('Dossier generato: '.$this->option('out'));

        return self::SUCCESS;
    }
}
