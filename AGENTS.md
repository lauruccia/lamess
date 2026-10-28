# Lamèss: istruzioni per gli assistenti di codice

La guida del progetto e le regole di Laravel Boost stanno in `CLAUDE.md`: leggilo per primo. Il portale è Laravel 13 + Filament 4 (PHP ^8.3); il front-end non si compila.

- Documentazione: `docs/` (parti da `manuale-sviluppatori.md`; cronologia in `registro-aggiornamenti.md`).
- Test: `php artisan test --compact`; stile: `vendor/bin/pint --format agent`.
- Il sito live non ha terminale: le migrazioni nuove si applicano a mano (SQL nel registro, vedi `docs/messa-online.md`).
- A fine lavoro commit e push su `main`; il deploy lo fa Laura. Non scrivere segreti nel repository.
