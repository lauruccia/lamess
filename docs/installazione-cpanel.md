# Installazione su cPanel (hosting condiviso, MySQL/MariaDB)

Aggiornato al 28 ottobre 2026. Questo documento descrive l'installazione e la configurazione. Per gli aggiornamenti del sito già in linea, per l'apertura al pubblico e per il piano di ritorno vedi «Messa online e piano di ritorno».

**Situazione di Lamèss**: hosting cPanel con database MySQL `lamess_portale`, **senza terminale sul sito live** (solo cPanel, phpMyAdmin, File Manager). Il codice arriva da GitHub (repository privato `lauruccia/lamess`, branch `main`) con cPanel → *Git Version Control* e il file `.cpanel.yml` (sezione A). Il pacchetto ZIP (sezione B) resta come alternativa.

## A. Con Git Version Control (percorso in uso)

### A1. Prima volta
1. **Database**: cPanel → *Database MySQL*: crea database e utente, associali con tutti i privilegi (qui: `lamess_portale`).
2. **Repository**: cPanel → *Git Version Control* → *Create*, clone di `lauruccia/lamess` (repository privato, branch `main`). Percorso usato: `/home/lamess/repositories/lamess2`.
3. **Deploy**: *Manage* → **Deploy HEAD Commit**. Lo esegue il file `.cpanel.yml` della radice del repository, che:
   - copia l'applicazione in `/home/lamess/lamess_app` (fuori dalla cartella pubblica), **senza** `.git`, `.env`, `storage`, `vendor`, `tests`, `docs`;
   - crea le cartelle scrivibili di `storage` e `bootstrap/cache`;
   - installa le librerie con `composer install --no-dev --optimize-autoloader` (composer del server oppure `composer.phar` scaricato nella home); se `vendor` manca, il registro del deploy riporta «ATTENZIONE vendor mancante»;
   - rinomina `public_html/index.html` in `index.html.in-arrivo` (la pagina «in arrivo» di prima);
   - copia `public/` in `/home/lamess/public_html` senza cancellare nulla (restano `data/`, `subscribe.php`, `php.ini`, il file di verifica Google) e corregge i percorsi di `index.php` verso `lamess_app`;
   - crea il collegamento `public_html/storage` verso `lamess_app/storage/app/public`;
   - se `vendor` e `.env` esistono, lancia `package:discover` e `optimize:clear`.
   Il percorso di PHP nel deploy è `/opt/cpanel/ea-php83/root/usr/bin/php`.
4. **`.env`**: non è nel repository. Crealo da File Manager in `/home/lamess/lamess_app/.env` partendo da `.env.cpanel.example` (vedi A2) e **non condividerlo mai** (né il file né schermate che mostrano chiavi o token).
5. **PHP**: cPanel → *Seleziona versione PHP* → 8.3 o superiore, estensioni `mbstring, xml, curl, zip, gd, intl, pdo_mysql, fileinfo, openssl`.
6. **Installazione guidata**: apri `https://xn--lamss-6ra.it/installazione` (con `LAMESS_INSTALL_TOKEN` di almeno 24 caratteri nel `.env`). La pagina controlla il server, chiede il token e i dati del super_admin, poi in un solo passaggio: esegue **tutte le migrazioni**, sincronizza **ruoli e permessi**, carica il **piano compensi ufficiale di pre-lancio**, crea la **tariffa di spedizione Italia** (9,00 € IVA inclusa) se manca e crea il super_admin. Funziona una volta sola: si blocca da sola (file `storage/app/installed.lock`).
   Il **Piano Incaricati** non viene caricato dall'installazione: si crea da `/admin` → Compensi → Piano compensi → «Crea bozza “Piano Incaricati”», si rivede, si simula e si pubblica (con approvazione di un secondo amministratore se i «quattro occhi» sono attivi).
