# Messa online: controlli, aggiornamenti e piano di ritorno

Aggiornato al 28 ottobre 2026. Il portale è su cPanel con database MySQL (`lamess_portale`) e **senza terminale sul sito live**: si lavora solo con cPanel, phpMyAdmin e File Manager. Indirizzo del sito: `https://lamèss.it` (in `APP_URL` si scrive la forma punycode `https://xn--lamss-6ra.it`); risponde anche da `www`.

## 1. Il portale resta chiuso finché non lo apri tu
Come funziona nel codice:
- L'interruttore **«Sito aperto al pubblico»** è in **Impostazioni di sistema → Sito**. Predefinito: **spento** (`LAMESS_SITE_OPEN=false`, vedi `.env.cpanel.example`).
- Da spento, i visitatori vedono la pagina **«Lamèss apre presto»** (risposta 503, `noindex`): nessuno può registrarsi, accedere all'area o ordinare.
- Restano sempre raggiungibili: il back-office `/admin`, i file riservati agli amministratori, i webhook dei pagamenti (`/webhooks/*`), `/installazione` (solo finché serve) e i file statici (css, js, immagini, font).
- **Chi è già collegato al back-office vede il sito normalmente**, per poterlo provare. Per vedere cosa vede il pubblico, usa una finestra anonima non collegata.
- Il valore salvato dal pannello **ha la precedenza sul `.env`**: se in Impostazioni c'è un valore salvato, cambiare `LAMESS_SITE_OPEN` nel `.env` non basta. Lo stato in vigore è la prima riga del «Controllo messa online» («Sito aperto al pubblico»).

**Per aprire**: prima il Controllo messa online senza blocchi (punto 2), poi Impostazioni di sistema → Sito → accendi «Sito aperto al pubblico» e salva (resta nel log di audit). **Per richiudere**: spegni lo stesso interruttore.

Emergenza senza accesso al pannello: in phpMyAdmin, tabella `system_settings`, riga con `key` = `site_open`, `value` = `false` (oppure `true` per aprire); poi cancella da File Manager i file dentro `storage/framework/cache/data`, perché le impostazioni sono tenute in cache.

## 2. Il «Controllo messa online»
In `/admin` → **Sistema → Controllo messa online** (solo super_admin). Da terminale (solo in sviluppo) c'è anche `php artisan lamess:controlla`.
Elenca ogni controllo con **OK**, **ATTENZIONE** (da valutare) o **BLOCCO** (da sistemare prima di aprire ai clienti). Si ripete dopo ogni correzione fino a non avere più blocchi.

