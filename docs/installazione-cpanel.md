# Installazione su cPanel (hosting condiviso, MySQL/MariaDB)

## A. Senza terminale (percorso consigliato)
Tutto si fa con il file manager di cPanel e un browser. Il pacchetto si prepara sul PC/ambiente di sviluppo.

0. **Sul PC** serve PHP 8.3 e Composer. Lo ZIP si prepara sempre dal PC: su cPanel senza terminale **non si lancia mai `composer install`** (le librerie sono già nello ZIP). Se hai eseguito `composer install` sul PC, nessun problema: la tua cartella `vendor` locale (con gli strumenti di sviluppo, es. `laravel/boost`) serve solo per i test e **non va caricata**; lo script del punto 1 costruisce da solo un `vendor` di produzione (`--no-dev`) in una cartella a parte.
1. **Prepara lo ZIP** (una volta per ogni rilascio): `php tools/crea-pacchetto-cpanel.php --prima-installazione`
   → crea `dist/lamess-AAAAMMGG-…-prima-installazione.zip` (≈ 60 MB, già con le librerie di produzione) e genera in `tools/.segreti-cpanel.env`
   la `APP_KEY` e il token di installazione (restano gli stessi nei pacchetti successivi: **non perderli**, una APP_KEY diversa rende illeggibili i dati cifrati e il log di audit).
   Per gli aggiornamenti: stesso comando senza `--prima-installazione` (lo ZIP non contiene `.env`).
2. **Database**: cPanel → *Database MySQL*: crea database e utente, associali con tutti i privilegi.
3. **Carica e decomprimi** lo ZIP fuori dalla cartella pubblica, es. `/home/UTENTE/lamess` (file manager → *Carica* → *Estrai*).
4. **Cartella pubblica**: cPanel → *Domini* → *Document Root* del dominio = `/home/UTENTE/lamess/public`. Se non è possibile, copia il contenuto di `public/` in `public_html/` e correggi in `public_html/index.php` i due percorsi (`../lamess/vendor/autoload.php`, `../lamess/bootstrap/app.php`).
5. **`.env`**: rinomina `.env.da-compilare` in `.env` e compila dominio, database e posta (il modello è `.env.cpanel.example`: sessioni e cache su file, code `sync`). Le chiavi dei servizi esterni (carta, verifica identità, fatture) si possono aggiungere subito o in un secondo momento: vedi «Chiavi dei servizi esterni» più sotto. Finché non le metti, il portale usa i sistemi di prova e il «Controllo messa online» lo segnala.
6. **PHP**: cPanel → *Seleziona versione PHP* → 8.3 o superiore, estensioni `mbstring, xml, zip, gd, intl, pdo_mysql, fileinfo, openssl`.
7. **Installazione guidata**: apri `https://tuodominio.it/installazione`. La pagina controlla il server, chiede il token (quello in `.env`) e i dati del super_admin, poi in un solo passaggio: esegue **tutte le migrazioni** (comprese quelle dei moduli più recenti: assistenza, wallet e prelievi, ricevute, novità e materiali, conformità e sanzioni, cessioni ed eredi, verifica identità), sincronizza **ruoli e permessi**, carica il **piano compensi ufficiale** e crea il super_admin. Funziona una volta sola: si blocca da sola (file `storage/app/installed.lock`). Per questo, **se la prima installazione la fai con la versione definitiva, non serve nessun altro comando**.
8. **Subito dopo**: cancella la riga `LAMESS_INSTALL_TOKEN` dal `.env`; aggiungi in *Operazioni pianificate (Cron)* la riga mostrata a fine installazione (ogni minuto, `schedule:run`); entra in `/admin`, collega l'app 2FA e apri **Sistema → Controllo messa online** per vedere cosa manca (vedi il documento «Messa online e piano di ritorno»).
9. **Dati da compilare nel pannello** (Impostazioni): *Venditore* (ragione sociale, partita IVA, indirizzo, CAP, comune, provincia, regime fiscale: servono per la fattura elettronica), *Bonifici SEPA*, *Email*. I parametri fiscali di *Ricevute e trattenute* restano spenti finché il commercialista non li ha validati.

### Chiavi dei servizi esterni (file `.env`)
Tutte facoltative all'installazione; senza, il portale resta con i driver di prova.

