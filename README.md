# Lamèss — Portale multilevel (motore di calcolo)

Portale di network marketing Lamèss, in Laravel 13 / PHP 8.3.
Stato corrente (5 ottobre 2026): motore di calcolo, rete, negozio, pagamenti, assistenza, wallet, contenuti, conformità, cessione/successione e driver reali (Stripe, fatture FatturaPA, verifica identità) completi e testati (451 test). Aperti: collegamento diretto a GestionaleK, firma elettronica reale, rinnovi automatici con Stripe.

## Avvio rapido

```bash
composer install
cp .env.example .env && php artisan key:generate
php vendor/bin/phpunit tests/Unit/Engine     # test del motore
php artisan lamess:parametri                 # parametri configurabili
php artisan lamess:parametri --write=docs/parametri.md
```

## Documentazione (`docs/`)

| File | Per chi |
|---|---|
| `manuale-utente.md` | Consulenti Lamèss |
| `manuale-admin.md` | Amministratori / back office |
| `manuale-sviluppatori.md` | Sviluppatori |
| `installazione-cpanel.md` | Installazione su cPanel, anche senza terminale |
| `messa-online.md` | Controlli, migrazione e piano di ritorno |
| `guida-breve-admin.md` | Guida di una pagina per chi gestisce il portale |
| `specifica-motore.md` | Regole di calcolo implementate (S1–S13) |
| `architettura.md` | Struttura del codice e scelte tecniche |
| `decisioni-aperte.md` | Decisioni D01–D12 e default adottati |

| `registro-aggiornamenti.md` | Cronologia di ogni passo |

## Principi

- Ogni parametro del piano è un dato (file JSON del piano + registro politiche), mai una costante nel codice.
- Importi e punti sono sempre `BigDecimal`, mai `float`.
- Il motore non accede al database: riceve uno snapshot, restituisce risultato e spiegazioni.
- Il Fast Start Bonus è sospeso: esiste solo la struttura configurabile, nessun calcolo.