I blocchi tipici: debug acceso, indirizzo senza https, 2FA non obbligatoria, token di installazione ancora nel `.env`, cron non attivo, pagamento con carta e firma elettronica ancora «di prova» (`fake`), dati del venditore incompleti, catena del log compromessa, cartelle `storage` e `bootstrap/cache` non scrivibili.
Con i driver reali compaiono controlli in più: chiavi e webhook di Stripe (BLOCCO se manca `STRIPE_SECRET` o `STRIPE_WEBHOOK_SECRET`, ATTENZIONE se la chiave è di prova), verifica d'identità online (ATTENZIONE se è manuale, BLOCCO se attiva senza chiave), invio delle fatture (ATTENZIONE con `fake` o `file`, BLOCCO con `mail` senza destinatario) e completezza dei dati del venditore per la fattura elettronica (partita IVA a 11 cifre, CAP, comune, provincia).
Dal 16 ottobre il controllo verifica anche il **cookie di sessione solo HTTPS** (BLOCCO in produzione se il cookie non è marcato sicuro: in produzione lo è da solo, oppure `SESSION_SECURE_COOKIE=true` nel `.env`) e la **protezione del log di audit nel database** (ATTENZIONE se l'hosting non ha concesso il privilegio TRIGGER: la catena di hash resta, ma il database non vieta da solo modifiche e cancellazioni; chiedere all'hosting il privilegio TRIGGER per l'utente del database e rieseguire la migrazione del log).
Altri avvisi: «Ricevute e trattenute fiscali» (ATTENZIONE finché sono spente), «Posta in uscita» (ATTENZIONE finché l'invio email è spento), incaricati attivi senza IBAN, utenti di prova.
**Il controllo non verifica KMoney**: la configurazione di KMoney (punto 5) va guardata a mano.

### Sicurezza prima dell'apertura (16 ottobre 2026)
- **HTTPS forzato**: `public/.htaccess` rimanda ogni richiesta `http://` a `https://` (esclusa la verifica del certificato AutoSSL `/.well-known/`). Si attiva con il primo deploy; provare `http://lamèss.it` e controllare che arrivi su `https://`.
- **Cookie di sessione**: in produzione solo HTTPS di default; il modello `.env.cpanel.example` ha anche `SESSION_SECURE_COOKIE=true`.
- **Accesso**: dopo 5 password sbagliate (stessa email e stesso IP) o 15 (stessa email da IP diversi) l'accesso è bloccato per 15 minuti; un accesso riuscito azzera il conteggio. Chi è bloccato può usare «Password dimenticata».
- **Prelievi dal wallet**: la verifica del saldo disponibile e la creazione della richiesta (e l'approvazione) avvengono nella stessa transazione, con il consulente bloccato: due richieste simultanee non possono più impegnare la stessa quota.
- **Operazioni automatiche**: ogni comando pianificato ha la protezione contro la sovrapposizione (si salta il giro se il precedente è ancora in corso; sblocco automatico dopo 2 ore).
- **A carico tuo sul server** (non si possono fare dal codice): verificare in `.env` `ADMIN_2FA_REQUIRED=true`, `APP_DEBUG=false`, `APP_ENV=production` e **nessuna riga** `LAMESS_INSTALL_TOKEN`; configurare 2FA e codici di recupero del super_admin; scaricare periodicamente i backup da *Sistema → Backup* e tenerli fuori dal server (o attivare i backup di cPanel); provare un ripristino su un database di prova; impostare `memory_limit` ≥ 256 MB e `max_execution_time` ≥ 120 s (cPanel → MultiPHP INI Editor); mettere un controllo esterno (es. UptimeRobot) su `https://lamèss.it/up`; dopo i primi giorni guardare cPanel → *Resource Usage*.

### Log di audit importato da un altro ambiente
Se nel database live ci sono righe del log create in locale (importazione dei dati), sono firmate con l'`APP_KEY` locale e il Controllo dà BLOCCO «la riga #N è stata modificata». Non è una manomissione: si risolve senza riscrivere nulla, dichiarando nel `.env` del server la chiave con cui sono firmate le prime righe:
- `LAMESS_AUDIT_KEY_PREVIOUS=` il valore dell'`APP_KEY` dell'ambiente di origine (stessa forma, `base64:…`);
- `LAMESS_AUDIT_KEY_PREVIOUS_UNTIL=` l'id dell'ultima riga importata (es. 352).
Le righe fino a quell'id si controllano con la chiave precedente, le successive con quella del server (`APP_KEY`, oppure `LAMESS_AUDIT_KEY` se impostata); il collegamento fra una riga e la precedente resta controllato dappertutto. Con `LAMESS_AUDIT_KEY` vuota la chiave in vigore è l'`APP_KEY` del server. Il valore delle chiavi non va mai scritto in git né nei documenti.

### Firma della Lettera di Incarico con codice per email (16 ottobre 2026)
Il driver `email` (`LAMESS_SIGNATURE_DRIVER=email` nel `.env`) manda un codice di 6 cifre all'indirizzo dell'account: vale 15 minuti, si può usare una volta, dopo 5 errori si deve chiederne un altro, un nuovo invio si può chiedere dopo 60 secondi (pulsante «Invia di nuovo il codice» nella pagina Diventa Incaricato). Il codice non appare mai a schermo né nei log; in cache resta solo la sua impronta. Il messaggio dice solo l'indirizzo mascherato (`m***@dominio.it`).
Per usarlo servono: `LAMESS_MAIL_ENABLED=true` (o l'impostazione «Invio email attivo» in Impostazioni di sistema) e l'SMTP configurato (`MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`). Se l'invio è spento il portale non finge: dice che il codice non può essere inviato. Il Controllo messa online ha la voce «Firma con codice per email: invio attivo» (BLOCCO se la posta è spenta o su «log»). **Prima di aprire**: prova una firma vera con un tuo indirizzo e controlla che l'email arrivi (anche nello spam; SPF/DKIM del dominio aiutano).
La firma resta una firma elettronica semplice: il valore legale con un OTP per email va confermato col consulente.

## 3. Come si aggiorna il sito (cPanel → Git Version Control)
Il codice sta nel repository GitHub **privato** `lauruccia/lamess` (branch `main`), collegato a cPanel → **Git Version Control** (percorso `/home/lamess/repositories/lamess2`). Il vecchio repository pubblico `lauruccia/lame` resta con la pagina «in arrivo» e non c'entra con questo sito.

**Procedura per ogni aggiornamento**
1. **Backup**: Sistema → Backup → Crea, poi scaricalo (e, se ci sono modifiche al database, anche cPanel → Backup).
2. **Se il rilascio ha migrazioni nuove**: esegui l'SQL in phpMyAdmin (punto 4), meglio *prima* del deploy: aggiungere colonne vuote non rompe il codice vecchio.
3. cPanel → Git Version Control → *Manage* sul repository → **Update from Remote**, poi **Deploy HEAD Commit**.
4. **Svuota la cache a mano** (senza terminale): in File Manager cancella i file dentro `bootstrap/cache` (**tranne `.gitignore`**) e quelli dentro `storage/framework/views`. I file in `bootstrap/cache` si ricreano da soli; le viste compilate si rigenerano alla prima visita.
5. Controlla: `/admin` si apre, riquadro «Operazioni automatiche» = Attivo, Sistema → Controllo messa online e Verifica del log.

**Cosa fa il deploy** (file `.cpanel.yml` nella radice del repository; account `lamess`, PHP 8.3 `/opt/cpanel/ea-php83`):
- copia l'applicazione in `/home/lamess/lamess_app` (fuori dalla cartella pubblica) con `rsync`, **senza** `.git`, `.env`, `storage`, `vendor`, `tests`, `docs`;
- crea le cartelle scrivibili di `storage` e `bootstrap/cache` se mancano;
- installa le librerie con `composer install --no-dev` (usa il composer del server o scarica `composer.phar` nella home): se non riesce, scrive «ATTENZIONE vendor mancante» nel registro del deploy;
- la prima volta rinomina `public_html/index.html` in `index.html.in-arrivo`;
- copia `public/` in `/home/lamess/public_html` (senza cancellare nulla: `data/`, `subscribe.php`, `php.ini` e il file di verifica Google restano) e riscrive i percorsi di `index.php` verso `lamess_app`;
- crea il collegamento `public_html/storage`;
- se `vendor` e `.env` ci sono, lancia `package:discover` e `optimize:clear`.
Il deploy **non lancia le migrazioni** né `lamess:ruoli`. Il `.env` **non sta nel repository**: vive in `/home/lamess/lamess_app/.env` e si modifica da File Manager. Non condividerlo e non incollarne il contenuto in chat o email.

Nota: il deploy lancia già `optimize:clear`, ma solo se `vendor` e `.env` esistono e il comando riesce; per questo, finché non è provato che la cache si svuota davvero dopo un deploy, resta la pulizia manuale del punto 4.

## 4. Migrazioni nuove senza terminale
**Non esiste** un meccanismo automatico: l'installazione guidata esegue `migrate` una volta sola e poi si chiude (`storage/app/installed.lock`); non c'è una pagina di manutenzione che lo rifaccia (vedi OPEN-50 in «Decisioni aperte»). Il deploy non lancia `artisan migrate`. Resta la via manuale:

1. **Quali migrazioni mancano**: in phpMyAdmin apri la tabella `migrations` (colonne `id`, `migration`, `batch`) e confrontala con i file in `/home/lamess/lamess_app/database/migrations` (File Manager). Mancano quelle che hanno un file ma nessuna riga.
2. **Backup** del database (Sistema → Backup).
3. Per ogni migrazione mancante, in ordine di nome, apri il file, leggi cosa fa nel metodo `up()` ed **esegui lo stesso SQL** nella scheda *SQL* di phpMyAdmin.
4. **Registrala** come eseguita, altrimenti il registro delle migrazioni resta indietro.

Esempio reale, `2026_11_04_100000_consultants_rank_hold_until` («Mantieni qualifica»: aggiunge due colonne a `consultants`):
```sql
ALTER TABLE consultants
  ADD COLUMN rank_hold_until DATE NULL,
  ADD COLUMN rank_hold_reason VARCHAR(500) NULL;

INSERT INTO migrations (migration, batch)
SELECT '2026_11_04_100000_consultants_rank_hold_until', MAX(batch) + 1 FROM migrations;
```
Se il database è sul sito di prova o ha un prefisso alle tabelle, adatta i nomi. Se l'`ALTER` dà «Duplicate column name», la colonna c'è già: registra solo la riga in `migrations`.

**Migrazioni dopo il 13 ottobre** (in ordine di nome; l'SQL pronto da incollare è nel registro aggiornamenti, alla voce di ogni rilascio, che resta la fonte: se manca, traduci il metodo `up()`):

| Migrazione | Cosa fa | Tipo |
|---|---|---|
| `2026_11_05_100000_product_makes_client` | «Rende cliente» sul prodotto | ALTER |
| `2026_11_06_100000_create_referral_invites_table` | inviti dei link di presentazione | CREATE |
| `2026_11_07_100000_shipping_address_country` | paese sull'indirizzo di spedizione | ALTER |
| `2026_11_07_110000_subscriptions_reminded_for` | promemoria scadenza carta | ALTER |
| `2026_11_07_120000_products_barcode` | codice a barre del prodotto | ALTER |
| `2026_11_07_130000_founder_prices` | incaricato fondatore e prezzo fondatore | ALTER |
| `2026_11_07_140000_subscriptions_interval_months` | abbonamenti mensili o bimestrali | ALTER |
| `2026_11_08_100000_pv_movements_client_user` | cliente collegato al movimento | ALTER |
| `2026_11_08_110000_easyfatt_exported_at` | data di esportazione Easyfatt | ALTER |
| `2026_11_09_100000_product_months_per_piece` | «I pezzi moltiplicano i mesi» | ALTER |
| `2026_11_09_110000_tasca` | Tasca: conti, movimenti, accredito del Bonus di Struttura | CREATE e ALTER |
| `2026_11_10_100000_rank_helps` | Agenti regalati | CREATE |
| `2026_11_11_100000_ivd_letter_versions` | versioni della Lettera, firme per versione (tre tabelle) | CREATE |
| `2026_11_12_100000_legal_documents` | Regolamento modificabile (due tabelle) | CREATE |
| `2026_11_13_100000_beneficiary_bic` | BIC su incaricati, pagamenti e richieste di prelievo | ALTER |
| `2026_11_14_100000_account_suspension` | sospensione e ban degli accessi (`users`) | ALTER |
| `2026_11_15_100000_site_analytics` | statistiche delle visite (due tabelle) | CREATE |

Le pagine nuove **danno errore finché la loro tabella non esiste**: Agenti regalati, Lettera di incarico e Regolamento (admin e `/area/lettera`), Tasca, Statistiche. Applica le migrazioni **prima** di aprire il rilascio, non dopo. Nel `INSERT INTO migrations` usa `MAX(batch)` oppure `MAX(batch) + 1`: per il registro è indifferente.

Altri casi: le migrazioni che creano tabelle o indici richiedono di tradurre a mano `Schema::create(...)`; se non te la senti, fai fare la traduzione a uno sviluppatore **prima** di pubblicare il rilascio, oppure esegui `php artisan migrate --force` da un terminale temporaneo (anche di un altro hosting con accesso allo stesso database).

**Permessi nuovi** (`lamess:ruoli`): il super_admin ha comunque ogni permesso. I permessi di dettaglio dei ruoli vengono sincronizzati dal codice solo all'installazione, con `lamess:ruoli` e quando si crea un utente admin da Sistema → Utenti. Non è provato cosa succeda dopo un rilascio che ne aggiunge: se un admin non vede una voce nuova, ricontrollalo dopo aver creato un utente admin dal pannello e segnalalo.

## 5. KMoney in produzione
Variabili nel `.env` del sito (solo i **nomi**: i valori vengono dal pannello KMoney e non vanno scritti in documenti, email, chat o screenshot):
- `LAMESS_KMONEY_DRIVER=api` (senza, o con altro valore, il driver è `fake`: simulazione di sviluppo, nessun pagamento vero);
- `KMONEY_BASE_URL`, `KMONEY_CLIENT_ID`, `KMONEY_CLIENT_SECRET`: il client OAuth «kshop-lamess», serve al collegamento dell'account del cliente («Accedi con KMoney»);
- `KMONEY_SELLER_ACCOUNT`: conto KMoney di Lamèss che incassa;
- `KMONEY_API_TOKEN`: token del conto venditore per addebiti e rimborsi;
- `KMONEY_WEBHOOK_SECRET`: firma del webhook; l'endpoint da registrare in KMoney è `https://xn--lamss-6ra.it/webhooks/kmoney` (le chiamate senza firma valida vengono rifiutate);
- facoltativa `KMONEY_REGISTER_URL` (pagina «Registrati»; vuota = `{KMONEY_BASE_URL}/register`).

Il pagamento con KMoney si abilita solo se sono compilati base URL, client id, client secret e conto venditore.
**Indirizzi di ritorno OAuth**: l'indirizzo è costruito sul dominio da cui arriva il cliente, quindi vanno registrati nel client KMoney **sia con `www` sia senza**:
`https://xn--lamss-6ra.it/area/kmoney/ritorno` e `https://www.xn--lamss-6ra.it/area/kmoney/ritorno`.
Se un token è stato mostrato in uno screenshot o in una chat, va **ruotato** (rigenerato) in KMoney e aggiornato nel `.env`.

## 6. Cosa resta spento di proposito
- **Ricevute provvigionali e ritenuta d'acconto**: `LAMESS_RECEIPTS_ENABLED=false` e `LAMESS_WITHHOLDING_ENABLED=false` finché non risponde il commercialista (i bonifici sono pari al lordo). INPS resta spenta (`LAMESS_INPS_ENABLED=false`).
- **Email**: `LAMESS_MAIL_ENABLED=false`. Si accende solo a posta verificata, da Impostazioni → Email → «Invio email attivo».
- **Sito al pubblico**: chiuso (punto 1).

### Email in coda (consigliato quando accendi la posta)
Con `QUEUE_CONNECTION=sync` (oggi) le email partono dentro la richiesta: se il server di posta è lento, rallenta anche la pagina. Le notifiche ai clienti (ordine pagato e spedito, estratti, KYC, inviti, canone, abbonamento) sono ora **accodabili**; restano immediate solo il codice di firma e il reset password.
1. Nel `.env` metti `QUEUE_CONNECTION=database` (la tabella `jobs` c'è già dall'installazione, nessuna migrazione).
2. Non serve altro: lo scheduler (il cron di ogni minuto) lancia da solo `queue:work --stop-when-empty --max-time=50`. Con `sync` quel lavoro non parte.
3. Svuota `bootstrap/cache/config.php` se presente. Le email partono entro circa un minuto; quelle fallite si ritentano 3 volte (dopo 1 e 5 minuti) e poi finiscono in `failed_jobs`.
4. Prova: un ordine di prova pagato, poi controlla in phpMyAdmin che `jobs` si svuoti e `failed_jobs` resti vuota.

## 7. Sequenza consigliata
1. **Prova generale** (sito di prova o, in mancanza, il sito chiuso con l'accesso admin): importazione, calcolo di un mese, confronto con Odoo (`lamess:verifica-odoo`, che richiede un terminale: si fa in sviluppo).
2. **Controllo sicurezza**: Controllo messa online senza blocchi; verifica del log; backup creato e scaricato; prova del ripristino su un database di prova.
3. **Finestra di migrazione** (consigliata di sera o nel fine settimana). Avvisare gli incaricati.
4. **Congelamento di Odoo**: da quel momento non si registrano più ordini né movimenti in Odoo.
5. **Esportazione finale da Odoo** → importazione nel portale (`lamess:importa-odoo`, `lamess:importa-storico`, `lamess:importa-prelievi`: richiedono un terminale, quindi si fanno in sviluppo sul database che poi si carica, oppure da un terminale temporaneo), poi riconciliazione: i totali devono coincidere con quelli di Odoo (pagina Riconciliazioni).
6. **Mesi già pagati**: segnarli «storico già pagato» (scheda del periodo) prima di preparare qualsiasi pagamento.
7. **Backup subito dopo l'importazione** (Sistema → Backup → Crea) e scaricarlo.
8. **Apertura**: Impostazioni di sistema → Sito → «Sito aperto al pubblico» (punto 1). Il dominio è già quello definitivo.
9. **Controlli dopo l'apertura**: accesso admin con 2FA, un ordine di prova, riquadro «Operazioni automatiche» = Attivo, Controllo messa online.
10. Solo a cose verificate: accendere l'invio email (Impostazioni → Email → «Invio email attivo»).

## 8. Piano di ritorno (se qualcosa non va)
**Prima dell'apertura** nulla è irreversibile: il sito è chiuso al pubblico e Odoo resta in funzione.

**Dopo l'apertura, subito, senza ordini veri**: richiudi il sito (Impostazioni di sistema → Sito, spegni l'interruttore). I visitatori tornano a vedere «Lamèss apre presto»; Odoo riprende a lavorare e nulla è perso perché i dati nel portale erano una copia.

**Dopo l'apertura, con ordini già entrati nel portale**
1. Non tornare indietro alla cieca: gli ordini nuovi esistono solo nel portale. Richiudi il sito come sopra (il back-office resta raggiungibile).
2. Scarica un backup (Sistema → Backup) e conservalo.
3. Correggi il problema, oppure ripristina l'ultimo backup buono: cPanel → **phpMyAdmin** → scegli il database `lamess_portale` → **Importa** → file `lamess-….sql.gz` (si importa anche compresso). L'importazione sostituisce tutte le tabelle e ricrea i trigger di protezione del log.
4. Dopo il ripristino: Controllo messa online e verifica del log.
5. Gli ordini entrati dopo il backup vanno riportati a mano o ricostruiti dai pagamenti.

**Codice che dà problemi dopo un aggiornamento**: su GitHub si annulla il commit difettoso con un commit di ripristino (`git revert`) su `main`, poi in cPanel *Update from Remote* e *Deploy HEAD Commit*, poi si svuota la cache (punto 3.4). Se il rilascio aveva migrazioni, le colonne aggiunte restano (sono compatibili con il codice vecchio); se serve tornare indietro anche sul database, si ripristina il backup fatto prima dell'aggiornamento (perdendo i dati inseriti nel frattempo).
Alternativa al deploy da Git: il pacchetto ZIP (`php tools/crea-pacchetto-cpanel.php`, vedi «Installazione su cPanel») resta disponibile; conservare sempre l'ultimo pacchetto buono.

## 9. Cosa serve dal cliente prima di aprire (aggiornato al 28 ottobre 2026)
**Il codice è pronto; mancano credenziali e decisioni.** Lo stato reale delle chiavi sul sito lo dice il Controllo messa online.
- **Carta (Stripe)**: account del cliente, chiavi `sk_live_…` e segreto del webhook; provare prima con le chiavi di prova e una carta di prova. Poi `LAMESS_PAYMENT_DRIVER=stripe`. Gli **abbonamenti con carta** si rinnovano da soli: dopo il primo pagamento Stripe la carta è salvata e i cicli successivi si addebitano senza il cliente davanti (se la banca chiede una conferma, il ciclo non è pagato e si riprova o va in pausa).
- **KMoney**: vedi punto 5.
- **Verifica d'identità online (facoltativa)**: `LAMESS_KYC_DRIVER=stripe_identity`; ha un costo per verifica. Senza, i documenti si controllano a mano.
- **Fatture elettroniche**: scegliere come trasmetterle. Il portale produce l'XML FatturaPA (validato contro lo schema 1.2.2). Opzioni: `file` (XML in `storage/app/private/sdi-out`, da ritirare), `mail` (spedito a una casella: gestionale, commercialista o PEC verso lo SdI). **GestionaleK**: nei documenti non risulta nessuna interfaccia; servono le loro specifiche per un collegamento diretto. Clienti esteri: l'XML usa il paese reale e `XXXXXXX`; le righe a IVA 0 hanno la Natura (`LAMESS_VAT_ZERO_NATURE`). Da decidere con il commercialista: IVA/OSS per i clienti UE, Natura, privati in fattura o in ricevuta (le ricevute non vanno allo SdI).
- **Dati del venditore**: ragione sociale, partita IVA (11 cifre), indirizzo, CAP, comune, provincia, regime fiscale.
- **Parametri fiscali** (ritenuta, quota imponibile, INPS, commissione sui prelievi) e **testi** (Lettera IVD, Regolamento): la Lettera è ora il testo definitivo di Kadeo (modificabile a versioni dal pannello), il Regolamento v2.0 è in bozza da validare; da far validare al commercialista e al Consorzio; ricevute e ritenuta restano spente finché non sono validati.
- **Firma elettronica reale** della lettera d'incarico: finché `LAMESS_SIGNATURE_DRIVER` è `fake` è un sistema di prova (OTP mostrato a schermo) e il Controllo dà BLOCCO. Decisione del 16/10: si usa `email` (codice OTP per email), vedi sopra.
- **IBAN degli incaricati**: li inseriscono loro dall'area personale; fuori area SEPA serve anche il BIC/SWIFT.
- **Caselle per gli avvisi**: `LAMESS_ORDINI_MAIL_TO` (avviso «Nuovo ordine») e `LAMESS_ABBONAMENTI_MAIL_TO` (riepilogo giornaliero degli abbonamenti, alle 08:15); servono l'invio email acceso e le caselle indicate.
- **Prova su MariaDB/MySQL** delle migrazioni nuove, sul sito di prova.

## 10. Primo giorno dopo l'apertura
1. Un ordine vero con carta (importo minimo) e verifica che l'ordine passi a «pagato» da solo: significa che il webhook funziona. Se resta «in attesa», controlla l'endpoint e il segreto in Stripe (Sviluppatori → Webhook → consegne). Stessa verifica con un ordine pagato in KMoney.
2. Controlla che la fattura sia «inviata» (Vendite → Fatture, colonna dell'invio) e che l'XML si scarichi (azione XML).
3. Controlla in Assistenza → Ticket che l'area incaricato permetta di aprire una richiesta, e che il cron mostri «Attivo».
4. Dopo la prima chiusura di mese: estratti conto pubblicati (Compensi), ricevute e prelievi se il piano è «su richiesta».