| Chiave | Valori | A cosa serve |
|---|---|---|
| `LAMESS_PAYMENT_DRIVER` | `fake` (prova) · `stripe` | pagamento con carta |
| `STRIPE_SECRET` | `sk_live_…` (in prova `sk_test_…`) | chiave segreta dell'account Stripe |
| `STRIPE_WEBHOOK_SECRET` | `whsec_…` | firma delle notifiche di Stripe (senza, i pagamenti non si confermano mai) |
| `LAMESS_KYC_DRIVER` | `manual` (default) · `stripe_identity` | verifica d'identità online; usa la stessa `STRIPE_SECRET` |
| `LAMESS_INVOICE_SINK` | `fake` · `file` · `mail` | destinazione delle fatture elettroniche (XML FatturaPA) |
| `LAMESS_SDI_MAIL_TO` | indirizzo email | destinatario del driver `mail` (casella di importazione del gestionale, commercialista o PEC verso lo SdI) |
| `LAMESS_SELLER_NAME`, `_VAT`, `_ADDRESS`, `_ZIP`, `_CITY`, `_PROVINCE`, `_TAX_CODE`, `_REGIME` | testo | dati del venditore (modificabili anche da Impostazioni → Venditore) |
| `LAMESS_MAIL_ENABLED` | `false` (default) · `true` | interruttore generale delle email, comprese le fatture spedite dal driver `mail` |
| `LAMESS_TRANSFER_FEE` | `50.00` | contributo per la cessione del codice |
| `LAMESS_INHERITANCE_LIMBO_DAYS` | `180` | giorni dopo cui un codice di un consulente deceduto, senza subentro, cessa |
| `LAMESS_DISPUTE_DAYS` | `10` | giorni per reclamare su estratti conto e ricevute |
| `LAMESS_PLAN_FOUR_EYES` | `true` | la pubblicazione del piano richiede un secondo amministratore |

Molti di questi valori si cambiano anche da **Impostazioni** nel pannello (hanno la precedenza sul `.env`). Le chiavi segrete (Stripe) stanno **solo** nel `.env`.

**Stripe, una volta sola** (dashboard Stripe → Sviluppatori → Webhook): aggiungi l'endpoint `https://tuodominio.it/webhooks/stripe` e abilita gli eventi `checkout.session.completed`, `checkout.session.async_payment_succeeded` e, se usi la verifica d'identità, `identity.verification_session.verified`, `identity.verification_session.requires_input`, `identity.verification_session.canceled`. Copia il «segreto di firma» (`whsec_…`) in `STRIPE_WEBHOOK_SECRET`. La pagina `/webhooks/stripe` accetta solo chiamate firmate.
Prova prima con le chiavi `sk_test_` e le carte di prova di Stripe; il «Controllo messa online» segnala come ATTENZIONE una chiave di prova.

Il super_admin si crea **solo** dall'installazione guidata o da `php artisan lamess:superadmin`; mai dal pannello.

## B. Con terminale (alternativa)

Verificato (fino alla fase 8, 5 ottobre): migrazioni, seeder e suite di test passano su **MariaDB 10.11** (`phpunit.maria.xml`). La suite attuale (451 test, moduli 1-7 inclusi) è verificata su SQLite: **prima della messa online rilancia le migrazioni e la suite su MariaDB** (`docs/phpunit.maria.xml.esempio`).
Non verificato: la tua versione esatta di MySQL/PHP sull'hosting, la posta reale, le prestazioni con molti consulenti.

## Requisiti da controllare nel cPanel
- **PHP 8.3 o superiore** (cPanel → *Select PHP Version* / *MultiPHP Manager*), con estensioni: `mbstring, xml, curl, zip, gd, intl, pdo_mysql, fileinfo, openssl`.
- **MySQL 8 / MariaDB 10.6+**.
- Accesso **Terminale** (SSH) consigliato per `composer` e `artisan`. Senza terminale: si prepara tutto sul PC (`composer install --no-dev`) e si carica la cartella `vendor` insieme al resto.
- **Cron job** (cPanel → *Cron Jobs*): serve per canoni, fatture, abbonamenti, chiusura mensile automatica e pagamenti.

## Passi
1. **Database**: cPanel → *MySQL Databases*: crea database e utente, associali con *tutti i privilegi*. Annota nome, utente, password (di solito con prefisso `cpuser_`).
2. **Carica il progetto** fuori dalla cartella pubblica, es. `/home/UTENTE/lamess` (non dentro `public_html`).
3. **Cartella pubblica**: il contenuto di `lamess/public` deve diventare la radice del dominio. Due modi:
   - meglio: cPanel → *Domains* → imposta la *Document Root* del dominio/sottodominio su `/home/UTENTE/lamess/public`;
   - altrimenti: copia il contenuto di `public/` in `public_html/` e correggi in `public_html/index.php` i due percorsi verso `../lamess/vendor/autoload.php` e `../lamess/bootstrap/app.php`.
4. **`.env`** (copia da `.env.example`) con:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://tuodominio.it
   APP_LOCALE=it
   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=cpuser_lamess
   DB_USERNAME=cpuser_lamess
   DB_PASSWORD=...
   SESSION_DRIVER=file
   CACHE_STORE=file
   QUEUE_CONNECTION=sync
   MAIL_MAILER=smtp   # host/porta/utente/password della casella creata in cPanel
   ADMIN_2FA_REQUIRED=true
   ```
5. **Comandi** (da Terminale, nella cartella del progetto):
   ```
   composer install --no-dev --optimize-autoloader
   php artisan key:generate --force
   php artisan migrate --force
   php artisan lamess:ruoli
   php artisan db:seed --class=PrelaunchPlanSeeder --force
   php artisan lamess:superadmin tua@email.it --name="Laura"
   php artisan storage:link
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
   **Non** lanciare `DemoSeeder` in produzione.