7. **Subito dopo**: cancella la riga `LAMESS_INSTALL_TOKEN` dal `.env`; in cPanel → *Operazioni pianificate (Cron)* aggiungi la riga ogni minuto
   `* * * * * /opt/cpanel/ea-php83/root/usr/bin/php /home/lamess/lamess_app/artisan schedule:run >> /dev/null 2>&1`
   (l'installazione guidata ne mostra una con `/usr/local/bin/php`: usa il percorso di PHP che funziona sul tuo account); entra in `/admin`, collega l'app 2FA e apri **Sistema → Controllo messa online**.
8. **Il sito resta chiuso al pubblico** finché non lo apri tu da Impostazioni di sistema → Sito (`LAMESS_SITE_OPEN=false` nel modello di `.env`). Vedi «Messa online», punto 1.
9. **Dati da compilare nel pannello** (Impostazioni): *Venditore* (ragione sociale, partita IVA, indirizzo, CAP, comune, provincia, regime fiscale: servono per la fattura elettronica), *Bonifici SEPA*, *Email*. I parametri fiscali di *Ricevute e trattenute* restano spenti finché il commercialista non li ha validati.

### A2. Variabili del `.env` di produzione
Il modello `.env.cpanel.example` contiene la parte di base (ambiente, database, sessioni e cache su file, code `sync`, posta, sito chiuso, 2FA, token di installazione, chiave del log). Le chiavi dei servizi esterni **non** vi sono elencate: si aggiungono a mano dalla tabella qui sotto. Tutte facoltative all'installazione; senza, il portale resta con i driver di prova e il «Controllo messa online» lo segnala. **Scrivi i valori solo nel `.env` sul server.**

Base per questo sito: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://xn--lamss-6ra.it` (forma punycode di `lamèss.it`; il sito risponde anche da `www`), `APP_LOCALE=it`, `DB_CONNECTION=mysql`, `DB_HOST=localhost`, `DB_DATABASE=lamess_portale` con utente e password del database, `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, `ADMIN_2FA_REQUIRED=true`. **Non cambiare `APP_KEY`** dopo l'installazione (renderebbe illeggibili i dati cifrati e il log di audit).

| Chiave | Valori | A cosa serve |
|---|---|---|
| `LAMESS_SITE_OPEN` | `false` (default) · `true` | sito aperto al pubblico; il valore salvato in Impostazioni → Sito ha la precedenza |
| `LAMESS_PAYMENT_DRIVER` | `fake` (prova) · `stripe` | pagamento con carta |
| `STRIPE_SECRET` | `sk_live_…` (in prova `sk_test_…`) | chiave segreta dell'account Stripe |
| `STRIPE_WEBHOOK_SECRET` | `whsec_…` | firma delle notifiche di Stripe (senza, i pagamenti non si confermano mai) |
| `LAMESS_KMONEY_DRIVER` | `fake` (default, solo sviluppo) · `api` · `off` | pagamento con KMoney |
| `KMONEY_BASE_URL`, `KMONEY_CLIENT_ID`, `KMONEY_CLIENT_SECRET` | testo | client OAuth «kshop-lamess»: collegamento dell'account KMoney del cliente |
| `KMONEY_SELLER_ACCOUNT` | numero di conto | conto KMoney di Lamèss che incassa |
| `KMONEY_API_TOKEN` | token | addebiti e rimborsi (token del conto venditore) |
| `KMONEY_WEBHOOK_SECRET` | testo | firma del webhook di KMoney (`/webhooks/kmoney`) |
| `KMONEY_REGISTER_URL` | indirizzo (facoltativo) | pagina «Registrati» di KMoney; vuoto = `{KMONEY_BASE_URL}/register` |
| `LAMESS_KYC_DRIVER` | `manual` (default) · `stripe_identity` | verifica d'identità online; usa la stessa `STRIPE_SECRET` |
| `LAMESS_SIGNATURE_DRIVER` | `fake` (default, solo sviluppo) · `email` | firma elettronica della lettera: `email` invia un codice OTP all'indirizzo dell'account (serve `LAMESS_MAIL_ENABLED=true` e l'SMTP); `fake` mostra l'OTP a schermo |
| `LAMESS_INVOICE_SINK` | `fake` · `file` · `mail` | destinazione delle fatture elettroniche (XML FatturaPA) |
| `LAMESS_SDI_MAIL_TO` | indirizzo email | destinatario del driver `mail` (casella di importazione del gestionale, commercialista o PEC verso lo SdI) |
| `LAMESS_SDI_SEND_MODE` | `immediate` (default) · `batch` | `immediate`: l'XML parte a ogni emissione; `batch`: restano «Da inviare» e partono col pulsante in *Fatture* o il 1° del mese alle 08:30 (modificabile anche da Impostazioni di sistema → Ordini e fatture) |
| `LAMESS_SELLER_NAME`, `_VAT`, `_ADDRESS`, `_ZIP`, `_CITY`, `_PROVINCE`, `_TAX_CODE`, `_REGIME` | testo | dati del venditore (modificabili anche da Impostazioni → Venditore) |
| `LAMESS_MAIL_ENABLED` | `false` (default) · `true` | interruttore generale delle email, comprese le fatture spedite dal driver `mail` |
| `LAMESS_RECEIPTS_ENABLED`, `LAMESS_WITHHOLDING_ENABLED` | `false` (default) | ricevute provvigionali e ritenuta d'acconto: **lasciare spenti** finché non risponde il commercialista |
| `LAMESS_INPS_ENABLED` | `false` (default) | contributo INPS: spento |
| `LAMESS_ANNUAL_FEE_ENABLED`, `LAMESS_ANNUAL_FEE` | `true` · `19.00` | canone annuale (IVA compresa); spento = nessun canone e rinnovi gratuiti |
| `LAMESS_TRANSFER_FEE` | `50.00` | contributo per la cessione del codice |
| `LAMESS_INHERITANCE_LIMBO_DAYS` | `180` | giorni dopo cui un codice di un incaricato deceduto, senza subentro, cessa |
| `LAMESS_DISPUTE_DAYS` | `10` | giorni per reclamare su estratti conto e ricevute |
| `LAMESS_PLAN_FOUR_EYES` | `true` | la pubblicazione del piano richiede un secondo amministratore |
| `LAMESS_KYC_REQUEST_DUE_DAYS` | `7` | giorni per rispondere a una richiesta di documenti KYC |
| `LAMESS_ORDINI_MAIL_TO` | indirizzo email | casella che riceve l'avviso «Nuovo ordine» (vuoto = nessun avviso) |
| `LAMESS_ABBONAMENTI_MAIL_TO` | indirizzo email | casella del riepilogo giornaliero degli abbonamenti (08:15) |
| `LAMESS_ORDER_FOR_OTHERS` | `false` (default) · `true` | mostra «Ordine per altri» nel carrello (nascosto dal 23/10/2026) |
| `LAMESS_SEPA_NAME`, `_IBAN`, `_BIC` | testo | conto da addebitare nel file SEPA dei pagamenti (modificabili da Impostazioni) |
| `LAMESS_ANALYTICS`, `LAMESS_ANALYTICS_RETENTION_DAYS` | `true` · `180` | statistiche di visita interne e giorni di conservazione (pulizia ogni notte alle 03:10) |
| `ADMIN_2FA_REQUIRED` | `true` (default) | autenticazione a due fattori obbligatoria per gli amministratori |

Molti di questi valori si cambiano anche da **Impostazioni** nel pannello (hanno la precedenza sul `.env`). Le chiavi segrete (Stripe, KMoney) stanno **solo** nel `.env`.

**Stripe, una volta sola** (dashboard Stripe → Sviluppatori → Webhook): aggiungi l'endpoint `https://xn--lamss-6ra.it/webhooks/stripe` e abilita gli eventi `checkout.session.completed`, `checkout.session.async_payment_succeeded` e, se usi la verifica d'identità, `identity.verification_session.verified`, `identity.verification_session.requires_input`, `identity.verification_session.canceled`. Copia il «segreto di firma» (`whsec_…`) in `STRIPE_WEBHOOK_SECRET`. La pagina `/webhooks/stripe` accetta solo chiamate firmate.
Prova prima con le chiavi `sk_test_` e le carte di prova di Stripe; il «Controllo messa online» segnala come ATTENZIONE una chiave di prova.

**KMoney**: l'endpoint del webhook è `https://xn--lamss-6ra.it/webhooks/kmoney`; gli indirizzi di ritorno OAuth `/area/kmoney/ritorno` vanno registrati sia per `xn--lamss-6ra.it` sia per `www.xn--lamss-6ra.it`. Dettagli in «Messa online», punto 5.

Il super_admin si crea **solo** dall'installazione guidata o da `php artisan lamess:superadmin`; mai dal pannello.

### A3. Aggiornamenti dopo la prima installazione
Procedura completa in «Messa online», punti 3 e 4. In breve: backup → (SQL delle migrazioni nuove in phpMyAdmin e riga in `migrations`) → *Update from Remote* → *Deploy HEAD Commit* → cancellare da File Manager i file in `bootstrap/cache` (tranne `.gitignore`) e `storage/framework/views` → controllare `/admin`, «Operazioni automatiche» e il Controllo messa online.
Il deploy non lancia `migrate` né `lamess:ruoli`, e l'installazione guidata, una volta usata, si chiude: **non esiste una pagina che applichi le migrazioni dal pannello** (OPEN-50).

## B. Con un pacchetto ZIP (alternativa, senza Git)
Il pacchetto si prepara sul PC e si carica da File Manager.

0. **Sul PC** serve PHP 8.3 e Composer. Su cPanel senza terminale **non si lancia `composer install`** (le librerie sono già nello ZIP). La cartella `vendor` locale (con gli strumenti di sviluppo) non va caricata: lo script del punto 1 costruisce un `vendor` di produzione (`--no-dev`) in una cartella a parte.
1. **Prepara lo ZIP**: `php tools/crea-pacchetto-cpanel.php --prima-installazione` → crea `dist/lamess-AAAAMMGG-…-prima-installazione.zip` (già con le librerie di produzione) e genera in `tools/.segreti-cpanel.env` la `APP_KEY` e il token di installazione (restano gli stessi nei pacchetti successivi: **non perderli**). Per gli aggiornamenti: stesso comando senza `--prima-installazione` (lo ZIP non contiene `.env`).
2. **Database**, 3. **Carica e decomprimi** lo ZIP fuori dalla cartella pubblica, es. `/home/UTENTE/lamess`.
4. **Cartella pubblica**: cPanel → *Domini* → *Document Root* = `/home/UTENTE/lamess/public`. Se non è possibile, copia il contenuto di `public/` in `public_html/` e correggi in `public_html/index.php` i due percorsi (`../lamess/vendor/autoload.php`, `../lamess/bootstrap/app.php`).
5. **`.env`**: rinomina `.env.da-compilare` in `.env` e compila dominio, database e posta (modello `.env.cpanel.example`).
6. **PHP** e 7. **installazione guidata** come ai punti A1.5 e A1.6; poi i punti A1.7-A1.9.

## C. Con terminale (solo sviluppo, o hosting che lo offre)
Requisiti: PHP 8.3 o superiore con le estensioni indicate sopra, MySQL 8 / MariaDB 10.6+, cron ogni minuto.
1. `.env` (copia da `.env.cpanel.example`) con `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `DB_*`, `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, posta, `ADMIN_2FA_REQUIRED=true`.
2. Comandi, nella cartella del progetto:
   ```
   composer install --no-dev --optimize-autoloader
   php artisan key:generate --force
   php artisan migrate --force
   php artisan lamess:ruoli
   php artisan db:seed --class=PrelaunchPlanSeeder --force
   php artisan lamess:superadmin tua@email.it --name="Laura"
   php artisan storage:link
   ```
   **Non** lanciare `DemoSeeder` in produzione.
3. `storage/` e `bootstrap/cache/` scrivibili (755, o 775 se serve).
4. Cron ogni minuto: `* * * * * /percorso/di/php /percorso/di/lamess/artisan schedule:run >> /dev/null 2>&1`.

Dopo un aggiornamento con terminale: `php artisan migrate --force` e `php artisan lamess:ruoli`.

## Operazioni automatiche (cron)
`schedule:run` ogni minuto fa partire (orari Europa/Roma):
- ogni minuto il «battito» che il riquadro «Operazioni automatiche» legge;
- 00:10 versioni del piano programmate che entrano in vigore; 00:20 sanzioni a tempo; 00:30 successioni;
- 02:15 backup del database; 02:45 ricalcolo notturno (stima del mese aperto);
- 03:10 pulizia delle statistiche di visita; 03:30 canoni annuali; 04:00 abbonamenti; 05:00 preparazione dei pagamenti dei mesi chiusi; 06:00 estratti conto (accettazione tacita); 08:00 promemoria documenti KYC; 08:15 riepilogo giornaliero degli abbonamenti (se c'è la casella);
- il 1° del mese alle 08:30 l'invio delle fatture in coda (solo con `LAMESS_SDI_SEND_MODE=batch`);
- ogni minuto, se `QUEUE_CONNECTION` non è `sync`, il lavoratore delle code;
- ogni ora: fatture (invio/ritentativi), chiusura mensile automatica (agisce solo quando il mese è finito e secondo l'impostazione «Chiusura automatica del mese») e Bonus di Struttura (solo se attivi in Impostazioni).
**Come controllare che giri**: in `/admin`, nella dashboard, il riquadro «Operazioni automatiche» mostra «Attivo» con l'ora dell'ultimo giro. Se dice «Mai partito» o «Fermo» (oltre 20 minuti) il cron non è impostato bene: ricontrolla il percorso di PHP e della cartella in cPanel → *Cron Jobs*.

## Attenzione
- `.env`, `storage/` e `vendor/` non devono mai essere raggiungibili dal web (per questo l'applicazione sta in `lamess_app`, fuori da `public_html`).
- Documenti KYC e lettere IVD stanno in `storage/app` (privato), non in `public`.
- Il driver di firma `fake` mostra il codice a schermo: **non usarlo in produzione**; usa `LAMESS_SIGNATURE_DRIVER=email` (il Controllo messa online dà BLOCCO con `fake`).
- Il pagamento con carta reale richiede `LAMESS_PAYMENT_DRIVER=stripe` e le chiavi; con `fake` il «Controllo messa online» dà BLOCCO.
- I file XML delle fatture e le copie inviate stanno in `storage/app/private/sdi-out` (non pubblico): includili nei backup dei file.
- Prima di ogni aggiornamento fai il backup del database (Sistema → Backup e cPanel → Backup).

## Log di audit su hosting condiviso
- Il log è protetto da trigger del database (vietano UPDATE e DELETE). Se l'utente MySQL non ha il privilegio `TRIGGER` la migrazione continua ma la pagina *Verifica del log* avvisa che la protezione a livello database manca: la catena di hash resta valida e rileva comunque le manomissioni.
- Annota ogni tanto l'«ancora» mostrata nella pagina di verifica: è l'unico modo di accorgersi di righe cancellate in coda.
- Non cambiare `APP_KEY` (o `LAMESS_AUDIT_KEY`) dopo l'installazione.

## Prima di pagare davvero (SEPA ed email)
1. Impostazioni → Venditore: denominazione, P.IVA/CF, indirizzo, email.
2. Impostazioni → Bonifici SEPA: intestatario e IBAN del conto aziendale.
3. Far controllare alla banca il primo file SEPA.
4. Solo quando la posta è configurata: Impostazioni → Email → «Invio email attivo».

## Backup e controllo
- Backup automatico ogni notte alle 02:15 (ultimi 14 in `storage/app/private/backups`); creazione e scarico manuale in **Sistema → Backup**.
- **Sistema → Controllo messa online** elenca ciò che manca prima di aprire.
- Il backup MySQL è un dump SQL generato dal portale (tabelle, dati, trigger del log). Va **provato una volta** con un ripristino su un database di prova prima di fidarsene.

## Cosa è verificato e cosa no
Verificato (fino al 5 ottobre 2026): migrazioni, seeder e installazione da zero su **MariaDB 10.11**; la suite di test gira su SQLite. Le migrazioni successive e la suite completa **non risultano provate su MariaDB/MySQL**: prima della messa online rilancia migrazioni e suite su MariaDB (`docs/phpunit.maria.xml.esempio`).
Non verificato: la tua versione esatta di MySQL/PHP sull'hosting, l'esito reale del deploy da Git (composer sul server), la posta reale, le prestazioni con molti incaricati.
