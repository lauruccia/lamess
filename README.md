# Lamèss — Portale

Portale Lamèss: negozio, rete degli Incaricati, motore di calcolo dei compensi, pagamenti (carta con Stripe, bonifico, KMoney), abbonamenti, fatture elettroniche, area riservata e back-office. Laravel 13, Filament 4, PHP 8.3.
Stato e cronologia: `docs/registro-aggiornamenti.md`. Questioni ancora aperte: `docs/decisioni-aperte.md`.

## Requisiti
- PHP 8.3 o superiore (estensioni: `mbstring, xml, curl, zip, gd, intl, fileinfo, openssl`, più `pdo_sqlite` in sviluppo o `pdo_mysql` in produzione) e Composer.
- Sviluppo: SQLite (predefinito in `.env.example`). Produzione: MySQL/MariaDB (cPanel).
- Node non serve: sito e back-office usano fogli di stile statici in `public/css`, senza compilazione.

## Installazione in sviluppo
```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan lamess:ruoli
php artisan db:seed --class=PrelaunchPlanSeeder
php artisan lamess:superadmin tua@email.it --name="Il tuo nome"
php artisan serve        # sito su http://localhost:8000, back-office su /admin
```
`.env.example` lascia il sito aperto (`LAMESS_SITE_OPEN=true`), le email bloccate (`LAMESS_MAIL_ENABLED=false`) e i driver di pagamento, KMoney e firma in modalità di prova (`fake`). `DemoSeeder` crea dati di fantasia: solo per sviluppo.

## Test
```bash
php artisan test --compact                 # suite completa (SQLite in memoria)
php artisan test --compact --filter=Nome   # un test o una classe
vendor/bin/pint --format agent             # stile del codice
```
Il motore di calcolo ha test propri in `tests/Unit/Engine`. La suite non è mai stata provata del tutto su MySQL/MariaDB: vedi `docs/phpunit.maria.xml.esempio`. Nell'ultima esecuzione registrata (16/10/2026) risultavano rossi, già prima delle ultime modifiche, `ShopAdminTest::test_product_can_be_created_with_automatic_pv`, `IncaricatiCareerProgressTest` e `SubscriptionsAndMultiOrdersTest::test_customer_area_pages_and_actions`; l'elenco aggiornato dei test rossi sta in `docs/registro-aggiornamenti.md` e in `docs/decisioni-aperte.md` (A-03).

## Distribuzione (produzione su cPanel, senza terminale)
Il sito live è su cPanel con database MySQL e **senza terminale**. Il codice sta nel repository GitHub privato `lauruccia/lamess` (branch `main`), collegato a cPanel → Git Version Control: l'aggiornamento è **Update from Remote** poi **Deploy HEAD Commit** (lo esegue `.cpanel.yml`). Dopo ogni deploy si svuota la cache cancellando da File Manager i file in `bootstrap/cache` (tranne `.gitignore`) e `storage/framework/views`; le migrazioni nuove non girano da sole e si applicano da phpMyAdmin. Il file `.env` non è nel repository e non va condiviso. Il portale resta **chiuso al pubblico** (pagina «Lamèss apre presto») finché non si accende «Sito aperto al pubblico» in Impostazioni di sistema → Sito.
Procedure complete: `docs/messa-online.md` (aggiornamenti, migrazioni, apertura, KMoney, piano di ritorno) e `docs/installazione-cpanel.md` (prima installazione, variabili del `.env`, cron). In alternativa al Git, `php tools/crea-pacchetto-cpanel.php` prepara un pacchetto ZIP.

## Documentazione (`docs/`)
| File | Per chi |
|---|---|
| `manuale-utente.md` | Incaricati e clienti |
| `manuale-admin.md` | Amministratori / back-office |
| `guida-breve-admin.md` | Guida di una pagina per chi gestisce il portale |
| `manuale-sviluppatori.md` | Sviluppatori |
| `installazione-cpanel.md` | Installazione e configurazione su cPanel |
| `messa-online.md` | Aggiornamenti, apertura al pubblico, controlli e piano di ritorno |
| `specifica-motore.md` | Regole di calcolo implementate |
| `architettura.md` | Struttura del codice e scelte tecniche |
| `parametri.md` | Parametri del motore (generato da `php artisan lamess:parametri --write=docs/parametri.md`) |
| `scheda-consulente.md` | Scheda incaricato 360° |
| `decisioni-aperte.md` | Decisioni, default adottati e questioni aperte |
| `registro-aggiornamenti.md` | Cronologia di ogni passo |
| `dossier.html` | Tutti i manuali in un solo file, generato con `php artisan lamess:manuale` (da rigenerare dopo ogni modifica ai manuali) |

## Principi
- Ogni parametro del piano è un dato (file JSON del piano in `config/lamess/`), mai una costante nel codice.
- Importi sempre `BigDecimal`, mai `float`. Prezzi IVA inclusa.
- Il motore non accede al database: riceve uno snapshot, restituisce risultato e spiegazioni.
- Ogni azione importante è scritta nel log di audit, protetto da catena di hash.
- Nessun segreto nel repository: chiavi e token vivono solo nel `.env` del server.