6. **Permessi**: `storage/` e `bootstrap/cache/` scrivibili (755, o 775 se serve).
7. **Cron** ogni minuto (percorso PHP come indicato dal cPanel):
   ```
   * * * * * /usr/local/bin/php /home/UTENTE/lamess/artisan schedule:run >> /dev/null 2>&1
   ```
   Fa partire ogni notte la chiusura mensile (01:00, solo il giorno 1 del mese lavora davvero), i canoni (03:30), gli abbonamenti (04:00) e i pagamenti del mese (05:00), e ogni ora le fatture.
   **Come controllare che giri:** in `/admin`, nella dashboard, il riquadro «Operazioni automatiche» mostra «Attivo» con l'ora dell'ultimo giro. Se dice «Mai partito» o «Fermo» (oltre 20 minuti) il cron non è impostato bene: ricontrolla il percorso di PHP e della cartella in cPanel → *Cron Jobs*.
8. **Primo accesso**: `https://tuodominio.it/admin`, accesso con l'utente creato; il pannello chiede di collegare l'app di autenticazione (2FA) e mostra i codici di recupero.

## Attenzione
- `.env`, `storage/` e `vendor/` non devono mai essere raggiungibili dal web (per questo la radice è `public/`).
- Documenti KYC e lettere IVD stanno in `storage/app` (privato), non in `public`.
- Il driver di firma è `fake`: **non usare in produzione reale** finché non c'è un provider vero.
- Il pagamento con carta reale richiede `LAMESS_PAYMENT_DRIVER=stripe` e le chiavi; con `fake` il «Controllo messa online» dà BLOCCO.
- I file XML delle fatture e le copie inviate stanno in `storage/app/private/sdi-out` (non pubblico): includili nei backup dei file.
- Prima di ogni aggiornamento fai il backup del database (cPanel → *Backup*).

## Log di audit su hosting condiviso
- Il log è protetto da trigger del database (vietano UPDATE e DELETE). Se l'utente MySQL non ha il privilegio `TRIGGER` la migrazione continua ma la pagina *Verifica del log* avvisa che la protezione a livello database manca: la catena di hash resta valida e rileva comunque le manomissioni.
- Annota ogni tanto l'«ancora» mostrata nella pagina di verifica: è l'unico modo di accorgersi di righe cancellate in coda.
- Non cambiare `APP_KEY` (o `LAMESS_AUDIT_KEY`) dopo l'installazione.

## Dopo ogni aggiornamento
`php artisan migrate` e `php artisan lamess:ruoli` (nuovi permessi: dopo ogni rilascio che ne aggiunge). Poi in `/admin` controlla che il riquadro «Operazioni automatiche» sia «Attivo».

**Sul sito senza terminale** oggi esistono solo due strade:
1. **Prima messa online con la versione definitiva** (il caso attuale, sito non ancora online): l'installazione guidata fa tutto (migrazioni, ruoli, piano, super_admin). Non servono altri passi.
2. **Aggiornamenti dopo la messa online**: l'installazione guidata è chiusa e non esiste ancora una pagina di manutenzione che lanci `migrate` e `lamess:ruoli` (era prevista ma non è stata realizzata: vedi OPEN-50 in «Decisioni aperte»). Finché non c'è, un rilascio con modifiche al database richiede un terminale temporaneo (anche quello di un altro hosting con accesso allo stesso database) oppure la realizzazione della pagina. Prima di ogni aggiornamento: backup del database (Sistema → Backup e cPanel → Backup) e conserva l'ultimo ZIP buono.

Dopo l'aggiornamento: apri **Sistema → Controllo messa online** e **Sistema → Verifica del log**.


## Prima di pagare davvero (SEPA ed email)
1. Impostazioni → Venditore: denominazione, P.IVA/CF, indirizzo, email.
2. Impostazioni → Bonifici SEPA: intestatario e IBAN del conto aziendale.
3. Far controllare alla banca il primo file SEPA.
4. Solo quando la posta è configurata: Impostazioni → Email → «Invio email attivo».

## Backup, controllo e piano di ritorno (Fase 8)
- Backup automatico ogni notte alle 02:15 (ultimi 14 in `storage/app/private/backups`); creazione e scarico manuale in **Sistema → Backup**.
- **Sistema → Controllo messa online** (o `php artisan lamess:controlla`) elenca ciò che manca prima di aprire.
- Procedura di migrazione e piano di ritorno: vedi «Messa online e piano di ritorno».
- Il backup MySQL è un dump SQL generato dal portale (tabelle, dati, trigger del log). Va **provato una volta** con un ripristino su un database di prova prima di fidarsene.
