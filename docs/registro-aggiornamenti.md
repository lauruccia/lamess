# Registro aggiornamenti

## 2026-11-10 — Pagine personali: pagamento automatico con KMoney (conto di KNM)
- **«Paga con KMoney»** in *Area → La mia pagina* (prima attivazione, scadenza passata e rinnovo nel preavviso). Il portale crea su KMoney una richiesta di pagamento da 150 KY sul conto di **KNM** (non Kadeo Group) e manda l'incaricato alla pagina di pagamento di KMoney; lì accede o apre il conto con dati già compilati. Quando KMoney conferma, la pagina si attiva da sola (email all'incaricato) e l'incaricato torna a «La mia pagina». Rinnovo annuale identico. La procedura a mano resta come ripiego («Preferisci la procedura a mano?»).
- **Spento di default**: si accende da *Impostazioni → Rete* («Pagine personali: pagamento automatico con KMoney») solo con `KMONEY_KNM_API_TOKEN`, `KMONEY_KNM_WEBHOOK_SECRET` e `KMONEY_BASE_URL` nel `.env`. Il conto di KNM è un'impostazione («conto KMoney che incassa»), non una costante.
- **Avviso firmato** `POST /webhooks/kmoney-pagine` (firma HMAC-SHA256 `X-KMoney-Signature`, la stessa di KMoney): idempotente (stesso avviso due volte = una attivazione), importo e valuta verificati, mai attivazione su pagamento non abbinato o con importo diverso (restano in audit: `page.payment_mismatch`, `page.payment_unmatched`). Se l'avviso tarda, il ritorno dell'incaricato chiede a KMoney com'è andata la richiesta.
- Codice: `PersonalPagePayment`, `PagePaymentWebhookController`, rotte `/area/pagina/paga`, `/area/pagina/pagamento`, `/webhooks/kmoney-pagine`. Test: `PersonalPagePaymentTest` (13, KMoney simulato). Manuali utente, admin, sviluppatori e `parametri.md` aggiornati.
- **Da fare su KMoney** (lato kmoney-app, vedi il suo registro): rilascio con la registrazione precompilata e il ritorno al pagamento; poi per il conto di KNM creare il token API *write* e registrare il webhook `payment_request.paid` verso `https://<sito>/webhooks/kmoney-pagine` con il segreto.
- Migrazione `2026_11_22_100000_consultant_pages_kmoney_payment`. Sul sito live esegui **prima del deploy**, in phpMyAdmin → scheda SQL:
  ```sql
  ALTER TABLE consultant_pages
    ADD COLUMN kmoney_request_uuid VARCHAR(64) NULL AFTER activation_note,
    ADD COLUMN kmoney_paid_uuid VARCHAR(64) NULL AFTER kmoney_request_uuid;

  INSERT INTO migrations (migration, batch)
  SELECT '2026_11_22_100000_consultant_pages_kmoney_payment', MAX(batch) + 1 FROM migrations;
  ```
- Se le colonne mancano, «La mia pagina» dà errore: eseguire l'SQL prima del deploy.
- Da chiarire con la commercialista: l'incasso è di KNM ma il portale fattura per il Consorzio Kadeo.


## 2026-11-10 — Pagine personali: nessuna bozza creata da una semplice visita, foto non più persa
- Con l'attivazione richiesta, aprire *La mia pagina* **non crea più** la riga della pagina: nasce solo con la richiesta di attivazione o con l'attivazione del Consorzio. Spenta l'attivazione, si comporta come prima (la bozza nasce all'apertura).
- **Foto**: «Modifica testi» del Consorzio (salvataggio diretto) partiva dal contenuto in uso e non dalla proposta in attesa: se la foto era stata caricata ma non ancora approvata, la perdeva e ne cancellava il file. Ora parte sempre dal contenuto che si vede nel modulo (proposta in attesa, altrimenti contenuto in uso). Test aggiunti in `PersonalPageTest` (22).
- Nessuna migrazione. Per togliere una pagina creata per errore: `DELETE FROM consultant_pages WHERE slug = '…';` (la foto, se c'era, sta in `storage/app/private/pages/{id}`).

## 2026-11-09 — Pagine personali: procedura di pagamento con i link KMoney
- La schermata di richiesta (e l'avviso di rinnovo) guida in 4 passi: **Apri un conto KMoney** (kmoney.it/register), **Ricarica KMoney** (kmoney.it/ricarica), acquisto del prodotto **«Pagina Incaricato Lamèss»** (kmoney.it/shop/169), poi il pulsante di richiesta. Il link del prodotto è il valore predefinito di *Pagine personali: link del prodotto* (modificabile in *Impostazioni → Rete*); registrazione e ricarica sono in `lamess.personal_pages.register_url` / `recharge_url` (env `LAMESS_PERSONAL_PAGES_REGISTER_URL`, `_RECHARGE_URL`). Nessuna migrazione.

## 2026-11-09 — Pagine personali: attivazione su richiesta, a pagamento (150 €), annuale
- La pagina personale **non è più per tutti**: l'incaricato la **richiede** da *Area → La mia pagina*, dove trova le istruzioni: registrarsi su kmoney.it, acquistare il prodotto **«Pagina Lamèss»** (**150,00 €**; il link del prodotto, visibile solo ai registrati, lo inserisce la titolare nelle impostazioni; i KMoney vanno al conto di KNM, non di Kadeo Group), indicare con quale nome o email ha pagato e premere il pulsante «Ho pagato, richiedi l'attivazione». Finché non è attivata non può scrivere, vedere l'anteprima né mettere online (e `/i/nome` non si apre).
- **Attivazione manuale** (per ora): dopo il pagamento la **titolare** controlla l'acquisto su KMoney e in *Assistenza → Pagine personali* usa **Conferma pagamento e attiva** (solo lei la vede); chi ha accesso al back-office può **Non accogliere la richiesta** con un motivo. All'attivazione l'incaricato riceve una email. Il pagamento automatico (addebito KMoney dal portale) è predisposto come passo successivo: basterà chiamare `PersonalPageService::activate($page, 'paid', $riferimento)`.
- **Durata**: un anno (impostabile). Un rinnovo fatto prima della scadenza somma i mesi alla data attuale. Negli ultimi 30 giorni (impostabile) l'incaricato vede l'avviso con il pulsante «Ho pagato, richiedi il rinnovo» e riceve una email di promemoria (`lamess:pagine-scadenze`, ogni giorno alle 08:20). Scaduta, la pagina smette di essere pubblica; testi e foto restano salvati.
- **Gratis**: la titolare può regalare l'attivazione, con o senza scadenza, dall'azione **Attiva gratis** sulla riga e dal pulsante **Attiva gratis per un incaricato** (anche per chi non ha ancora aperto la pagina). Le altre figure del back-office non vedono queste azioni. Le pagine già esistenti risultano attivate dal Consorzio, senza scadenza.
- **Impostazioni** (*Impostazioni di sistema → Rete*): attivazione su richiesta (interruttore: spento, tutti gli incaricati attivi usano la pagina come prima), costo (150.00), link del prodotto su kmoney.it, mesi di validità (12), giorni di preavviso (30). Env: `LAMESS_PERSONAL_PAGES_ACTIVATION`, `_PRICE`, `_PAY_URL`, `_MONTHS`, `_REMINDER_DAYS`.
- Elenco admin: nuove colonne *Attivazione* e *Scade il*, filtro per attivazione (richieste, attive, in scadenza, scadute, non attivate); il numero in menu conta revisioni **e** richieste. L'elenco non parte più filtrato sulle sole pagine da approvare.
- Codice: `PersonalPageService` (`isActivated`, `requestActivation`, `activate`, `cancelRequest`, `sendExpiryReminders`), notifica `PersonalPageActivation`, comando `LamessPagineScadenze`, vista `site/area/page-activation.blade.php`. Test: `tests/Feature/Pages/PersonalPageTest.php` (21).
- Migrazione `2026_11_17_100000_consultant_pages_activation`. Sul sito live esegui **prima del deploy**, in phpMyAdmin → scheda SQL (le pagine già create risultano attivate dal Consorzio, senza scadenza):
  ```sql
  ALTER TABLE consultant_pages
    ADD COLUMN activation VARCHAR(12) NOT NULL DEFAULT 'none' AFTER blocked_note,
    ADD COLUMN activation_source VARCHAR(8) NULL AFTER activation,
    ADD COLUMN activation_requested_at TIMESTAMP NULL DEFAULT NULL AFTER activation_source,
    ADD COLUMN activated_at TIMESTAMP NULL DEFAULT NULL AFTER activation_requested_at,
    ADD COLUMN activation_expires_at TIMESTAMP NULL DEFAULT NULL AFTER activated_at,
    ADD COLUMN activation_reminded_at TIMESTAMP NULL DEFAULT NULL AFTER activation_expires_at,
    ADD COLUMN activation_note TEXT NULL AFTER activation_reminded_at;

  UPDATE consultant_pages SET activation = 'active', activation_source = 'free', activated_at = NOW();

  INSERT INTO migrations (migration, batch)
  SELECT '2026_11_17_100000_consultant_pages_activation', MAX(batch) + 1 FROM migrations;
  ```
- Se le colonne mancano, «La mia pagina» e l'elenco *Pagine personali* danno errore: eseguire l'SQL prima del deploy.

## 2026-11-09 — Compensi della Tasca come metodo di pagamento (+25%)
- Al checkout l'incaricato attivo trova, **dopo gli altri metodi**, l'interruttore **«Usa i compensi della Tasca»** (+25%): sceglie carta o bonifico per l'eventuale resto. Con 120 € di compensi paga 150 € di prodotti; usando la Tasca la **spedizione è gratuita** (anche se paghi solo una parte con i compensi); se la Tasca copre tutto l'ordine questo risulta pagato subito.
- Si usano solo i compensi **liberi**: mesi chiusi, estratto conto pubblicato con i 10 giorni di reclamo passati, nessuna contestazione, nessuna sospensione (`bonus_hold`), né prelievi già richiesti. Vale su tutti i prodotti (abbonamenti compresi) senza tetto mensile; non si combina con KMoney.
- La scelta è **definitiva**: al riepilogo serve la spunta di consenso, registrata come evento dell'ordine (`tasca_consent`). La parte pagata con la Tasca genera commissioni e fa «cliente» sul valore di listino. La maggiorazione è sull'ordine (`orders.tasca_bonus`): è il costo per l'azienda.
- Annullo prima del pagamento: i compensi tornano nella Tasca. Reso: tornano **senza la maggiorazione** e restano spendibili solo per acquisti (non prelevabili).
- Abbonamenti con carta: interruttore **«Usa la Tasca nei rinnovi»** in *Abbonamenti*; se il saldo non basta si addebita la carta.
- Admin: *Impostazioni → Metodi di pagamento* (`tasca_purchase_enabled`, `tasca_bonus_percent`); sul prodotto, interruttore **«Acquistabile con i compensi della Tasca»**; negli ordini colonna «Maggiorazione Tasca» e riga «Compensi Tasca» nel dettaglio.
- Codice: `TascaPurchase`, `OrderService::place(... useTasca)`, `RefundService` (metodo `tasca`), `WalletService::purchaseOnlyCredit`, `SubscriptionService::setUseTasca`. Test: `tests/Feature/Wallet/TascaPurchaseTest.php`.
- Fiscalità (compensazione, IVA, Kadeo/Inhoreca, ritenuta): da confermare con la commercialista; per ora gestita nel modo più semplice.
- Migrazione `2026_11_21_100000_tasca_purchases`. Sul sito live esegui **prima del deploy**, in phpMyAdmin → scheda SQL:
  ```sql
  ALTER TABLE orders
    ADD COLUMN tasca_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN tasca_used DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN tasca_bonus DECIMAL(12,2) NOT NULL DEFAULT 0;
  ALTER TABLE products ADD COLUMN tasca_enabled TINYINT(1) NOT NULL DEFAULT 1;
  ALTER TABLE subscriptions ADD COLUMN use_tasca TINYINT(1) NOT NULL DEFAULT 0;
  ALTER TABLE wallet_transactions ADD COLUMN order_id BIGINT UNSIGNED NULL, ADD INDEX wallet_transactions_order_id_index (order_id);

  INSERT INTO migrations (migration, batch)
  SELECT '2026_11_21_100000_tasca_purchases', MAX(batch) + 1 FROM migrations;
  ```
- Se le colonne mancano, checkout e ordini danno errore: eseguire l'SQL prima del deploy.

## 2026-11-09 — «Candidati»: richiesta di ingresso con approvazione
- Con la registrazione solo su invito, chi non ha un invito può **candidarsi** da `/candidati` (pulsante «Candidati» al posto di «Registrati» nel menu, in `/accedi` e nell'avviso «Registrazione solo su invito»). Campi, su due colonne per non scorrere: nome e cognome, email e telefono, città, **interesse** (due pulsanti: Cliente oppure Incaricato alla vendita, obbligatorio), consenso privacy obbligatorio. Antirobot con campo nascosto e limite di 5 invii al minuto; una sola candidatura aperta per email (la risposta alla persona è sempre «Candidatura ricevuta»). Nessun account viene creato.
- Admin: *Rete → Candidature* (permessi `network.view` / `network.manage`; il numero in nav indica le candidature da valutare). **Approva**: scegli lo sponsor (facoltativo; senza, vale l'incaricato «casa» se impostato, altrimenti nessuno) e un messaggio facoltativo; la persona riceve l'email con il **link personale** `/registrazione?candidatura=…` (monouso, valido 30 giorni). **Rifiuta**: email cortese con il motivo, se scritto. **Rimanda email**: rinvia l'esito (per le approvate proroga il link di 30 giorni). Con «Invio email attivo» spento l'esito resta nel pannello (colonna *Email*) e si rimanda dopo.
- Dal link il modulo di registrazione si apre con nome, cognome ed email già compilati (l'email non è modificabile: il link vale per quella persona); a registrazione fatta la candidatura passa a *Registrato* e il link smette di funzionare. Chi ha scelto «incaricato alla vendita» lo vede in elenco ed è avvisato nell'email che potrà candidarsi come incaricato dalla sua area. Audit: `application.approve`, `application.reject`.
- Codice: `ApplicationService`, `ApplicationController`, `RegistrationGate::application`, `MembershipApplication`, notifiche `MembershipApplicationApproved/Rejected`, risorsa `MembershipApplicationResource`. Test: `MembershipApplicationTest`.
- Migrazione `2026_11_19_100000_membership_applications` (tabella nuova). Sul sito live (senza terminale) esegui **prima del deploy**, in phpMyAdmin → scheda SQL sul database `lamess_portale`:
  ```sql
  CREATE TABLE IF NOT EXISTS membership_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(40) NOT NULL,
    city VARCHAR(100) NOT NULL,
    province VARCHAR(4) NULL,
    interest VARCHAR(20) NOT NULL,
    message TEXT NULL,
    privacy_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    sponsor_id BIGINT UNSIGNED NULL,
    token VARCHAR(64) NULL,
    token_expires_at TIMESTAMP NULL,
    decision_note TEXT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    email_sent_at TIMESTAMP NULL,
    email_note VARCHAR(255) NULL,
    registered_user_id BIGINT UNSIGNED NULL,
    registered_at TIMESTAMP NULL,
    ip VARCHAR(45) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY membership_applications_email_index (email),
    KEY membership_applications_status_index (status),
    UNIQUE KEY membership_applications_token_unique (token),
    CONSTRAINT membership_applications_sponsor_id_foreign FOREIGN KEY (sponsor_id) REFERENCES consultants (id) ON DELETE SET NULL,
    CONSTRAINT membership_applications_reviewed_by_foreign FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT membership_applications_registered_user_id_foreign FOREIGN KEY (registered_user_id) REFERENCES users (id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  INSERT INTO migrations (migration, batch)
  SELECT '2026_11_19_100000_membership_applications', MAX(batch) + 1 FROM migrations;
  ```
- Se la tabella manca, `/candidati` dà errore all'invio: crearla prima del deploy.

## 2026-11-09 — Pagine personali: «Copia link di anteprima» per chi non ha accesso
- Nuovo pulsante **Copia link di anteprima** in *Assistenza → Pagine personali* (permesso `compliance.manage`): crea e copia un link firmato che vale **7 giorni** e mostra la pagina (con le modifiche in attesa) a chiunque lo riceva, anche senza essere collegato e anche a sito chiuso. Si apre su `/anteprima-pagina/{id}/condivisa?...`, è `noindex` e la foto è servita con un link firmato con la stessa scadenza. Scaduto o manomesso: errore 403. Ogni link creato è nel registro di audit (`page.preview_link`).
- Chiunque abbia il link lo può girare ad altri finché è valido: va dato solo a chi deve vedere la pagina. Il resto del sito resta chiuso; il link non dà accesso ad altro.
- Nessuna migrazione. Test: `PersonalPageTest` (2 test nuovi).

## 2026-11-09 — Registrazione solo su invito e negozio con prezzi solo dopo l'accesso
- **Registrazione solo su invito** (*Impostazioni di sistema → Sito*, acceso di default, `LAMESS_REGISTRATION_INVITE_ONLY`). Ci si registra soltanto da un link referral `/r/CODICE`, da `?ref=CODICE` o da un invito personale; senza, `/registrazione` mostra l'avviso «Registrazione solo su invito» (stato 403, `noindex`) e il POST è rifiutato sul server. Lo sponsor è quello del link: il campo «codice del tuo incaricato» non c'è più e un `sponsor_code` mandato a mano è ignorato. Vale solo il codice referral (casuale), non il codice attività LM00123 (consecutivo, indovinabile). Link sospeso o bannato: non vale. I pulsanti «Registrati» spariscono dalle pagine pubbliche per chi non ha un invito nel browser. Chi è già registrato accede come prima; admin e super admin continuano a creare utenti dal pannello.
- **Negozio visibile con prezzi solo dopo l'accesso** (*Impostazioni di sistema → Sito*, acceso di default, `LAMESS_SHOP_LOGIN_REQUIRED`). Chi non è collegato vede `/negozio` e le schede prodotto (restano indicizzabili da Google) ma senza prezzi, offerte, spedizione gratuita, filtro e ordinamento per prezzo, e senza dati `price` nei dati strutturati; al posto del carrello c'è «Accedi per acquistare», che dopo l'accesso riporta alla pagina di partenza (`/accedi?dopo=/percorso`, solo indirizzi interni). Il carrello (`/carrello*`) richiede il login (middleware `shop.login`, risposta 401 JSON per le chiamate del carrello a cassetto). Spariscono per i non collegati i link «Negozio» e «Carrello» da menu e piè di pagina. Gli utenti collegati vedono prezzi e acquistano come prima.
- Pagamenti: nessuna modifica. Stripe e KMoney ritornano su `/area/...` (già riservato) e i webhook `/webhooks/*` non passano dal controllo.
- Codice: `RegistrationGate`, `ReferralResolver::findByReferralCode`, `ShopAccess`, `ShopLoginRequired`, `SiteController`, `ShopController`, viste del sito e del negozio, `lamess-shop.js` (varianti senza prezzo). Test: `InviteOnlyRegistrationTest`, `ShopLoginRequiredTest`; in `phpunit.xml` i due interruttori sono spenti, così la suite esistente resta com'era.
- Nessuna migrazione. Per tornare al comportamento di prima basta spegnere i due interruttori in *Impostazioni di sistema → Sito* (o, senza pannello, in phpMyAdmin: tabella `system_settings`, righe `registration_invite_only` e `shop_login_required` con `value` = `false`).

## 2026-11-06 — Pagina personale: dal negozio si torna alla pagina dell'incaricato
- Chi arriva da `/i/{slug}` e va nel negozio o nel carrello vede una barra «← Torna alla pagina di {nome}». La pagina è ricordata in sessione (`pp_back`), la barra si può chiudere ed è nascosta in checkout, area riservata, back-office e anteprime. Se la pagina non è più online (incaricato sospeso, pagina nascosta) la barra sparisce.
- Codice: `PersonalPageService::backLink()`, `PersonalPageController::show`, `site/layout.blade.php`, stile in `lamess-front.css`. Nessuna migrazione.

## 2026-11-06 — Pagina personale: spiega tutto quello che direbbe l'incaricato a voce, con animazioni
- La pagina `/i/{slug}` ora racconta il prodotto come lo racconterebbe l'incaricato: «Ti spiego Lamèss» (cos'è e perché «caffè cosmetico»), sei ragioni, ingrediente per ingrediente con dosi e indicazioni autorizzate, «Cosa dice la ricerca» (con chiaro cosa lo studio non dimostra), «Per chi è» e avvertenze (caffeina, gravidanza, vegani, dose massima), Stop Time, come ordinare con spedizione, abbonamento, pagamenti ed estero, «Io e Lamèss» (cosa fa l'incaricato e chi c'è dietro il marchio), contatti, FAQ ampliata (16 domande) e invito finale a diventare incaricato.
- Animazioni leggere in SVG/CSS, senza librerie: chicchi che fluttuano nell'header, tazza con bustina che versa, numeri che salgono (dosi, studio, Consorzio), orologio Stop Time che rallenta fino a fermarsi. Tutte si fermano con «riduci movimento».
- I testi sono in `config/personal-page.php` e seguono il sito pubblico (integratore, dosi in etichetta, nessuna promessa di risultato, nessun nome di persona). I numeri del Consorzio (anni, soci, box) hanno la data nel testo: da aggiornare lì.
- Classi della pagina rinominate con prefisso `pp-` dove coincidevano con quelle del negozio (`.stat`, `.chips`, `.trust`). Nessuna migrazione.

## 2026-11-06 — Pagina personale: header di impatto e correzione «Come ordinare»
- Header a tutta altezza con frase d'impatto, nome grande con sfumatura oro, pulsante principale luminoso, punti di fiducia, foto in arco con sigillo e box del prodotto in primo piano (se l'incaricato non ha una foto, nell'arco compare la foto del prodotto). Ingresso animato, disattivato con «riduci movimento». Testi e immagine in `config/personal-page.php` (`hero_claim`, `hero_chips`, `product_image`).
- Corretto «Come ordinare»: le classi `.steps`, `.step`, `.num`, `.card`, `.tag`, `.lead` si sovrapponevano a quelle del negozio; ora quelle della pagina hanno il prefisso `pp-`.
- Nessuna migrazione.

## 2026-11-06 — Pagina personale dell'incaricato: nuovo look premium rivolto al cliente finale
- La pagina `/i/{slug}` è stata ridisegnata come sito one-page di alto livello (apertura scura con foto e sigillo «Incaricato LAMÈSS», fascia scorrevole, «Perché Lamèss» in sei ragioni, «Nella bustina» con le dosi in etichetta, Stop Time, «Come ordinare» in tre passi, storia e contenuti consigliati dell'incaricato, contatti con QR, FAQ) e termina con l'invito **«Ti piace Lamèss? Fanne parte»**: contatto via WhatsApp o email per diventare incaricato, senza cifre né promesse di guadagno. Barra fissa «WhatsApp / Registrati con me» su mobile; animazioni disattivate con «riduci movimento».
- I testi comuni (perché, dosi, passi, invito finale, FAQ, avvertenze) sono in `config/personal-page.php` e sono allineati al sito pubblico: integratore alimentare, dosi come in etichetta, nessuna promessa di risultato. Le chiavi precedenti (`pillars`, `product_title`, `product_text`, `banner_*`) sono sostituite.
- Visibilità invariata: la pagina si disattiva da sola se l'incaricato non è attivo o l'account è sospeso/cessato, oppure se il Consorzio la nasconde; resta valida la fase di prova «solo il Consorzio».
- Nessuna migrazione. Test: `PersonalPageTest` invariato (12 verdi).

## 2026-11-06 — Pagine personali: fase di prova «solo il Consorzio» e anteprima admin
- Nuova impostazione **Pagine personali: solo il Consorzio (fase di prova)** in *Impostazioni di sistema → Rete* (`LAMESS_PERSONAL_PAGES_STAFF_ONLY`, spenta di default). Accesa: la voce *La mia pagina* compare solo a chi ha accesso al back-office (permesso `compliance.view`), gli altri incaricati non vedono nulla e **nessuna pagina `/i/nome` è pubblica**, nemmeno se già approvata e online. Spenta: torna il normale flusso (incaricato → approvazione → online).
- Nuovo pulsante **Anteprima** in *Assistenza → Pagine personali*: apre `/anteprima-pagina/{id}` in una nuova scheda, con le modifiche in attesa, anche se la pagina non è approvata o non è online. La vede solo chi ha `compliance.view` (gli altri e i visitatori non collegati: 404 o accesso); la pagina è `noindex`. Serve che l'interruttore generale delle pagine sia acceso.
- Per preparare una pagina di prova: accendere l'interruttore generale e la fase di prova, aprire *La mia pagina* con un account incaricato e admin, compilare e salvare, poi guardarla da *Anteprima* nell'elenco. Quando si è pronti, spegnere la fase di prova e approvare/mettere online le pagine.
- Nessuna migrazione. Test: `PersonalPageTest` (2 test nuovi).

## 2026-11-05 — Permessi a tre livelli: vedere / modificare / creare ed eliminare
- Per ogni area con «gestire» c'è ora un terzo permesso **`area.create`** (rete, KYC, canoni, periodi, ordini, catalogo, pagamenti, assistenza, conformità, contenuti): **vedere** (`.view`) < **modificare** (`.manage`) < **creare ed eliminare** (`.create`, include modificare). Nella pagina *Ruoli e permessi* (matrice, profili, accessi degli utenti) compare come nuova riga sotto ogni «gestire». Le azioni delicate (Fai diventare cliente, Sposta sponsor, ecc.) restano solo del super_admin.
- Nel back-office il pulsante «Nuovo» e l'eliminazione dipendono da `.create`; la modifica da `.manage`. Piano compensi e «Funzionalità e viste» restano a due livelli.
- Per non togliere nulla a nessuno, chi ha già `.manage` (profili preimpostati, profili personalizzati, permessi diretti) riceve anche `.create`: poi il super_admin potrà togliere `.create` dove serve.
- **SQL da lanciare a mano in phpMyAdmin** (il deploy non lancia `migrate`; poi in *Ruoli e permessi* premere «Sincronizza»):
```sql
INSERT IGNORE INTO permissions (name, guard_name, created_at, updated_at) VALUES
('network.create','web',NOW(),NOW()),
('kyc.create','web',NOW(),NOW()),
('fees.create','web',NOW(),NOW()),
('periods.create','web',NOW(),NOW()),
('orders.create','web',NOW(),NOW()),
('catalog.create','web',NOW(),NOW()),
('payouts.create','web',NOW(),NOW()),
('support.create','web',NOW(),NOW()),
('compliance.create','web',NOW(),NOW()),
('content.create','web',NOW(),NOW());
INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
  SELECT c.id, rp.role_id FROM role_has_permissions rp JOIN permissions m ON m.id = rp.permission_id
  JOIN permissions c ON c.name = REPLACE(m.name, '.manage', '.create') AND c.guard_name = 'web' WHERE m.name IN ('network.manage', 'kyc.manage', 'fees.manage', 'periods.manage', 'orders.manage', 'catalog.manage', 'payouts.manage', 'support.manage', 'compliance.manage', 'content.manage');
INSERT IGNORE INTO model_has_permissions (permission_id, model_type, model_id)
  SELECT c.id, mp.model_type, mp.model_id FROM model_has_permissions mp JOIN permissions m ON m.id = mp.permission_id
  JOIN permissions c ON c.name = REPLACE(m.name, '.manage', '.create') AND c.guard_name = 'web' WHERE m.name IN ('network.manage', 'kyc.manage', 'fees.manage', 'periods.manage', 'orders.manage', 'catalog.manage', 'payouts.manage', 'support.manage', 'compliance.manage', 'content.manage');
```
- Tecnico: `Roles::PERMISSIONS` e `MATRIX`, `PermissionCatalog`, `GatedByPermission::createPermission()`, migrazione `2026_11_18_100000_permissions_create_level`.

## 2026-11-05 — Pulsante «Fai diventare cliente» sugli incaricati
- Nuovo pulsante **«Fai diventare cliente»** nel menu della riga di *Rete → Incaricati* e tra le azioni della scheda incaricato (motivo obbligatorio, finisce nel log; **solo super_admin**: l'admin non lo vede e, anche forzando la chiamata, l'azione viene rifiutata).
- **Senza storico economico**: l'incaricato esce da elenco e albero (come «Declassa»), i suoi diretti e i suoi clienti passano al suo sponsor, la persona resta cliente.
- **Con storico** (ordini, commissioni, prelievi…): non si cancella nulla. L'incarico è **cessato** (diretti al primo ascendente in essere, Reg. 2.8), l'utente perde il ruolo di incaricato, ha il ruolo cliente ed è assegnato a quell'ascendente; i suoi clienti passano allo stesso ascendente e i suoi percorsi nell'albero vengono tolti.
- Non occorre migrazione. Tecnico: `ConsultantConversion::makeCustomer`, `App\Filament\Support\MakeCustomerAction`, chiave `consultant360.action.make_customer`, test `tests/Feature/Network/MakeCustomerTest.php` (non ancora eseguito: PHP non disponibile nel terminale usato).

## 2026-11-02 — Pagina pubblica «Come funziona» per chi riceve il link referral
- Nuova pagina **/come-funziona** (sito vetrina): cinque passi, simulazione «Prova tu» (clienti e incaricati del team → percentuale, qualifica Pearl/Crystal, 1° livello della rete), tabelle di scaglioni e livelli, qualifiche con il colore della pietra, anteprima dell'area riservata con dati di esempio (Qualifica, Il mio ramo, Statino, Tasca), costi e disclaimer.
- I numeri sono letti dal **piano in vigore** (scaglioni, livelli, requisiti, importo minimo del cliente, canone): se si modificano dal pannello, la pagina si aggiorna. Se il piano in vigore non è quello Incaricati si usa il file `config/lamess/plan-incaricati-2026-10-08.json`.
- Il link referral resta: la pagina accetta `?ref=` e il cookie referral, e il pulsante «Registrati gratis» riporta alla registrazione con lo stesso sponsor.
- **Dalla registrazione (pagina referral) la presentazione non si raggiunge**: nessun link a `/come-funziona`, che resta una pagina a sé (la si condivide a parte). Nella registrazione c'è invece il popup **«Cosa c'è in una bustina?»** (riquadro nel pannello scuro a sinistra, link sotto «La registrazione è gratuita…» su telefono): cinque schede animate sui benefici del prodotto (caffè e integrazione nello stesso gesto, dosi in etichetta con numeri che crescono, caffè 100% Arabica AAA, zero zuccheri/senza glutine/senza lattosio, calda o fredda, abbonamento senza trappole), avanzamento automatico con **pausa**, frecce, tastiera e swipe; con «riduci animazioni» attivo non c'è movimento né avanzamento. Testi presi da «Perché Lamèss» e «Prodotto», con l'avvertenza «integratore, non un medicinale». Senza JavaScript il link porta a `/il-prodotto`.
- **«Lavora con noi» → «Come funziona»**: dalla pagina Lavora con noi ci sono il pulsante «Guarda come funziona» (scheda Incaricato) e un link nella frase sui compensi; la pagina resta pubblica e si può condividere a mano (`/come-funziona`). Nel popup della registrazione cadono piano dei **chicchi di caffè** (decorativi, si fermano con «Pausa» e spariscono con «riduci animazioni»).
- Popup benefici, altre due animazioni: nella 1ª scheda **una bustina bianca con fascia arancione si apre, versa nella tazza** (il caffè sale, poi parte il vapore; ciclo di 7 s); nella 4ª il riquadro **Calda ⇄ Fredda** passa da un fondo caldo (vapore) a uno freddo (cubetti di ghiaccio). Si fermano con «Pausa» (il passaggio del mouse ferma solo l'avanzamento delle schede); con «riduci animazioni» restano fermi (tazza piena, Calda e Fredda affiancate).
- **Importi in euro negli esempi spenti di default** (restano percentuali e qualifiche; nell'anteprima dello statino gli importi sono «€ •••»). Per mostrarli: `LAMESS_HOW_IT_WORKS_SHOW_EURO=true` nel `.env` (`config('lamess.how_it_works_show_euro')`).
- Tecnico: `HowItWorksData` (`App\Domain\Plan`), `SiteController::howItWorks`, vista `site/front/come-funziona.blade.php`, `public/css/lamess-how.css`, `public/js/lamess-how.js`, `@stack('head')` nel layout del sito vetrina, `@yield('side_extra')` nel layout della registrazione, `partials/benefits-dialog.blade.php`, `public/css/lamess-benefits.css`, `public/js/lamess-benefits.js`. Nessuna migrazione. Test: `HowItWorksPageTest`.

## 2026-11-02 — Bonifici in sospeso a fine mese e «Riapri ordine»
- **Bonifici in sospeso** (*Compensi → Bonifici in sospeso*, permesso `periods.manage`, nascondibile da *Funzionalità e viste*): prima del runtime ufficiale l'admin decide, **ordine per ordine**, che cosa fare dei bonifici ancora in attesa del mese: **Conferma nel mese** (le provvigioni valgono nel mese della data dell'ordine; incasso e fattura restano alla data reale), **Sposta al mese successivo** (l'ordine resta in attesa e la chiusura del mese non lo annulla) oppure **Annulla**. Ogni scelta chiede un motivo e finisce in cronologia ordine e nel registro audit (`period.transfer_confirmed`, `period.transfer_deferred`, `period.transfer_cancelled`). Si apre anche dal pulsante **Bonifici in sospeso (n)** nel dettaglio del mese.
- **Blocco del runtime ufficiale**: se il mese è già finito e restano bonifici non decisi, il runtime ufficiale non parte (la stima live sì) e il messaggio indica la pagina. Anche la chiusura automatica si ferma con lo stesso motivo (la vedi nel Cruscotto). Per un mese ancora in corso non cambia nulla. Il controllo riguarda solo i bonifici: carta e KMoney non completati si annullano alla chiusura come prima.
- **Riapri ordine** (solo `super_admin`, nella riga e nel dettaglio dell'ordine annullato, con motivo obbligatorio; audit `order.reopened`): l'ordine torna «In attesa di pagamento», si riprenotano le giacenze, si riattivano i codici sconto e i movimenti PV annullati tornano in attesa (nel loro mese, o nel mese corrente se quel mese è chiuso). Non si riapre se la merce non c'è più, se uno sconto è esaurito, se la quota KMoney è già stata riaccreditata o se l'ordine è di un abbonamento: in quei casi l'ordine resta annullato e un messaggio spiega perché.
- Tecnico: `PendingTransfers` (`App\Domain\Periods`), `OrderService::markPaid(..., $competenceAt)` (il mese delle provvigioni può differire dalla data di incasso), `OrderService::reopen()`, nuovo campo `orders.deferred_to_period`. Test: `PendingTransfersTest`, `PendingTransfersPageTest`, `ReopenOrderTest`.
- Migrazione `2026_11_17_100000_orders_deferred_to_period` (una colonna). Sul sito live (senza terminale) esegui in phpMyAdmin → scheda SQL sul database `lamess_portale`, meglio **prima del deploy** (una colonna vuota non rompe il codice vecchio):
  ```sql
  ALTER TABLE orders
    ADD COLUMN deferred_to_period VARCHAR(7) NULL,
    ADD INDEX orders_deferred_to_period_index (deferred_to_period);

  INSERT INTO migrations (migration, batch)
  SELECT '2026_11_17_100000_orders_deferred_to_period', MAX(batch) + 1 FROM migrations;
  ```

## 2026-11-02 — Panoramica e «Il mio ramo»: etichette, ordine fisso, barre, albero
- **Panoramica**: il riquadro «Ordini» si chiama **I miei ordini**; **Qualifica** compare sempre ed è sempre il primo dei tre riquadri Qualifica → Clienti personali → Incaricati diretti (prima spariva, e l'ordine cambiava, quando il mese non aveva ancora un calcolo). «Diretti Pearl» diventa **Incaricati diretti** con sottotitolo «Almeno Pearl al 1° livello».
- **Scaglioni dei compensi diretti**: il «prossimo scaglione» è il primo non raggiunto che paga **di più**; due scaglioni con la stessa percentuale non danno più «Ne mancano 3 per il 20%» / «sale dal 20% al 20%».
- **Pannello di avanzamento**: titolo **Verso la prossima qualifica** (a qualifica massima: «Qualifica massima raggiunta»); la riga «Qualifica» diventa **Qualifica attuale**; il requisito Pearl si chiama «Incaricati diretti almeno Pearl al 1° livello» senza la riga «Incaricati portati da te…»; nella scala delle qualifiche «raggiunta» diventa **già raggiunta**.
- **Barre di avanzamento**: quelle complete (requisito soddisfatto) apparivano grigie perché la classe `ok` prendeva lo stile del riquadro verde di conferma (sfondo chiaro e margini) e copriva il riempimento verde. Ora le barre usano la classe `done` e `.meter.ok` è neutralizzato. Nell'albero la barra vuota ha un contorno, per distinguerla dal grigio perla.
- **Albero personale**: la radice (tu) mostra anche i **clienti**, come gli altri nodi: «Pearl · tu · 3 clienti».
- Solo viste, testi e CSS/JS: nessuna migrazione. Test: `IncaricatiCareerProgressTest` (uno nuovo).

## 2026-10-30 — Pagine personali: interruttore in «Funzionalità e viste»
- Nuova voce **Pagine personali degli incaricati** (`area.personal_page`) in *Sistema → Funzionalità e viste*: spenta, sparisce il link «La mia pagina» dall'area dell'incaricato (anche per il super admin) e le pagine `/area/pagina` e `/i/nome` non si aprono. I contenuti restano. Prima la voce «Elenco Pagine personali» spegneva solo l'elenco del back-office, non il link dell'area. Vale insieme all'interruttore in Impostazioni di sistema → Rete (`LAMESS_PERSONAL_PAGES`). Nessuna migrazione.

## 2026-10-30 — Checkout: codice fiscale solo per l'Italia (campo in pagina)
- Il campo **Codice fiscale** del passo 1 (Fatturazione) è obbligatorio (asterisco e `required`) **solo se la nazione è Italia**; per le altre nazioni è facoltativo e l'etichetta dice «(solo per l'Italia)». Il controllo del server era già così: il modulo in pagina però lo imponeva a tutti, e un cliente estero non riusciva a salvare i dati.
- Nel checkout la **Nazione** di fatturazione resta modificabile anche con i dati già salvati (prima era bloccata): cambiandola si aggiorna il profilo e si ricalcolano spedizione e prodotti; gli altri dati restano bloccati. Senza una scelta nel checkout vale la nazione del profilo, non più sempre l'Italia.
- Una nazione vale solo se è tra i **mercati aperti** (`LAMESS_SHOP_COUNTRIES` nel `.env`, es. `IT,NL`): per le altre il checkout tratta il cliente come italiano. Il telefono resta obbligatorio per tutti. Nessuna migrazione. Test: `CheckoutBillingLockTest` (due nuovi).

## 2026-10-30 — Bonus di Struttura: dati fino alla domenica precedente
- Il calcolo settimanale usa solo i dati **fino alla fine dell'ultima domenica già trascorsa** (23:59:59, ora di Roma): un calcolo di lunedì o di mercoledì considera i dati fino a domenica sera, uno di domenica quelli fino alla domenica prima. Gli ordini registrati dopo entrano nel calcolo della settimana successiva.
- Nuova impostazione *Bonus di Struttura (settimanale) → Dati considerati fino a* (`lamess.structure_bonus_cutoff_weekday`, default 7 = domenica; 0 = nessun taglio). Vale anche per **Calcola ora**; la pagina mostra la data del taglio. La chiusura del mese aggiorna il registro con tutti i dati del mese. I bonus già confermati non cambiano.
- Tecnico: `SnapshotBuilder::build()` ha il nuovo parametro `cutoff`; `StructureBonusService::cutoff()`. Nessuna migrazione. Test: `StructureBonusTest` (due nuovi).

## 2026-10-30 — Checkout da telefono: campi affiancati
- Passo 1 (Fatturazione): **codice fiscale e telefono sulla stessa riga**, come nome/cognome, città/CAP e provincia/nazione (la partita IVA delle aziende va su una riga sua). Sotto i 400 px il carattere dei due campi si riduce un poco perché i 16 caratteri del codice fiscale ci stiano; sotto i 340 px vanno uno sotto l'altro.
- Da telefono: campi alti 46 px con carattere da 16 px (il telefono non ingrandisce la pagina quando si tocca un campo), pulsanti «Salva i dati» e «Avanti» a tutta larghezza, margini ridotti. Il codice fiscale si scrive in maiuscolo da solo, senza correzione automatica. Solo grafica: nessuna migrazione.

## 2026-10-30 — Checkout: codice fiscale e telefono obbligatori
- Per confermare un ordine o un abbonamento servono **codice fiscale** (per chi è in Italia, ora anche le aziende) e **telefono** (per tutti). Prima il telefono non era richiesto e il codice fiscale solo ai privati.
- Il telefono è un nuovo campo del passo 1 (Fatturazione), si salva col profilo («Salva i dati») e finisce anche nei dati di fatturazione dell'ordine. Chi ha già il profilo senza telefono, al prossimo checkout deve inserirlo e salvare; il checkout non va avanti finché manca. Gli ordini creati a mano dall'admin e i rinnovi degli abbonamenti non cambiano.
- Corretto subito dopo: il campo codice fiscale del checkout compariva vuoto anche con il dato salvato (virgolette in più nel modulo).
- Nessuna migrazione. Test: `ShopPagesTest` (due nuovi), `tests/Support/ShopDb` con telefono di prova.

## 2026-10-30 — Etichette di spedizione con email e telefono
- Sull'etichetta, sotto l'indirizzo, ora compaiono **telefono** (della consegna, altrimenti del profilo del cliente) ed **email** del cliente. Formato invariato (8 per foglio A4). Nessuna migrazione. Test: `ShippingLabelsTest`.

## 2026-10-28 — Pagina personale dell'incaricato (`/i/nome`)
- Nuovo: ogni incaricato attivo ha una **pagina pubblica personale** sul modello della pagina «brand partner» (hero con nome, qualifica e foto, presentazione, il prodotto, banner *Stop Time*, «La mia storia», video e articoli consigliati, contatti con WhatsApp ed email, social, QR di registrazione, FAQ e avvertenze). Indirizzo `https://lamèss.it/i/{slug}`; lo slug nasce dal nome e l'incaricato lo può cambiare.
- **Chi modifica cosa**: l'incaricato modifica da *Area → La mia pagina* (nome da mostrare, qualifica, presentazione, foto, storia, WhatsApp, email, 4 social, fino a 3 contenuti consigliati, indirizzo) con **Anteprima**, «Metti online» e «Togli dal pubblico» (con conferma). Le parti comuni (descrizione del prodotto, 4 punti di forza, banner, FAQ, avvertenze) sono del Consorzio e stanno in `config/personal-page.php`: uguali per tutti, non modificabili dall'incaricato.
- **Approvazione** (impostazione *Le pagine personali vanno approvate dal Consorzio*, accesa di default, `LAMESS_PERSONAL_PAGES_APPROVAL`): ogni salvataggio va in `proposal` e il pubblico continua a vedere la versione precedente; la pagina non è online finché non c'è una prima approvazione. Spenta: le modifiche vanno online subito.
- **Back-office**: *Assistenza → Pagine personali* (`ConsultantPageResource`, permessi `compliance.view`/`compliance.manage`, nascondibile da *Funzionalità e viste*): **Approva**, **Non approvare** (con motivo, lo legge l'incaricato), **Modifica testi** (vanno online subito), **Nascondi** / **Riattiva**. Badge col numero di pagine da approvare.
- Interruttore generale *Pagine personali degli incaricati* in Impostazioni di sistema → Rete (`LAMESS_PERSONAL_PAGES`): spento, le pagine non si aprono e la voce sparisce dall'area; i contenuti restano.
- **Chi non vede la pagina**: incaricati non attivi (cessati, in attesa), account sospesi o bannati, pagine nascoste dal Consorzio o non approvate. La foto si serve da `/media/pagine/{id}/{file}`: pubblica solo se la pagina è online, altrimenti solo per il proprietario e per chi ha `compliance.view`. Testi sempre escapati; link solo http/https; i link esterni hanno `nofollow`.
- Il pulsante «Registrati con me» porta a `/r/{codice}`: stesso tracciamento del link referral (cookie, 15 giorni). Contatore di visite sulla pagina. Audit: `page.submitted`, `page.updated`, `page.approved`, `page.rejected`, `page.blocked`, `page.unblocked`, `page.online`, `page.offline`, `page.slug_changed`.
- Migrazione `2026_11_16_100000_consultant_pages` (tabella nuova). Sul sito live (senza terminale) esegui **prima del deploy**, in phpMyAdmin → scheda SQL sul database `lamess_portale`:
  ```sql
  CREATE TABLE IF NOT EXISTS consultant_pages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    consultant_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(60) NOT NULL,
    display_name VARCHAR(120) NULL,
    headline VARCHAR(160) NULL,
    bio TEXT NULL,
    story TEXT NULL,
    photo_path VARCHAR(255) NULL,
    whatsapp VARCHAR(30) NULL,
    email_public VARCHAR(190) NULL,
    instagram VARCHAR(300) NULL,
    facebook VARCHAR(300) NULL,
    tiktok VARCHAR(300) NULL,
    linkedin VARCHAR(300) NULL,
    media LONGTEXT NULL,
    proposal LONGTEXT NULL,
    review VARCHAR(12) NOT NULL DEFAULT 'none',
    review_note TEXT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    blocked TINYINT(1) NOT NULL DEFAULT 0,
    blocked_note TEXT NULL,
    approved_at TIMESTAMP NULL,
    decided_at TIMESTAMP NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY consultant_pages_consultant_id_unique (consultant_id),
    UNIQUE KEY consultant_pages_slug_unique (slug),
    KEY consultant_pages_enabled_blocked_index (enabled, blocked),
    CONSTRAINT consultant_pages_consultant_id_foreign FOREIGN KEY (consultant_id) REFERENCES consultants (id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  INSERT INTO migrations (migration, batch)
  SELECT '2026_11_16_100000_consultant_pages', MAX(batch) + 1 FROM migrations;
  ```
- Dopo il deploy, per l'**immagine** non serve altro (le foto stanno in `storage/app/private/pages`, come gli altri file riservati). Se la tabella manca, «La mia pagina» dà errore: crearla prima del deploy.
- Test: `PersonalPageTest`.

## 2026-10-28 — Ruoli e permessi in dettaglio
- Corretto: «Agenti regalati» (Compensi) non compariva tra le «Voci di menu visibili» di un utente perché mancava nel `FeatureRegistry`. Ora c'è (`backoffice.resource.rank_helps`) e un test verifica che ogni risorsa del back-office abbia la sua voce.
- Nuovo: pagina **Sistema → Ruoli e permessi** (solo super_admin, slug `ruoli-permessi`, `RolesPermissionsPage`). Tre viste: **Matrice dei permessi** (ogni permesso per area, con cosa sblocca, se è sensibile e quali profili lo includono), **Profili** (preimpostati e personalizzati) e **Accessi degli utenti** (per ogni admin: profilo corrispondente, numero di permessi e di sensibili, voci di menu visibili su totali, 2FA, avvisi; il «Dettaglio» elenca permessi per area e menu visibile/nascosto). Esportazioni CSV della matrice e degli accessi (`;`, UTF-8 con BOM, celle protette dalle formule); pulsante **Sincronizza permessi**.
- **Profili personalizzati**: il super_admin li crea, copia (anche da un preimpostato), modifica e cancella. Sono modelli: scegliendoli in Utenti i permessi si copiano sull'admin e restano ritoccabili; cambiarli o cancellarli non tocca chi li ha già usati. Sono ruoli spatie con prefisso `profilo:` mai assegnati agli utenti e fuori da `Roles::backoffice()` (non aprono il pannello): nessuna tabella nuova, **nessuna migrazione, nessun SQL**. Log di audit: `access.profile_created|updated|deleted`, `access.roles_synced`, `access.matrix_exported`, `access.users_exported`.
- **Utenti → permessi** raggruppati per area (Cruscotto, Rete, Compensi, Vendite, Assistenza, Sistema), ognuno con la spiegazione di ciò che sblocca e il segno «sensibile»; «Parti da un profilo» ora offre anche i profili personalizzati. **«Gestire» include sempre «vedere»**: spuntando il primo si accende il secondo, e il servizio lo applica anche lato server (`PermissionCatalog::close()`), in creazione e in modifica.
- Nuovi permessi di sola lettura: `catalog.view` (prodotti, categorie, sconti, bundle, tariffe), `content.view` (novità, materiali, eventi, registro download), `kyc.view`, `fees.view`. Prima per vedere queste liste serviva il permesso di gestione. Le liste si aprono con «vedere» **oppure** «gestire», quindi gli admin già creati non perdono niente. I profili preimpostati restano come prima, con il solo «vedere» aggiunto dove hanno già «gestire» (il profilo *Sola lettura (direzione)* non cambia).
- **Senza terminale**: salvare i permessi di un admin crea nel database i permessi nuovi che mancano (`Roles::sync()`), quindi dopo un rilascio che ne aggiunge non serve più `lamess:ruoli` (resta il pulsante **Sincronizza permessi**). Chiude il punto aperto sui permessi nuovi di OPEN-50 (le migrazioni restano a mano).
- `lamess:admin-user --preset=` accetta anche un profilo personalizzato (`"profilo:Nome"`).
- Nessuna migrazione, nessun SQL. Dopo il deploy: aprire **Ruoli e permessi → Sincronizza permessi** (o salvare un admin) per creare i 4 permessi nuovi. Test: `PermissionCatalogTest`, `AccessProfilesTest`, `RolesPermissionsPageTest`.

## 2026-10-28 — Pagina «Compensi del mese» e login con la «è»
- Nuovo: pagina **Compensi → Compensi del mese** (permesso `payouts.view`, nascondibile da *Funzionalità e viste*, voce `compensi_mese`). Mostra, per ogni incaricato, quanto sta maturando nel mese scelto (di default quello in corso): qualifica (con «↑ da …» se è salito rispetto al mese prima), importo per ogni tipo di compenso, **Bonus di Struttura**, **Totale**, **Trattenuto**, **Saldo non ancora pagato** e se è **pagabile** (IBAN presente e KYC verificato). In alto i totali del mese.
- **Mese aperto**: è la stima dell'ultimo calcolo, si aggiorna a ogni ordine, pagamento o movimento e può cambiare fino alla chiusura. **Mese chiuso**: importi definitivi, con il link allo **Statino** di ciascuno (nei mesi aperti il link porta alla scheda). Se per il mese non c'è nessun calcolo, la pagina rimanda a *Periodi → Stima (live)*.
- Filtri: mese (ultimi 24), ricerca per nome o codice, «solo chi ha importi» (acceso), ordine per totale, nome o trattenuto. **Esporta CSV** (separatore `;`, con le colonne dei tipi di compenso e «Pagabile»). Sola lettura: non scrive nulla.
- Login del back-office: ora accetta anche l'email con la «è» (`nome@lamèss.it`) da iPhone/Safari, che rifiutava il campo email standard; il dominio è convertito in punycode (`xn--lamss-6ra.it`) prima del controllo. Nessuna migrazione, nessun SQL.
- Clienti: colonne più ravvicinate e icone azione più distanziate.

## 2026-10-28 — Statistiche del sito
- Nuovo: pagina **Statistiche sito** (*Sistema*, permesso `kpi.view`, nascondibile da *Funzionalità e viste*). Per il **sito pubblico e l'area riservata** e per il **back-office**: utenti **online adesso** (chi sono, in quale pagina, da dove arrivano, dispositivo e luogo, aggiornamento ogni 30 secondi), visite e pagine viste con confronto sul periodo precedente, grafico giornaliero, pagine più viste e d'ingresso, **ricerche nel negozio** (anche quelle senza risultati), **come arrivano** (Google, social, email, altri siti, campagne UTM, link referral degli incaricati), paesi e città, dispositivi, browser, sistemi, ultime visite; nel back-office anche chi ha lavorato e cosa ha usato.
- Rilevamento interno (nessun servizio esterno, nessun cookie in più): niente bot, immagini o richieste interne; IP troncato; cancellazione automatica dopo 180 giorni (`LAMESS_ANALYTICS_RETENTION_DAYS`, ogni notte alle 03:10); spegnibile con `LAMESS_ANALYTICS=false`. Le visite dello staff nel sito pubblico sono escluse di default.
- Limiti: il **paese e la città** si vedono solo se l'hosting li comunica (es. Cloudflare); le **ricerche dentro il back-office** non si registrano; il sito chiuso al pubblico mostra solo le visite dello staff. Conviene citare le statistiche anonime nell'informativa privacy.
- Migrazione `2026_11_15_100000_site_analytics` (due tabelle nuove). Sul sito live (senza terminale) esegui **prima del deploy**, in phpMyAdmin → scheda SQL sul database `lamess_portale`. Se la dimentichi il sito funziona lo stesso: la pagina avvisa che mancano le tabelle e non registra nulla finché non le crei.
  ```sql
  CREATE TABLE IF NOT EXISTS analytics_visits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vid VARCHAR(26) NOT NULL,
    started_at TIMESTAMP NULL DEFAULT NULL,
    last_seen_at TIMESTAMP NULL DEFAULT NULL,
    user_id BIGINT UNSIGNED NULL,
    is_staff TINYINT(1) NOT NULL DEFAULT 0,
    landing_area VARCHAR(5) NOT NULL,
    landing_path VARCHAR(255) NOT NULL,
    current_area VARCHAR(5) NOT NULL,
    current_path VARCHAR(255) NOT NULL,
    referrer_host VARCHAR(120) NULL,
    source VARCHAR(40) NOT NULL,
    medium VARCHAR(20) NULL,
    campaign VARCHAR(120) NULL,
    referral_code VARCHAR(40) NULL,
    country VARCHAR(2) NULL,
    region VARCHAR(80) NULL,
    city VARCHAR(80) NULL,
    device VARCHAR(10) NOT NULL,
    browser VARCHAR(30) NOT NULL,
    os VARCHAR(30) NOT NULL,
    ip_masked VARCHAR(45) NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY analytics_visits_vid_unique (vid),
    KEY analytics_visits_started_at_index (started_at),
    KEY analytics_visits_last_seen_at_index (last_seen_at),
    KEY analytics_visits_user_id_index (user_id),
    KEY analytics_visits_source_index (source)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE IF NOT EXISTS analytics_hits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    area VARCHAR(5) NOT NULL,
    path VARCHAR(255) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    search_term VARCHAR(120) NULL,
    search_results INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY analytics_hits_visit_id_index (visit_id),
    KEY analytics_hits_created_at_index (created_at),
    KEY analytics_hits_area_created_at_index (area, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  INSERT INTO migrations (migration, batch)
  SELECT '2026_11_15_100000_site_analytics', MAX(batch) + 1 FROM migrations;
  ```
- Test: `SiteStatsTest` (14).

## 2026-10-27 — Bonifici fuori area SEPA e BIC/SWIFT
- Nuovo campo **BIC/SWIFT** su incaricato, pagamento e richiesta di prelievo (copiato al momento della richiesta). È **obbligatorio se l'IBAN è fuori area SEPA**; il formato è controllato. Compare nei moduli IBAN (area incaricato, creazione e modifica admin) e nel CSV dei bonifici.
- Il **file SEPA (XML)** esclude i pagamenti con IBAN fuori area SEPA: restano da pagare, sono elencati in un avviso all'admin e in un **CSV a parte** («Bonifici fuori area SEPA») da fare a mano in banca. Senza BIC un pagamento con IBAN extra-SEPA **non viene preparato** (resta bloccato come per KYC/IBAN mancanti).
- IBAN: aggiunte le lunghezze per TN, MA, EG, TR, AE, SA e l'elenco dei paesi SEPA.
- Migrazione `2026_11_13_100000_beneficiary_bic`: sul sito live (senza terminale) esegui **prima del deploy**, in phpMyAdmin → scheda SQL sul database `lamess_portale`:
  ```sql
  ALTER TABLE `consultants` ADD COLUMN `bic` VARCHAR(11) NULL AFTER `iban_holder`;
  ALTER TABLE `payouts` ADD COLUMN `bic` VARCHAR(11) NULL AFTER `iban_holder`;
  ALTER TABLE `withdrawal_requests` ADD COLUMN `bic` VARCHAR(11) NULL AFTER `iban_holder`;
  INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_11_13_100000_beneficiary_bic', MAX(`batch`) FROM `migrations`;
  ```
  Senza le colonne il salvataggio dell'IBAN e la preparazione dei pagamenti danno errore.

## 2026-10-27 — «Forza qualifica» fino a una data o a revoca
- Il modulo **Forza qualifica** permette ora di mantenere la qualifica **fino a una data** oppure **fino a revoca** (usa la stessa protezione di «Mantieni qualifica», con log). Storico e tabella «Qualifiche raggiunte» riconoscono la fonte «forzata» (prima la forzatura non risultava).
- Dopo la forzatura o la protezione il calcolo live riparte subito: badge e albero rete leggono il nuovo risultato senza aspettare il ricalcolo notturno delle 02:45. Nessuna migrazione.

## 2026-10-27 — Sospendere o bannare un account
- Nuovo: ogni account (cliente, incaricato o admin) si può **sospendere** (stop temporaneo) o **bannare** (blocco deciso), con motivo obbligatorio. Si riattiva quando si vuole.
- Effetti: non può più entrare (sito e back-office; le sessioni aperte si chiudono alla richiesta successiva); non compare più in elenchi, alberi (admin e «Il mio ramo»), conteggi di tab e link referral; i suoi abbonamenti non vengono più addebitati. Non si cancella nulla: ordini, fatture, compensi e storico restano, e la rete non si spezza (i suoi sotto-incaricati si vedono sotto il primo sponsor ancora visibile). Chi prova ad accedere, con la password giusta, vede «account sospeso/bloccato».
- Dove: azione **Sospendi o banna** / **Riattiva account** nel menu della riga di Incaricati, Clienti e Utenti. I bloccati si ritrovano col filtro **Account** (predefinito «Attivi»; opzioni Sospesi, Bannati, Sospesi e bannati) e hanno il badge nella colonna **Account**. Chi può: super_admin per tutti tranne altri super_admin; admin con permesso `network.manage` solo per incaricati e clienti; nessuno su se stesso. Ogni azione è nel log di audit (`user.suspended`, `user.reinstated`).
- Diverso dallo stato «Sospeso» dell'incaricato (rete/sanzioni, che ferma solo i compensi): quello resta com'era.
- Migrazione `2026_11_14_100000_account_suspension`: sul sito live (senza terminale) esegui **prima del deploy**, in phpMyAdmin → scheda SQL sul database `lamess_portale`:
  ```sql
  ALTER TABLE users
    ADD COLUMN suspended_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN suspension_type VARCHAR(16) NULL,
    ADD COLUMN suspension_reason VARCHAR(500) NULL,
    ADD COLUMN suspended_by BIGINT UNSIGNED NULL,
    ADD INDEX users_suspended_at_index (suspended_at);
  INSERT INTO migrations (migration, batch)
  SELECT '2026_11_14_100000_account_suspension', MAX(batch) + 1 FROM migrations;
  ```
  Senza le colonne il codice nuovo dà errore (link referral, abbonamenti, elenchi).
- Test: `AccountSuspensionTest` (9).

## 2026-10-26 — Lettera di incarico a versioni e Regolamento incaricati modificabili
- **Rete → Lettera di incarico**: l'admin modifica il testo (base in `resources/legal/ivd-letter-default.html`, con segnaposto) distinguendo il **refuso** (stessa versione, nessuna nuova firma) dalla **modifica contrattuale** (nuova versione da far firmare). Pulsante **Invia da firmare** agli incaricati registrati: tutti, solo attivi o selezione manuale; promemoria, storico delle modifiche e stato delle firme per versione. Il contratto IVD già firmato non si tocca.
- **Area incaricato**: pagina `/area/lettera` per firmare la nuova versione con codice OTP, PDF firmato e avviso nella panoramica. Pagina di firma a tutta larghezza, prima la richiesta del codice e poi l'OTP; le dichiarazioni dell'art. 12 si compilano solo nel modulo e non si ripetono nel testo.
- **Rete → Regolamento incaricati**: stesso meccanismo (refuso / nuova versione, storico) per il Regolamento, che l'incaricato accetta prima di firmare la lettera. Funzione nascondibile `regolamento_incaricati` (e `lettera_incarico` per la lettera).
- Migrazioni `2026_11_11_100000_ivd_letter_versions` e `2026_11_12_100000_legal_documents`: sul sito live esegui **prima del deploy**, in phpMyAdmin → SQL. La prima versione della lettera si crea da sola al primo salvataggio o al primo «Invia da firmare».
  ```sql
  CREATE TABLE IF NOT EXISTS `ivd_letter_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `version` VARCHAR(30) NOT NULL,
    `body` LONGTEXT NOT NULL,
    `change_summary` TEXT NULL,
    `articles_1341` TEXT NULL,
    `is_current` TINYINT(1) NOT NULL DEFAULT 0,
    `revision` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `published_at` DATETIME NULL,
    `published_by` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ivd_letter_versions_version_unique` (`version`),
    KEY `ivd_letter_versions_is_current_index` (`is_current`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  CREATE TABLE IF NOT EXISTS `ivd_letter_edits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ivd_letter_version_id` BIGINT UNSIGNED NOT NULL,
    `kind` VARCHAR(20) NOT NULL,
    `summary` TEXT NULL,
    `body_before` LONGTEXT NULL,
    `words_changed` INT UNSIGNED NOT NULL DEFAULT 0,
    `actor` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `ivd_letter_edits_ivd_letter_version_id_foreign` FOREIGN KEY (`ivd_letter_version_id`) REFERENCES `ivd_letter_versions` (`id`) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  CREATE TABLE IF NOT EXISTS `ivd_letter_signatures` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ivd_letter_version_id` BIGINT UNSIGNED NOT NULL,
    `consultant_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `sent_by` VARCHAR(255) NULL,
    `sent_at` DATETIME NULL,
    `last_reminder_at` DATETIME NULL,
    `viewed_at` DATETIME NULL,
    `signature_provider` VARCHAR(255) NULL,
    `signature_ref` VARCHAR(255) NULL,
    `signed_at` DATETIME NULL,
    `evidence` JSON NULL,
    `pdf_path` VARCHAR(255) NULL,
    `pdf_hash` VARCHAR(64) NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ivd_letter_signatures_version_consultant_unique` (`ivd_letter_version_id`, `consultant_id`),
    KEY `ivd_letter_signatures_user_id_status_index` (`user_id`, `status`),
    CONSTRAINT `ivd_letter_signatures_ivd_letter_version_id_foreign` FOREIGN KEY (`ivd_letter_version_id`) REFERENCES `ivd_letter_versions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `ivd_letter_signatures_consultant_id_foreign` FOREIGN KEY (`consultant_id`) REFERENCES `consultants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `ivd_letter_signatures_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  CREATE TABLE IF NOT EXISTS `legal_documents` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(40) NOT NULL,
    `version` VARCHAR(30) NOT NULL,
    `body` LONGTEXT NOT NULL,
    `articles_1341` TEXT NULL,
    `change_summary` TEXT NULL,
    `revision` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_by` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `legal_documents_slug_unique` (`slug`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  CREATE TABLE IF NOT EXISTS `legal_document_edits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `legal_document_id` BIGINT UNSIGNED NOT NULL,
    `kind` VARCHAR(20) NOT NULL,
    `version` VARCHAR(30) NOT NULL,
    `summary` TEXT NULL,
    `body_before` LONGTEXT NULL,
    `actor` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `legal_document_edits_legal_document_id_foreign` FOREIGN KEY (`legal_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_11_11_100000_ivd_letter_versions', MAX(`batch`) FROM `migrations`;
  INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_11_12_100000_legal_documents', MAX(`batch`) FROM `migrations`;
  ```
  Senza le tabelle la pagina di firma della lettera e le pagine Lettera/Regolamento danno errore.
- Test: `IvdLetterVersionsTest`, `AreaPagesTest`.

## 2026-10-26 — Il mio ramo, guadagni provvisori e ritocchi dell'area
- **Il mio ramo**: l'albero sta sopra l'elenco; l'elenco ha filtri (livello, qualifica, tipo) e ordinamento.
- **Guadagni provvisori**: la panoramica e la scheda mostrano la stima dei compensi del mese in corso, che si aggiorna a ogni ordine, pagamento, reso o movimento (ricalcolo live in coda, `LiveRecalcDispatcher`), senza aspettare il ricalcolo notturno. Test `ProvisionalEarningsTest`.
- **Registrazione**: codice fiscale facoltativo con controllo di formato; in «I miei dati» è visibile anche da mobile e, se vuoto, mostra «Non indicato».
- Ai campi password del sito è stato aggiunto l'**occhiolino** per mostrare/nascondere la password; il portale (utenti e admin) resta sempre sul **tema chiaro**.
- PDF fattura: non va più in errore se il cliente non ha partita IVA o codice fiscale.

## 2026-10-26 — Avviso «Nuovo ordine» e cruscotto più leggero
- Alla creazione di ogni ordine parte una email a tutti gli utenti **admin e super admin**, oppure agli indirizzi in `LAMESS_ORDINI_MAIL_TO` (separati da virgola) se impostato. Non blocca mai l'ordine e rispetta `LAMESS_MAIL_ENABLED`.
- Cruscotto: lo snapshot dei KPI resta in **cache per 60 secondi** (per periodo) e i widget si caricano con la pagina, senza il refresh ogni 5 secondi di Filament (con quattro widget erano circa 48 richieste PHP al minuto per scheda aperta e l'hosting condiviso rispondeva 502 su `livewire/update`). Nessuna migrazione.

## 2026-10-26 — Dati di pagamento via email e pulsante «Invia di nuovo»
- Prima: con il bonifico l'IBAN si vedeva solo nella pagina dell'ordine; nessuna email al cliente alla registrazione dell'ordine (l'unica email di pagamento era «pagamento ricevuto»).
- Ora: nuova email `OrderPaymentDetails` con IBAN/istruzioni, importo e causale per il bonifico, oppure link all'ordine per pagare con carta o confermare KMoney (anche in due passi KMoney + resto). Parte da sola quando il cliente conferma un ordine con bonifico (o sceglie il bonifico per il resto dopo KMoney); un errore di invio non blocca mai l'ordine.
- Admin: azione **Invia di nuovo i dati di pagamento** sugli ordini in attesa (elenco e scheda ordine), con conferma. Rifiuta con un messaggio chiaro se le email sono spente, se manca l'email del cliente o se l'IBAN è ancora «da configurare». Ogni invio lascia una nota interna in cronologia.
- Il link porta alla pagina dell'ordine (accesso con login), dove ci sono IBAN e pulsanti di pagamento: non è un link diretto a Stripe.
- Test: `test_bank_transfer_order_emails_the_payment_details_to_the_customer`, `test_card_order_does_not_send_bank_details`, `test_payment_details_can_be_resent_from_the_panel`, `test_payment_details_are_not_sent_without_iban_or_with_email_off`, `test_payment_details_action_is_hidden_on_paid_orders`. Nessuna migrazione.

## 2026-10-24 — Ticket: si vede chi li crea
- Elenco admin: colonna **Creato da** (nome + «Incaricato LM…» / «Cliente»), ricercabile per nome ed email; «Incaricato» e «Aperto il» disponibili come colonne facoltative; nuovi filtri «Non presi in carico» e «In carico a me»; caricamento dei dati senza query ripetute.
- Scheda ticket: creatore, data di apertura e «In carico a»; i messaggi mostrano il nome dell'autore (un super admin resta «Assistenza» per gli altri admin).
- Prima il ticket di un cliente o collaboratore (senza codice incaricato) mostrava «—» come unico riferimento.
- Test: `test_back_office_sees_who_created_the_ticket`. Nessuna migrazione.

## 2026-10-23 — Lettera di Incarico «Definitiva» Kadeo Group, dichiarazioni e accettazioni
- Testo: la lettera del Consorzio (16 articoli, premesse, art. 12 dichiarazioni, art. 13 privacy e immagine) sostituisce il riassunto di una pagina. Un solo testo (`resources/views/legal/ivd-letter-body.blade.php`) per la pagina pubblica `/lettera-di-incarico`, la schermata di firma e il PDF. Modello versione `2026-2`.
- Refusi del file Word corretti nel portale: indirizzi `kadeogrou.it`, `kadeogropu.it`, `kadeogroup/support` → `www.kadeogroup.it`, «Conorzio» → «Consorzio», «Via G. ferraris» → «Via G. Ferraris». Il canone dell'art. 2 si legge dall'impostazione del portale (non più «19,90» fisso) e, se spento, la frase cambia in «senza alcun canone annuale».
- Firma: nella schermata di firma, prima dell'OTP, ci sono le dichiarazioni dell'art. 12 (reddito, previdenza, pensione, mono/plurimandatario) e le accettazioni del blocco finale della lettera (lettera, artt. 1341-1342 della lettera, Regolamento, artt. 1341-1342 del Regolamento, Piano compensi, informativa GDPR). Obbligatorie: senza, la firma non parte. Facoltativa la liberatoria foto/video (pagina `/liberatoria-foto`, testo inglese del Consorzio).
- Prove: dichiarazioni e accettazioni (con versioni dei documenti e orario) restano in `evidence` del contratto; il PDF le riporta con ☒/☐ e la firma del Consorzio (`public/img/firma-consorzio.png`); il certificato di firma le elenca. Nessuna migrazione.
- Regolamento: resta la v2.0 (stessa numerazione della v1.1 allegata, ma con «Incaricato» e Piano Incaricati); gli articoli citati nelle caselle 1341-1342 coincidono.
- Test: `IvdLetterAcceptancesTest` (6) e adeguati `SitePagesTest` e `KycAndFeesTest`.

## 2026-10-23 — Indirizzo di consegna incompleto
- Causa: un ordine con indirizzo di spedizione «Piano 1» (senza via) stampava l'etichetta senza via; il portale salvava quanto scritto senza controlli.
- `AddressCheck`: serve via e numero civico (o «snc»); usato da rubrica indirizzi, checkout (nuovo indirizzo, indirizzo salvato, «come la fatturazione») e modifica indirizzo.
- Indirizzi già salvati e incompleti: si fermano al momento dell'ordine con il messaggio di correggerli in «I miei dati».
- Azione admin «Modifica indirizzo di consegna» sugli ordini non ancora spediti, con nota in cronologia.
- Etichette: formato unico, quello di 8 per foglio A4; l'etichetta singola occupa la prima casella (prima era una pagina A6 con caratteri grandi).
- Test: `DeliveryAddressTest` (14). Nota: 9 test su abbonamenti e audit dei prodotti falliscono anche senza queste modifiche (già presenti).

## 2026-10-01 — E1/E2/E3: motore di calcolo
- Motore puro PHP in `App\Domain\Engine`, piano ufficiale prelancio in JSON, registro politiche D01–D12.
- Test: 52 (carriera, bonus, scenari golden G01–G26 coperti salvo G21/G22/G24, proprietà), 1362 asserzioni, tutti verdi; Pint pulito.
- Comando `php artisan lamess:parametri`.
- Manuali iniziali, specifica, architettura, decisioni aperte.
- Non ancora: persistenza, Fortify/2FA, ruoli, Filament, interfacce, `InvoiceSink`/GestionaleK, FSB (sospeso).
- Nota: Pest/Larastan non installati (rete cloud); da aggiungere sul PC.

## 2026-10-01 — Persistenza e ciclo del periodo
- Migrazioni: consulenti, versioni piano, periodi, movimenti PV, run di calcolo, righe commissione, statistiche, qualifiche, premi e rate Rank Bonus.
- `SnapshotBuilder` (DB → motore) e `PeriodService` (apri / live / runtime / chiudi) con lock, checklist di chiusura, immutabilità (G22, G23, G24, G19 su DB).
- `PvLedger`, seeder del piano ufficiale, comando `lamess:periodo`.
- Test: 61 (54 + 7 feature su SQLite), tutti verdi.
- Non ancora: utenti/login, interfaccia admin, import ordini, settlement/wallet (F5), rete con closure table e cessazioni (F1), FSB.

## 2026-10-01 — Rete (F1 parziale) e back-office Filament
- Rete: stati e transizioni, cessazione con ricollegamento dei diretti (G20), storico sponsor, closure table, sponsor immutabile, re-iscrizione dopo 180 giorni (configurabile).
- Back-office Filament 4: Piano compensi (con simulazione e attivazione), Periodi (apri/live/runtime/chiudi), Movimenti PV, Consulenti. Tema terracotta/Inter dalla palette Lamèss.
- Movimenti di periodi chiusi immutabili anche a livello di modello.
- Test: 80, tutti verdi.
- Non ancora: onboarding/KYC e Lettera IVD, referral/FAN, canone, cessione account, 2FA e ruoli, dashboard KPI.

## 2026-10-01 — F1 completo
- FAN con link referral e cookie a 15 giorni; richiesta di diventare consulente con firma (driver fake); accettazione/rifiuto; KYC e onboarding; tesserino PDF; canone annuale con promemoria e scadenza; pagine pubbliche e area consulente; risorse admin per contratti, KYC, canoni, clienti.
- Test: 106.

## 2026-10-02 — Ruoli, 2FA e cruscotto KPI
- Ruoli (spatie/permission) con matrice permessi; sostituito il flag `is_admin`; 2FA con app di autenticazione (obbligatorio da configurazione); cruscotto KPI del back-office.
- Test: 115, tutti verdi.
- Non ancora: ordini e fatture (F2), InvoiceSink/GestionaleK, approvazione a quattro occhi del piano, gestione utenti da interfaccia.

## 2026-10-03 — F2: negozio, ordini, fatture
- Catalogo con PV/CV (default imponibile/1,23, promo CV doppio), omaggi bundle, kit, tariffe di spedizione.
- Ordine → pagamento (carta finta o bonifico) → movimenti PV confermati nel mese del pagamento → fattura/ricevuta numerata → consegna al gestionale tramite `InvoiceSink` (driver finto, retry orario).
- Resi con nota di credito e storno PV (G19 lato ledger, G22); negozio pubblico e area ordini; sezioni admin Prodotti, Omaggi, Spedizioni, Ordini, Fatture, Note di credito.
- Test: 148 su SQLite e su MariaDB.
- Non ancora: FatturaPA XML, pagamento carta reale (Stripe/Nexi), import del catalogo reale, driver GestionaleK, ricerca ordini per consulente nell'area consulente (F6).

## 2026-10-04 — Catalogo reale e prezzi al centesimo
- Importazione del listino Odoo (35 prodotti, prezzi IVA inclusa, PV esplicito): `lamess:importa-prodotti`.
- Prezzi unitari a 8 decimali; arrotondamento una volta per aliquota: i prezzi IVA inclusa tornano al centesimo.
- Prodotti a prezzo zero non acquistabili direttamente.
- Test: 154 su SQLite e MariaDB.

## 2026-10-05 — Fasi 0 e 1 del piano di lavoro del portale
- Fase 0: token grafici Lamèss (colori, Marcellus e Inter ospitati in locale) per sito, aree utente e back-office; installazione guidata `/installazione` e pacchetto ZIP per cPanel senza terminale (`tools/crea-pacchetto-cpanel.php`, `.env.cpanel.example`).
- Fase 1: super_admin invisibile agli altri ruoli (scope globale + 404 + etichetta «Amministrazione»), ruolo admin a permessi singoli, pagine Utenti e Impostazioni di sistema, log di audit con catena di hash e trigger anti-modifica, recupero password, creazione del super_admin solo da riga di comando/installatore.
- Test: 209 (SQLite), tra cui invisibilità del super_admin, catena e manomissioni del log, installatore.

## 2026-10-06 — Fase 2: parametri del piano
- Piano: nuovi parametri `min_payout` (15 €), `scn_threshold` (5.000 €), `move_node_window_days` (7) caricati da `lamess.config`; i «PR mensili minimi» (100) coincidono con `activity_threshold`. Significato di SCN e Move Node ancora da chiarire con il cliente: oggi sono solo valori salvati e modificabili.
- Versioni del piano: data di efficacia (`effective_from`), stato «programmata», `CompPlanVersion::inForce()`; i periodi si aprono con la versione in vigore il primo giorno del mese. Scheduler giornaliero che porta in vigore le versioni programmate.
- Pagina «Piano compensi»: modulo strutturato (parametri, rank con requisiti, Matching, politiche) al posto del JSON; solo le bozze si modificano; attivazione con data di efficacia e motivo; confronto con la versione precedente.
- Prodotti: base di calcolo PV (netto/lordo), quota commissionabile, modalità PV sugli sconti e flag spedizione (questi ultimi due salvati ma non ancora usati dagli ordini).
- Log di audit: `plan.created/updated/activated/duplicated` e `product.updated` con valori prima/dopo.
- Test: 225.

## 2026-10-07 — Fase 3: albero
- Operazioni (`TreeOperations`, permesso `network.restructure` concesso solo dal super_admin): sposta ramo / cambia sponsor con anteprima d'impatto (persone spostate, catena prima/dopo, mesi aperti e chiusi toccati), anti-ciclo, data di efficacia (non nel futuro), scelta «il ramo segue» o «solo lui» (i diretti passano al suo sponsor), scelta «ricalcola i mesi chiusi» (solo super_admin; registra i mesi da riaprire, la riapertura è della fase 6).
- Clienti: riassegnazione singola, in blocco (dall'elenco) e di tutti i clienti di un consulente, con storico (`customer_assignment_history`).
- Qualifica forzata (fonte `forced`), sospensione/riattivazione e cessazione ora scrivono anche il log di audit con stato prima/dopo.
- Ogni operazione richiede un motivo e scrive il log.
- Vista interattiva «Albero della rete»: ricerca, apri/chiudi tutto, zoom, colore per qualifica (con nome scritto), scheda dettaglio, cessati nascosti di default.
- Test: 240.

## 2026-10-08 — Fase 4: storico Odoo e riconciliazione
- `database/import/odoo_storico_etl.py` legge gli export «Ordini di vendita» e «Fatture» e scrive `ordini.csv` e `fatture.csv`; `php artisan lamess:importa-storico [--prova]` (da lanciare dopo `lamess:importa-odoo`) crea ordini, pagamenti, fatture e note di credito storiche. Nessuna email, nessun nuovo movimento PV: agganciati quelli già importati.
- Gli export non hanno le righe prodotto: ogni ordine/fattura ha una riga riepilogativa (prodotto segnaposto `STORICO-ODOO`, descrizione ricavata dai movimenti). Le fatture storiche nascono «inviate» (`sink_ref = odoo`): il gestionale non le riceve due volte. Ordini e documenti importati hanno `source = odoo`.
- Fattura ↔ ordine: riferimento «(Kxxxxx)» nei movimenti, altrimenti acquirente + importo se l'abbinamento è unico.
- Tabella di riconciliazione `import_reconciliations` e pagina «Riconciliazione Odoo» (filtri, approvazione singola o in blocco con nota e log di audit, CSV): fatture senza ordine, PR ordine ≠ PV movimenti, totali diversi, SdI rifiutato/inoltro fallito, ordini da fatturare, movimenti che citano ordini/fatture assenti, note di credito da verificare.
- Preventivi non importati. Idempotente; le differenze approvate restano approvate.
- Test: 244.

## 2026-10-09 — Fase 5 (parte 1): albero vero e «cosa manca»
- `CareerProgress` (app/Domain/Career): per ogni consulente calcola qualifica attuale e prossima, PR richiesti/maturati/mancanti (personali e di gruppo), regola del 50% per linea, requisiti di struttura mancanti, punti attività del mese, sblocco Indiretti/Matching, prossimi bonus (rate Rank Bonus e premi) e un elenco «Cosa fare».
- «Albero della rete» riscritta come vero albero ad alberi (SVG): schede con colore di qualifica e barra di avanzamento, zoom, ricerca, apri/chiudi; il pannello laterale mostra «cosa manca per il livello successivo» (anche per admin). Lo stesso blocco compare nella scheda consulente (sezione «Avanzamento di carriera») e nell'area del consulente («Il mio avanzamento»). Per sospesi/cessati si mostra il motivo, non i PR mancanti.
- `consultants.network_since`: data di ingresso effettiva in rete per il motore (distinta dalla data della lettera `since`); l'import Odoo la anticipa al primo evento reale (chiave `ingressi_anticipati`). I movimenti fuori mese sono riportati nel mese corretto.
- `lamess:verifica-odoo YYYY-MM`: confronta qualifiche e PR del motore con Odoo e scrive le differenze nella riconciliazione (`verifica_*`). Sul replay gen–set: qualifiche 86/86 uguali, PR di gruppo 68/86 uguali; 18 differenze piccole da rivedere.
- Test: 254.

## 2026-10-02 — Allineamento al PMK 070726
- Stato ATTIVO: contano solo i PVF di almeno 1 cliente; gli acquisti personali restano PVC e valgono per i PR ma non per l'attività.
- Acquisti personali dei consulenti: nessun Direct a chi compra; lo sponsor e la linea sopra maturano la differenza come Team Bonus. La vecchia regola resta come opzione (`sponsor`).
- Fast Start Bonus **attivo**: nuovo `FsbCalculator` (step 1–9 con diretti ≥100 PV, PV cumulati dalla firma con regola del 50%, qualifica raggiunta; step 10 con 3 linee). `SnapshotBuilder` fornisce i movimenti dei mesi precedenti e gli step già pagati nei periodi chiusi, così nessun premio si ripaga.
- PV: fisso per prodotto anche in promo (`discount_pv_policy = catalog`).
- Piano: parametri `payout_mode = monthly_auto` e `payout_day = 15` (il settlement F5 non è ancora costruito).
- Test: 268 (14 nuovi: G15–G17, G27, G34/35, G41, G43, step 10, FSB su due mesi). **Le versioni del piano già salvate nel database hanno ancora i valori vecchi:** va creata e attivata una nuova versione (o rieseguito il seeder) per applicarli.

## 2026-10-02 — Fase 6: chiusura mensile, pagamenti, statini, export; abbonamento e ordine multiplo
- Alla chiusura del mese si generano le liquidazioni (`settlements`: maturato e trattenuto per consulente, dalle righe bloccate della run ufficiale). Pagamenti (`payouts`): soglia minima del piano (15 €), importi sotto soglia che si accumulano ai mesi successivi, saldi negativi riportati, blocco se manca KYC verificato o IBAN o c'è un blocco bonus. Segna pagato (riferimento e data) e annulla.
- Statini mensili per consulente (pagina e PDF) con il perché di ogni importo; export CSV dei compensi del mese e dei bonifici da fare (tracciati nell'audit).
- Riapertura di un mese chiuso: solo super amministratore, con motivo obbligatorio, solo l'ultimo mese chiuso e solo se nessun pagamento è stato eseguito; annulla bonifici da fare e liquidazioni, riporta le righe a bozza.
- Nuovi permessi `payouts.view` / `payouts.manage`: dopo l'aggiornamento eseguire `php artisan lamess:ruoli`.
- Abbonamento mensile (solo carta): primo ordine subito, poi uno al mese con `lamess:abbonamenti` (ogni giorno alle 04:00); addebito fallito → nuovi tentativi, poi pausa; pausa/ripresa/annullo dal cliente e dall'admin. Spedizione gratuita con impegno di almeno 12 mesi (parametri modificabili nel pannello).
- Ordine per conto di altri: il consulente aggiunge al carrello prodotti per i suoi clienti e per i consulenti diretti; un solo ordine e una sola spedizione, punti al destinatario.
- Fase 5 lasciata aperta di proposito (18 differenze PR, nuova versione del piano, decisioni OPEN).
- Test: 297. Dopo l'aggiornamento eseguire `php artisan migrate`.

## 2026-10-05 — Fase 6: pagamento automatico il giorno 15 e controllo del cron
- `lamess:pagamenti` (ogni giorno alle 05:00): per i mesi chiusi non ancora preparati, dal giorno di pagamento del piano (15 del mese dopo) crea i pagamenti «da pagare». Una sola volta per mese: un pagamento annullato a mano non viene ricreato. La riapertura del mese azzera il segno. Con `payout_mode = on_request` non fa nulla.
- Dashboard admin: riquadro «Operazioni automatiche» (Attivo / Fermo / Mai partito) alimentato da un battito registrato ogni minuto da `schedule:run`.
- Dopo l'aggiornamento: `php artisan migrate`.
- Chi diventa pagabile (KYC, IBAN) dopo la preparazione del mese viene preso dal mese successivo, oppure subito con «Prepara pagamenti».

## 2026-10-05 — Chiusura mensile automatica
- Impostazione «Chiusura automatica del mese» (Impostazioni → Chiusura mensile): **spenta**, **solo calcolo ufficiale** (default: il mese resta in revisione e lo chiude l'admin) oppure **calcola e chiude da sola**.
- `lamess:chiusura-mese` gira ogni notte alle 01:00 sul primo mese non chiuso, solo se è finito da meno di 45 giorni e solo se tutti i mesi prima sono chiusi: i mesi arretrati (es. quelli importati da Odoo) si chiudono a mano.
- Movimenti in attesa di pagamento o dati cambiati: la chiusura si ferma e riprova ogni notte; il blocco entra nel registro una sola volta per motivo e compare nel riquadro «Operazioni automatiche» della dashboard.
- Catena completa: fine mese → calcolo/chiusura (01:00 del giorno 1) → pagamenti preparati dal 15 (05:00) → bonifico e «Segna pagato» a mano.
- Test: 304.

## 2026-10-05 — Mesi storici già pagati
- Azione «Segna come storico già pagato» nella scheda del periodo chiuso: crea le liquidazioni e le segna pagate, così un mese già pagato fuori dal portale (Odoo) non entra mai in un pagamento nuovo. Va usata per i mesi chiusi prima della Fase 6 (gen–set 2026) prima del primo «Prepara pagamenti».
- Test: 305.

## 2026-10-05 — Correzioni e prova del percorso completo
- Correzione: «Genera liquidazioni» riportava ad «aperte» le liquidazioni di un mese segnato come storico già pagato. Ora le liquidazioni pagate o legate a un pagamento non si riscrivono mai; «Genera liquidazioni» compare solo per mesi senza liquidazioni; «Segna come storico già pagato» compare finché nessuna liquidazione del mese è legata a un pagamento.
- Prova su copia del database reale (gen–ago storico, settembre da pagare): 27 liquidazioni, 5 pagamenti preparati il 15/10 (1.197,73 €), CSV bonifici e statino PDF corretti. Nessun consulente ha ancora l'IBAN: senza IBAN nessuno è pagabile.
- Documentazione rivista: manuali utente, admin, sviluppatori, architettura, installazione, decisioni aperte (OPEN-42/43/44), dossier rigenerato.
- Test: 305.

## 2026-10-05 — File SEPA, avvisi ai consulenti, dati del venditore
- **File SEPA** (Pagamenti → «Esporta file SEPA (XML)», pain.001.001.03): bonifici multipli dei pagamenti «da pagare» da caricare nell'home banking. IBAN controllati (cifra di controllo), nomi ripuliti dai caratteri non SEPA, pagamenti senza IBAN valido saltati ed elencati. Richiede in Impostazioni → Bonifici SEPA intestatario e IBAN del conto da cui si paga. Registro di audit `export.sepa`, i pagamenti esportati ricevono la data di esportazione. **Prima del primo uso reale far controllare il file alla banca.**
- **Avvisi email, solo ai consulenti**: «statino pronto» alla chiusura del mese e «bonifico effettuato» quando si segna un pagamento come pagato. Due interruttori in Impostazioni → Email. Nessun avviso ai clienti. Con «Invio email attivo» spento non parte nulla (blocco globale + controllo nel servizio). Un errore di posta non blocca mai chiusure o pagamenti.
- **Dati del venditore**: l'etichetta ora è «Denominazione (ragione sociale oppure nome e cognome)» (vale anche per chi non è un'azienda); la dashboard mostra «Da completare» finché resta il testo provvisorio.
- «Segna come storico già pagato» sparisce una volta usato (resta solo se qualche liquidazione del mese torna aperta).
- Test: 318.

## 2026-10-05 — Fase 7: aree utente e grafica premium
- **Back-office**: menu in alto con 4 gruppi a tendina (Rete, Compensi, Vendite, Sistema) al posto della lunga barra laterale; logo e nome Lamèss; stile premium (`public/css/lamess-admin.css`, file statico, nessuna compilazione); pagina di accesso con logo; stati in italiano (Attivo, Chiuso, In revisione…) tramite `App\Support\StatusLabels`; tolto il riquadro «Benvenuto» dal cruscotto.
- **Area consulente**: «Il mio ramo» (albero in sola lettura con qualifica, stato, PVF e PR del mese), «I miei clienti» (solo quelli assegnati), numeri del mese in panoramica (PVF, PR, qualifica).
- **Area di tutti**: «I miei dati» (recapiti e cambio password; email e dati fiscali solo tramite il Consorzio).
- **Pagine di errore** 403, 404, 419, 429, 500, 503 in italiano con la grafica Lamèss; pagine vuote con messaggio.
- **Sito e area**: nuovo layout con intestazione, sotto-menu e piè di pagina; adattato al telefono (`public/css/lamess-site.css`).
- Test: 324.

## 2026-10-05 — Fase 8 (parte 1): sicurezza e messa online
- **Backup del database** dal portale (Sistema → Backup; automatico ogni notte alle 02:15, ultimi 14; scarico solo per il super_admin, registrato nel log). `lamess:backup` da riga di comando.
- **Controllo messa online** (Sistema → Controllo messa online, `lamess:controlla`): 20 controlli con OK / Attenzione / Blocco.
- **Intestazioni di sicurezza** su tutte le risposte (nosniff, frame, referrer, permessi, HSTS su https).
- **Matrice dei permessi** testata: portale pubblico mai nel back-office, ogni profilo admin vede solo ciò che il suo permesso consente, pagine di sistema solo super_admin, tutte le pagine admin si aprono senza errori; limite ai tentativi di accesso verificato.
- Nuovi documenti: «Messa online e piano di ritorno», «Guida breve per l'amministratore».
- Aperto: firma elettronica reale (oggi di prova), carta reale (Stripe), prova del ripristino MySQL su hosting.
- Test: 340.

## Pacchetto cPanel verificato (5 ottobre 2026)
- Il pacchetto non include più la cache di sviluppo (`bootstrap/cache`), né file di servizio (.github, .mcp.json, boost.json, .styleci.yml, .npmrc), né i database di prova.
- Prova da zero su MariaDB: installazione guidata, blocco della pagina dopo l'uso, accesso, intestazioni di sicurezza, scheduler, backup e ripristino (tabelle e trigger) riusciti.
- Aperte: OPEN-47/48/49.

## 2026-10-05 — Moduli 1-7: dal perimetro «fuori piano» al sistema completo
- **Modulo 1**: approvazione a quattro occhi della pubblicazione del piano (nuovo permesso `plan.approve`).
- **Modulo 2**: assistenza (ticket, note interne), estratti conto con reclamo entro 10 giorni e accettazione tacita (`lamess:estratti`).
- **Modulo 3**: wallet e prelievi su richiesta, ricevute provvigionali, trattenute (ritenuta d'acconto, INPS, commissione) spente di default e da validare col commercialista, regime fiscale del consulente.
- **Modulo 4**: novità, materiali scaricabili con filigrana, eventi con lista d'attesa, link e QR del referral (permesso `content.manage`).
- **Modulo 5**: conformità: casi, sanzioni con effetti automatici (`lamess:sanzioni`), materiali promozionali approvati con codice `MA-AAAA-nnnn` (permessi `compliance.*`).
- **Modulo 6**: cessione del codice e successione agli eredi (`lamess:successioni`, 180 giorni), contributo di cessione 50 €.
- **Modulo 7**: driver reali: Stripe Checkout con webhook firmato; fatture elettroniche FatturaPA (TD01/TD04) validate contro lo schema ufficiale, con consegna `file` o `mail`; verifica d'identità online con Stripe Identity; nuovi controlli nel «Controllo messa online».
- Aperto: OPEN-50…64 (vedi «Decisioni aperte»), in particolare l'aggiornamento senza terminale dopo la messa online, GestionaleK e i rinnovi automatici con Stripe.
- Test: 451 (1 saltato, preesistente).


## 2026-10-08 — Piano Incaricati: niente PV, regola cliente e mantenimento delle qualifiche
- **Importi in euro al posto dei PV.** `pv_movements.amount` (migrazione `2026_10_25_100000_pv_movements_amount`): importo IVA inclusa della riga d'ordine, già al netto degli sconti di prezzo; i resi lo storna. Il piano Incaricati calcola compensi e clienti sugli euro. I movimenti storici senza importo si convertono con «euro per punto» (`commission_base_per_pv`, 1,5 = 100 PV → 150 €). Le colonne PV/CV/PR restano solo come storico tecnico e per il vecchio piano.
- **Chi è cliente lo decide l'admin** (Piano compensi → Base di calcolo): `client_rule` = `any_order` (basta un ordine) oppure `min_amount` (un ordine di almeno `client_min_amount` €, IVA inclusa; le righe dello stesso ordine si sommano). Predefinito: ordine da 150 €. Attenzione: con `min_amount` un ordine in promozione sotto l'importo (es. Box a 129,90 €) non rende cliente.
- **Nessuno stato ATTIVO nel piano Incaricati.** Non esiste la spesa mensile minima di 150 €: diretti, indiretti, indiretti estesi e Bonus di Struttura maturano a prescindere. Restano esclusi dagli indiretti solo gli incaricati sospesi o cessati. Badge e sezione «Attività» sono nascosti nelle schermate quando il piano è Incaricati (`ActiveStatus::applies()`); il vecchio piano a PR resta invariato.
- **Mantenimento delle qualifiche fino a una data** (`kosmos.rank_grace_until`, scelta dall'admin): finché l'ultimo giorno del mese cade entro la data, la qualifica non si riduce per mancanza di clienti (politica `kosmos_rank_maintenance`). Vuoto = nessuna protezione. Le nuove qualifiche si ottengono comunque.
- Movimenti manuali: campo «Importo in euro». Hash dei calcoli esistenti invariati (campi opzionali).
- Test: nuovi in `KosmosPlanTest`, `ClientRuleTest`, `KosmosPlanAdminTest`; asserzioni sugli importi in `OrdersTest` e `InvoicesAndReturnsTest`.

## 2026-10-09 — Negozio: ultimi passaggi per vendere subito
- **Spedizione predefinita**: l'installazione guidata crea la tariffa Italia di 9,00 € IVA inclusa (7,38 netti + 22%) se non esiste; si cambia in Vendite → Tariffe di spedizione. Prima, senza tariffa, la spedizione risultava a 0.
- **Dal carrello alla registrazione al checkout**: chi si registra con il carrello pieno torna direttamente al checkout (prima finiva nell'area).
- Carrello: i link «accedi» e «registrati» sono cliccabili.
- Test aggiunti: `test_guest_with_cart_registers_and_lands_on_checkout`, tariffa predefinita in `InstallerTest`. Non eseguiti in questa sessione (PHP non disponibile).

## 2026-10-09 — Shop: gestione ordini completa (admin/superadmin)
- Stati ordine: in lavorazione, spedito (corriere + tracking, mail al cliente), consegnato, annullato; storico eventi e note interne per ogni ordine.
- Rimborsi totali/parziali con ripristino magazzino: carta (Stripe), KMoney, bonifico (manuale, da segnare "fatto"); retry se il rimborso fallisce.
- Pagina admin "Vendite → Pagamenti e spedizioni" (catalog.manage): metodi di pagamento attivi, istruzioni bonifico; costo spedizione dalle tariffe esistenti.
- Dettaglio ordine in admin, esportazione CSV, annullamento ordine da parte del cliente (solo bonifico non pagato).
- Test: tests/Feature/Shop/OrderManagementTest.php (16 test).

## 2026-10-09 — Shop: spedizione a peso, reso con spedizione, resi dal cliente, ordine manuale
- Tariffe di spedizione per paese e fascia di peso (peso in grammi nella scheda prodotto; «*» = resto del mondo); più tariffe per lo stesso paese.
- Reso: opzione «Rimborsa anche le spese di spedizione» (una sola volta per ordine, nota di credito con riga spedizione) — chiude OPEN-33.
- Il cliente può richiedere il reso dalla pagina dell'ordine (entro lamess.return_window_days = 14 giorni dalla consegna); l'admin approva (reso, giacenza, rimborso) o rifiuta con messaggio.
- Admin: «Nuovo ordine» manuale per un cliente (prodotti, indirizzo, metodo, «già pagato»).
- Migrazione: 2026_10_30_100000_shipping_weight_bands_and_returns. Test: ShippingZonesTest e OrderManagementTest (20 test).
- Spedizione: la tariffa generale si inserisce IVA inclusa (9 € → 7,38 netti); ogni prodotto può avere un costo proprio (vuoto = tariffa generale, 0 = gratis); con più prodotti si paga la voce più alta. Migrazione 2026_10_30_110000.

## 2026-10-12 — Checkout KMoney, mantenimento qualifiche a giorni, scheda prodotto, elenco per livelli
- Checkout: sotto «Paga con KMoney» compare il vantaggio calcolato sull'importo («Ricarichi solo € 120,00 e paghi 150,00 KY: risparmi € 30,00, fino al 25% in più»), con l'etichetta «Risparmia fino al 25%»; la percentuale si cambia in Impostazioni di sistema → Metodi di pagamento (0 = nessun messaggio). Gli importi KMoney sono scritti in KY. Sotto c'è anche «Hai già un conto KMoney? Accedi. Non ce l'hai ancora? Registrati.» I due link sono attivi col collegamento reale a KMoney; «Registrati» va a `KMONEY_REGISTER_URL` (vuoto = `{KMONEY_BASE_URL}/register`).
- Piano Incaricati, acquisto dell'incaricato: il motore già lo tratta come cliente di sé stesso e lo sponsor matura l'indiretto di 1° livello come per un cliente di un suo diretto (interruttore K04); aggiunto il test che lo dimostra.
- Mantenimento delle qualifiche: nuovo campo `kosmos.rank_grace_days` (predefinito 90) = giorni dall'attivazione del piano (efficace dal, altrimenti giorno di attivazione) in cui la qualifica non scende per mancanza di clienti; ogni mese iniziato nella finestra è protetto per intero. Resta la data fissa `rank_grace_until`; se ci sono entrambi vale la più lontana.
- Scheda prodotto (admin): V.A.P. (% del prezzo o importo fisso), clienti che vale e durata in mesi sono nella sezione «Commissioni e durata (Piano Incaricati)» della colonna principale, con riepilogo in euro; nell'elenco prodotti le colonne V.A.P. e «Clienti e durata» sono visibili.
- Clienti (admin): l'etichetta del filtro «Assegnazione» non si confonde più con il filtro «Incaricato»; test sul pulsante «Clienti» della scheda incaricato.
- Nuova pagina Rete → «Elenco per livelli» (e pulsante nell'elenco incaricati): incaricati in ordine di ramo con rientro per livello, ricerca di chi scegliere come punto di partenza, limite di livelli e filtro per stato.
- Test: KosmosPlanTest, KosmosPlanAdminTest, SubscriptionPaymentMethodsTest, ShopCatalogAdminTest, Consultant360Test, LevelListTest. Suite: 700 test, 1 rosso preesistente (`SitePagesTest::test_full_consultant_journey_through_the_site`: il tesserino prima dell'accettazione risponde 200 invece di 404).

## 2026-10-12 — Documenti KYC, invio documenti, certificato di firma, storico prelievi Odoo
- **Richiedi documenti KYC** (permesso `kyc.manage`): azione nella scheda incaricato, nell'elenco e come azione di massa. L'incaricato riceve una email e vede il riquadro «L'amministrazione ti ha chiesto dei documenti» in `/area/onboarding`. La richiesta si chiude da sola al caricamento di tutto; promemoria dopo 3 e 6 giorni (`lamess:kyc-promemoria`, ogni giorno alle 08:00). Impostazioni: `LAMESS_KYC_REQUEST_DUE_DAYS` (7), `kyc_request_reminder_days`. Tabella `kyc_requests`.
- **Invia documenti** (permesso `network.manage`): dalla scheda, o in massa, l'admin manda per email lettera firmata, certificato di firma, tesserino, estratti conto e ricevute. Ogni invio è nel log di audit (`documents.sent`). Allegati fino a 15 MB.
- **Certificato di completamento della firma**: nuove evidenze (email e verifica email del firmatario, versione del modello) e registro accessi prima/dopo la firma (`ivd_contract_events`). PDF scaricabile dall'incaricato (`/area/file/certificato`) e dall'admin (`/admin-files/contract/{id}/certificate`). La geolocalizzazione non viene registrata.
- **Canone annuale**: 19 € IVA compresa (testo della lettera aggiornato), importo modificabile e **interruttore «Canone annuale attivo»** nelle impostazioni (spento: nessun canone, rinnovi gratuiti).
- **Storico prelievi Odoo**: `lamess:importa-prelievi` (da `database/data/odoo/prelievi.csv`, generato da `database/import/odoo_prelievi_etl.py`). Importa richieste, pagamenti e ricevute (numerazione `COMM…` di Odoo, serie a parte); «pagato» = liquidato o autofattura pagata; **il wallet non viene toccato**; importi non tracciati e netti diversi finiscono in riconciliazione. Il netto di tutti i 45 prelievi storici è riprodotto dal calcolatore (ritenuta 23% sul 78% + 2,50 €, senza INPS).
- **Confronto bonus con Odoo**: `lamess:verifica-odoo 2026-09 --bonus` (da `commission_period.csv`, generato da `odoo_commission_period_etl.py`). Vale solo per il vecchio piano a PR.
- **Ricevute e ritenuta d'acconto restano SPENTE** (`LAMESS_RECEIPTS_ENABLED`, `LAMESS_WITHHOLDING_ENABLED` = false) finché non risponde il commercialista. Per accenderle: impostare le due variabili a `true` nel `.env` (INPS resta spenta).
- Migrazioni da eseguire: `2026_11_03_100000_kyc_requests`, `2026_11_03_110000_ivd_contract_events`, `2026_11_03_120000_payout_history_import`.
- Test: suite completa 703, 700 verdi, 1 saltato e 2 rossi **non legati a queste modifiche**: `SitePagesTest::test_full_consultant_journey_through_the_site` (già rosso prima: il tesserino risulta scaricabile subito dopo la firma, il test si aspetta 404) e `InstallerTest::test_install_creates_superadmin_once_and_locks` (requisiti server dell'ambiente cloud di prova). Nessun commit.

## 2026-10-13 — Qualifica mantenuta, carrello, prezzi IVA inclusa, piano precedente nascosto, form di modifica
- **Mantieni qualifica**: per ogni incaricato la qualifica attuale si può congelare fino a una data scelta dall'admin (campi `rank_hold_until` e `rank_hold_reason`), dalla scheda, in massa, su tutti i registrati o dal menu riga; ogni cambio è nel log di audit (`rank.hold`, `rank.hold_removed`). Vale solo con il Piano Incaricati attivo e `kosmos_rank_maintenance` basato sui clienti. Migrazione: `2026_11_04_100000_consultants_rank_hold_until`. Colonna «Qualifica mantenuta fino al» nascosta di default. Permesso `network.restructure`, interruttore `consultant360.action.hold_rank`.
- **Elenco per livelli**: nuova colonna «Hanno acquistato» (clienti assegnati con almeno un ordine pagato nel periodo scelto: mese corrente, scorso, ultimi 3 mesi, anno, intervallo libero).
- **Carrello**: «torna al carrello» non lo svuota più (`PendingCart`: il carrello resta in sessione finché l'ordine non è pagato; gli ordini in attesa vecchi si annullano al nuovo ordine).
- **Prezzi e riepiloghi IVA inclusa** (cliente e admin); dell'IVA si mostra solo l'aliquota («IVA 22% inclusa»). Con un carrello 100% KMoney anche la spedizione si paga in KMoney. Gli abbonamenti hanno sempre spedizione gratuita; rimosso l'impegno minimo configurabile.
- **KMoney**: pannello «Conto KMoney» nel profilo (`/area/profilo#kmoney`: collega, autorizza i pagamenti, scollega); il checkout rimanda lì se il conto non è collegato. % KMoney sulle card e nelle liste prodotto, con filtro e ordinamento. Sul sito live servono nel `.env` `LAMESS_KMONEY_DRIVER=api`, `KMONEY_SELLER_ACCOUNT`, `KMONEY_API_TOKEN`, `KMONEY_WEBHOOK_SECRET`; gli URI di ritorno OAuth vanno registrati sia con `www` sia senza.
- **Visibilità piano precedente** (solo super_admin): una pagina sotto Compensi nasconde in un colpo ogni riferimento al vecchio piano a PR (menu, schede 360, widget, rete, estratti, esportazioni, testi salvati) senza cancellare né ricalcolare dati (`App\Domain\Plan\LegacyPlan`). Scheda incaricato e area riservata mostrano i requisiti per la prossima qualifica col nuovo piano. Il log di audit non viene toccato.
- **Ordini in abbonamento**: relazione `Order::subscription()`; badge «Tipo ordine» nel dettaglio e nell'elenco, filtro «Solo abbonamenti / Solo ordini singoli», riga «Tipo» nella pagina dell'ordine del cliente.
- **Scheda incaricato**: la tabella dei diretti è «Incaricati diretti (1° livello)» con «Ordini del mese» e «Importo del mese» (ordini pagati del mese, IVA inclusa) al posto di «Origine».
- **Immagini prodotto**: l'anteprima nell'admin usa la route `/media/...` dello shop (l'URL firmato era rifiutato in produzione). Dopo il deploy svuotare la cache (vedi sotto).
- **Form di modifica generici** (`GenericForm`, `GenericFormVocabulary`): etichette in italiano, select ricercabili per le relazioni, menu per stati e tipi, righe prodotto/quantità per i JSON noti, date nel fuso di Roma, suffissi €/%. Backup in `_backup/generic-form-20261013`.

## 2026-10-14 — Pulizia testi checkout KMoney e test
- Tolta la frase «finché non confermi non addebitiamo nulla» dal messaggio dopo «Conferma ordine» e dal riquadro della pagina ordine.
- Form generici: le colonne JSON con forma nota (righe prodotto/quantità) non sono più obbligatorie, così un abbonamento si può salvare anche senza prodotti dall'admin.
- Test del checkout KMoney allineati al testo attuale («collega il tuo conto KMoney dal profilo»).
- Gli abbonamenti partono con impegno di 12 mesi, non vincolante: si possono interrompere quando si vuole.
- Sul sito live non c'è terminale: dopo ogni deploy, per svuotare la cache, cancellare da File Manager i file in `bootstrap/cache` (tranne `.gitignore`) e `storage/framework/views`.
- **Documentazione aggiornata**: manuali (admin, utente, sviluppatori), guida breve, scheda incaricato, architettura, specifica del motore, parametri, messa online, installazione cPanel, decisioni aperte e README riallineati al Piano Incaricati, alla terminologia «Incaricato», all'IVA inclusa, a KMoney e alla messa online senza terminale. Il dossier `docs/dossier.html` è stato rigenerato con `php artisan lamess:manuale`.
- **Prezzi prodotto IVA esclusa**: nel form prodotto (prezzo, listino barrato, spedizione del prodotto e varianti) si inserisce il prezzo IVA esclusa con l'aliquota; il prezzo IVA inclusa si calcola e compare accanto. Cliente e admin continuano a vedere sempre l'IVA inclusa con la sola aliquota.
- **Piè di pagina**: anche la versione ridotta riporta «KADEO GROUP – Tutti i diritti riservati» e i dati dell'azienda.
- **Area incaricato**: l'albero della rete mostra il colore della pietra di ogni qualifica, con legenda (`App\Domain\Career\RankPalette`, usata anche dall'albero del back-office).
- Corretto il testo del parametro `engine` (multilevel_v3 = piano precedente a PR) e il commento di `SubscriptionService` sull'impegno di 12 mesi non vincolante.

## 2026-10-14 — Regola cliente a due vie, qualifiche «fino a revoca», estese same_or_higher
- **Regola cliente**: un ordine rende cliente se il **totale** raggiunge l'importo minimo del piano (anche solo accessori) **oppure** contiene un prodotto con il nuovo interruttore «Rende cliente a prescindere dall'importo» (scheda prodotto, spento di default: il comportamento di oggi non cambia). Il flag si congela su riga d'ordine e movimento (migrazione `2026_11_05_100000_product_makes_client`: colonna `makes_client` booleana, default 0, su `products`, `order_items`, `pv_movements`). Cambiare il minimo = nuova versione del piano; cambiare un prodotto = interruttore. Test: `ClientRuleTest` (+5), `ShopCatalogAdminTest`.
- **Qualifiche congelate «fino a revoca»**: «Mantieni qualifica» (scheda, riga, massa, «a tutti») ha l'interruttore «Fino a revoca (senza scadenza)»: salva `2099-12-31`, in elenco «fino a revoca». Riguarda solo la qualifica, non il conteggio dei clienti.
- **Indiretti estesi**: `kosmos_extended_stop` = `same_or_higher` (stessa qualifica o più alta) predefinito (decisione di Laura) nel codice e in `plan-incaricati-2026-10-08.json`.
- **Piano precedente nascosto**: nuovi test `LegacyPlanTest` (8) su impostazione, permessi, ambiente e assenza di effetti sui calcoli. Test aggiunti a `Consultant360Test` e `KosmosPlanTest`.
- Il Piano Incaricati parte il 1° novembre 2026 (ottobre resta sul piano precedente); Fast Start, Team, Matching e Rank Bonus restano spenti.

## 2026-10-14 — Reset password dall'admin, singolo e in blocco
- **Invia reset password** per incaricati, clienti FAN e utenti: sulla riga, sulle righe selezionate e «Reset password a tutti» (incaricati, clienti o entrambi; conferma INVIA). Manda il link per scegliere una nuova password, senza cambiare quella attuale. Permesso `network.manage`.
- Salta back-office, email non valide e (se acceso) chi ha già un link delle ultime 24 ore; non crea link se «Invio email attivo» è spento; tempo massimo con conteggio dei rimasti, rilanciabile; audit con soli conteggi.
- Aggiunta al repository la notifica `ResetPasswordIt` (usata da `User` ma mancante su GitHub: il «password dimenticata» online andava in errore).
- Test: `AdminPasswordResetLinksTest` (11). Documentazione aggiornata: manuale admin, guida breve, manuale utente, manuale sviluppatori, dossier.
- Nota: `ConsultantListTest::test_no_per_row_queries` fallisce già prima di questa modifica (soglia di 60 query, ne fa ~120).

## 2026-10-14 — Area incaricato allineata al Piano Incaricati
- **Il mio ramo**: albero con ricerca e dati per persona (clienti, ramo, diretti Pearl, venduto del mese), tabelle per colonna (barra verso la soglia) e per livello, pannello «Verso la qualifica successiva». `NetworkOverview` raccoglie i dati; nessun recapito, solo nome, codice, qualifica, stato e numeri aggregati.
- **Avanzamento** (`CareerProgress::buildKosmos`, partial `career-progress-kosmos`, usato anche nella scheda admin): requisiti con raggiunto/richiesto, scaglioni diretti, livelli indiretti, mantenimento e scala qualifiche. Il motore salva in `next_rank` anche `personal_clients` e `pearl_first_level_now` (rifare il calcolo del mese per averli per tutti).
- **Panoramica**: clienti, diretti Pearl, compensi del mese stimati (`EarningsSummary`) e Bonus di Struttura. **I miei clienti**: primo ordine, ordine più alto, «conta per il piano».
- **Referral**: il codice mancante (account importati) si genera al primo uso, risolvendo il 404 su `/area/referral` e il link `/r` senza codice; dominio mostrato come lamèss.it (`App\Support\Idn`), copiato in ASCII.
- Test non eseguiti in questa sessione (PHP assente sul PC): lanciare `php artisan test`, in particolare `LegacyPlanTest`, `AreaPagesTest`, `ContentTest`, `CareerProgressTest`.

## 2026-10-14 — Requisiti attuali e «Dove agire» nell'area incaricato
- Il motore salva in `next_rank['current']` l'esito dei requisiti della qualifica attuale; `CareerProgress` li espone come `current_requirements` e il pannello li mostra sopra quelli della prossima qualifica.
- `NetworkOverview`: per ogni persona sotto di te clienti registrati/validi/senza ordine, ultimo ordine, venduto del mese, ramo e cosa manca per la sua prossima qualifica; tabella «Dove agire» con ricerca, ordinata per vicinanza alla qualifica. Dei clienti altrui solo conteggi e date (nessun nome o contatto).
- Corretto il 500 su `/area`: le righe `@php(...)` in linea nella panoramica venivano unite al `@endphp` successivo; verifica con compilazione Blade di tutte le viste dell'area.


## 2026-10-14 — Avanzamento anche per chi non è nel calcolo salvato
- `CareerProgress::forConsultant()`: se l'incaricato non compare nell'ultimo calcolo salvato (entrato dopo il calcolo), si usa un calcolo di prova in memoria (cache 10 min, nessuna scrittura). Il pannello "Il mio avanzamento" non resta più vuoto.

## 2026-10-14 — Qualifiche congelate «fino a revoca», estese same_or_higher, test del piano precedente nascosto, manuali
- **Qualifiche congelate**: la protezione della qualifica ora ha l'opzione «Fino a revoca (senza scadenza)» (salva `2099-12-31`) nella scheda, nell'azione di riga, di massa e in «Mantieni qualifiche a tutti». L'elenco mostra «fino a revoca». La qualifica resta finché l'admin o il super admin la toglie. Il congelamento riguarda **solo la qualifica**: i clienti contati con la regola dei 150 € incidono comunque su scaglioni dei diretti e livelli degli indiretti.
- **Indirette estese**: la regola `same_or_higher` (stessa qualifica o più alta) è il default in `PolicySpecs` (K02, decisa da Laura) e nel JSON `plan-incaricati-2026-10-08.json`; il piano attivo nel database già la usa.
- **Piano precedente nascosto**: nuovi test `tests/Feature/Plan/LegacyPlanTest.php` (8) sul pulsante/impostazione che nasconde il piano a PR (menu, schede, permessi, ambiente, nessun effetto sui calcoli).
- **Altri test**: `Consultant360Test` (qualifica fino a revoca), `KosmosPlanTest` (estese di default, qualifica mantenuta). I due test che erano rossi sono verdi.
- **Manuali aggiornati**: utente, amministratore, guida breve, specifica del motore, architettura, sviluppatori, decisioni aperte (K01–K08), scheda incaricato, messa online, installazione cPanel, parametri (sezione Incaricati generata) e dossier.
- **Date**: il Piano Incaricati parte il 1° novembre 2026 (ottobre resta sul piano precedente); lo storico Odoo serve solo come dato dei mesi precedenti. Fast Start, Team, Matching e Rank Bonus restano spenti.
- Suite completa: restano 4 rossi non legati al piano (lavori su shop/KMoney/form del 13/10). Nessun commit.
- **Regola cliente a due vie** (14/10/2026): un ordine rende cliente se il **totale** raggiunge l'importo minimo del piano (qualunque siano i prodotti, anche solo accessori) **oppure** contiene un prodotto con il nuovo interruttore «Rende cliente a prescindere dall'importo» (scheda prodotto, spento di default, quindi il comportamento di oggi non cambia). Il flag si congela sull'ordine e sul movimento (migrazione `2026_11_05_100000_product_makes_client`). Cambiare il minimo = nuova versione del piano; cambiare un prodotto = interruttore. Test in `ClientRuleTest` e `ShopCatalogAdminTest`; manuali e parametri aggiornati.

## 2026-10-14 (sera) — Mantenimento qualifiche solo per chi viene dal vecchio piano
- **Decisione di Laura**: la data di mantenimento del piano (`rank_grace_until` / `rank_grace_days`) vale solo per gli incaricati importati dal vecchio sistema, cioè con `consultants.previous_rank_code` valorizzato (lo riempie «Congela qualifiche attuali come precedenti»). I nuovi incaricati possono essere promossi ma, se i clienti calano, la qualifica scende.
- Il mantenimento per il singolo incaricato (`rank_hold_until`, anche «fino a revoca») resta valido per tutti.
- Il motore prende la data più lontana tra `rank_grace_until` e i giorni dall'attivazione: per far valere il 31/12/2026 svuotare `rank_grace_days`.
- Il Piano Incaricati è attivo dal 09/10/2026 (versione 5); il periodo 2026-10 resta legato alla versione precedente finché non lo si sposta (vedi procedura).
- Tecnico: `Node::$previousRank` (fuori dall'hash se vuoto), `SnapshotBuilder`, `PeriodCalculator`, `CareerProgress::isLegacy()`; test `test_the_plan_wide_date_protects_only_those_coming_from_the_previous_system`.

## 2026-10-14 (sera) — «Il mio ramo»: albero grafico come quello del back-office
- L'albero dell'area incaricato non è più un elenco: è lo stesso organigramma del back-office (schede con colore della pietra, barra verso la qualifica successiva, ricerca, livelli 1–3 / 1–6, espandi tutto, schermo intero, minimappa, scheda al clic, doppio clic per aprire l'albero di una persona), con l'incaricato alla radice e solo il suo ramo. Niente PV, PR, Fast Start o regola del 50%.
- La scheda mostra qualifica, livello, clienti, iscritti, senza primo ordine, ultimo ordine, venduto del mese, diretti Pearl e i requisiti per la prossima qualifica, con testi neutri; i requisiti si caricano da `GET /area/rete/{id}/dettaglio` (solo per sé e la propria downline, altrimenti 404).
- Tecnico: `Domain/Career/NetworkTree` (dati e dettaglio), vista `site/area/_tree.blade.php`, script `public/js/lamess-tree.js` (nessuna libreria, nessun build). Se i dati dell'albero non si possono costruire resta il vecchio elenco ad albero.
- Test: `AreaPagesTest` (dati dell'albero con radice l'incaricato e senza punti; scheda di dettaglio solo per il proprio ramo). Nessun commit.

## 2026-10-15 — Scheda incaricato completa (admin e iscrizione da referral)
- **Admin, Rete → Incaricati → Nuovo**: modulo a sezioni (Iscrizione, Anagrafica, Residenza e contatti, IBAN facoltativo). Nome e cognome separati, data e luogo di nascita (solo maggiorenni), residenza, nazione, telefono, PEC, IBAN e intestatario facoltativi. Sponsor facoltativo. I dati personali si salvano nel profilo dell'utente, quindi l'email è obbligatoria se si compilano anagrafica o contatti; se l'utente esiste già si completano solo i campi vuoti.
- **Iscrizione da referral (`/area/diventa-incaricato`)**: vista a quattro sezioni (sponsor, anagrafica, residenza e contatti, conferma) con nazione e PEC; chi arriva da link, cookie o invito ha lo sponsor **bloccato** (il valore inviato nel form viene ignorato); IBAN e documenti restano nell'onboarding dopo la firma.
- Nessuna migrazione. Non salvati perché non esistono nel profilo: profilo fiscale, tipo autofattura, posizione fiscale, lingua (presenti nei dati Odoo).
- Tecnico: `ConsultantAccess::grant()` riceve i dati del profilo; `AreaController::lockedSponsor()`.
- Test: `ConsultantAccessTest` (profilo completo con sponsor assente e IBAN, email obbligatoria con dati personali, minorenni e IBAN non validi) e `SitePagesTest` (sponsor del link non modificabile).


## 2026-10-15 — Simulazione incaricati: albero subito visibile
- La pagina «Simulazione incaricati» mostra l'albero reale del periodo già all'apertura (prima di simulare), con qualifica a colori, clienti e, per ogni incaricato, cosa manca per la qualifica successiva: indica dove intervenire.
- I pulsanti «+ clienti», «+ agenti», «sposta» sono attivi fin da subito; dopo la simulazione l'albero diventa quello «dopo» con le stesse informazioni.
- `IncaricatiSimulator::currentTree()` (sola lettura, nessuna scrittura nel database); albero di base in cache per 2 minuti.
- Albero della simulazione con due viste, come nelle pagine «Albero rete» ed «Elenco incaricati per livelli»: **Vista albero** (grafico con zoom e trascinamento, riquadri a colori della pietra, evidenziati quelli che cambiano, clic per dettagli e azioni) ed **Elenco per livelli** (tabella con livello, qualifica prima → dopo, diretti, clienti, requisiti mancanti, compensi e pulsanti). La vista scelta resta ricordata nel browser.
- Riepilogo della simulazione: tooltip su «Qualifiche che salgono/scendono» (chi, prima → dopo) e su «Compensi del mese» (suddivisione per tipo e incaricati con la variazione maggiore), con i codici di chi sale/scende sotto il numero.
- Simulazione: «Compensi del mese» ridenominato «Provvigioni degli incaricati nel mese»; tabella esplicativa per tipo (chi le riceve, come maturano, prima/dopo/differenza, totale) al posto del vecchio riquadro «Compensi per tipo»; corretta l'impaginazione del riquadro.
- Simulazione: il totale delle provvigioni mostra separati agenti simulati e incaricati reali (`summary.total_virtual`, `summary.total_real_diff`), con nota che il totale comprende gli agenti simulati.
- Simulazione: nella tabella delle provvigioni ogni tipo si apre al clic e mostra, per incaricato (reale o simulato), quanto matura prima → dopo e da quali vendite (origine, livello, vendite, ordini, base V.A.P., aliquota, requisiti); `IncaricatiSimulator::breakdown()`, primi 60 incaricati per tipo.
- Simulazione: nuova modifica «Aggiungi colonne» (anche pulsante «+ colonne» nell'albero): crea N linee dirette sotto l'incaricato, ciascuna con un agente Pearl e almeno M clienti (di default 90, il minimo di colonna del piano), per provare le qualifiche che richiedono colonne (es. Ruby: 3 colonne da 90 clienti). `IncaricatiSimulator::addColumns()` / `defaultColumnClients()`; test in `IncaricatiSimulationTest`.

## 2026-10-15 (sera) — Decisioni di Laura sul piano: compressione, retrocessione, pagina del piano
- **Compressione degli indiretti** (nuova politica K06 `kosmos_compression`, predefinita `skip`): chi non rispetta i requisiti del livello (o è escluso/sospeso) è saltato e il livello passa al primo upline che li rispetta; nessuno incassa due volte sulla stessa vendita; gli estesi restano a distanza genealogica. `none` ripristina il comportamento precedente. Il campo è nel pannello del piano (bozza → Qualifiche e requisiti).
- **Retrocessione su tutti i requisiti** (K05 `kosmos_rank_maintenance`, nuovo valore e predefinito `requirements`): ogni mese si paga la qualifica più alta di cui si rispettano ancora clienti, Pearl al 1° livello, struttura e colonne (`RankMaintenance`, dal basso verso l'alto: un Pearl che scende fa scendere anche chi sta sopra). Restano le protezioni (piano e «Mantieni qualifica» per singolo incaricato, già disponibile per gli admin con `network.restructure`). L'area incaricato mostra anche i requisiti da mantenere.
- **Piano attivo**: `rank_grace_days` vuoto e `rank_grace_until` = 31/12/2026 (decisione del 14/10); fino a quella data restano protetti gli importati dal vecchio piano; poi tutti ricalcolati.
- **Pagina «Il piano compensi»** (`/piano-compensi`, pubblica): generata dal piano in vigore (tabelle diretti, indiretti, estesi, qualifiche, Bonus di Struttura, pagamenti, avvertenze); linkata dalla casella di accettazione della domanda, dal menù dell'area incaricato, dal piè di pagina e dalla Lettera di Incarico.
- **Filtro e ricerca nell'elenco clienti** dell'incaricato (`AreaController::CUSTOMER_FILTERS`).
- **Regolamento v2.0 (bozza)**: riscritto secondo il nuovo piano (terminologia «Incaricato», niente «Consulente Attivo», regole di cliente, acquisto proprio, compressione, retrocessione, passaggio al nuovo piano, Bonus di Struttura, minimo pagabile 15 € in tutto il documento, pagamento mensile entro il 15). Da far vedere al legale/commercialista (fee 2,50 € per bonifico, acquisto proprio, resi).
- Kcard: refuso di un vecchio progetto, nessuna azione.
- Test: motore 103 verdi (compressione, retrocessione per clienti/Pearl/colonne/struttura), funzionali su pagina piano e filtro clienti.

## 2026-10-15 (pomeriggio) — Commissione bonifico eliminata, conferme di Laura
- **Commissione di 2,50 € per bonifico eliminata**: `LAMESS_BANK_FEE` predefinito 0,00 (`lamess.fiscal.bank_fee`, impostazione «Commissione bonifico sui prelievi»); tolta dal Regolamento v2.0 (6.1.a). Dove `.env` o le impostazioni di sistema hanno ancora 2,50 va messo 0.
- Confermato: l'acquisto proprio paga il compenso diretto all'incaricato, gli indiretti agli upline e conta come cliente per i requisiti e le qualifiche (già così nel motore, K04 acceso). Estesi: restano a distanza genealogica. Resi: per ora ignorati.

## 2026-10-16 — Sicurezza prima dell'apertura (esito dell'analisi del 15/10)
- **Prelievi wallet**: `WithdrawalService::request` controlla saldo e crea la richiesta dentro la transazione con `lockForUpdate` sul consulente; `approve` rilegge lo stato e blocca il consulente (niente doppio impegno del saldo con richieste simultanee).
- **Accesso**: blocco per account oltre al limite per IP (`SiteController::login`): 5 errori per email+IP, 15 per sola email, finestra di 15 minuti, azzerati da un accesso riuscito.
- **Cookie di sessione**: `config/session.php` → `secure` vero di default in produzione; `SESSION_SECURE_COOKIE=true` anche in `.env.cpanel.example`.
- **HTTPS forzato** in `public/.htaccess` (escluso `/.well-known/` e localhost).
- **Scheduler**: `withoutOverlapping(120)` su tutti i comandi tranne il battito.
- **Controllo messa online**: nuove voci «Cookie di sessione solo HTTPS» e «Log di audit protetto nel database».
- Test: nuovo `LoginLockoutTest` (3); Wallet 28 verdi. Suite completa 899/901: restano `ShopAdminTest::test_product_can_be_created_with_automatic_pv` e `ConsultantListTest::test_no_per_row_queries`, che falliscono già sul commit precedente (non legati a questo lavoro).
- Non fatto (a carico del server o rimandato): 2FA e `.env` live, backup fuori server e ripristino di prova, limiti PHP, controllo esterno su `/up`, timestamp nel webhook KMoney, CSP, `uncompromised()` sulle password.

## 2026-10-16 (mattina) — Log di audit importato da locale
- Sul server il Controllo dava BLOCCO sulla catena: le righe 1–352 erano del locale (verificate col dump del 12/10 e l'`APP_KEY` locale), le successive firmate con la chiave del server.
- Nuove impostazioni `LAMESS_AUDIT_KEY_PREVIOUS` e `LAMESS_AUDIT_KEY_PREVIOUS_UNTIL` (`lamess.audit_key_previous`, `…_until`): in verifica le righe fino all'id indicato usano la chiave precedente; le nuove righe usano sempre quella in vigore. Nessuna riga viene riscritta, il controllo del collegamento fra le righe resta intero.
- Test: `AuditLogTest` 15 verdi (nuovo: chiave precedente con confine sull'id).

## 2026-10-16 (mezzogiorno) — Firma con codice per email
- Nuovo driver di firma `email` (`EmailSignatureProvider`, notifica `SignatureOtpCode`): OTP di 6 cifre inviato per email, 15 minuti, 5 tentativi, nuovo invio dopo 60 secondi, impronta HMAC in cache (mai in chiaro), nessun codice a schermo; se l'invio email è spento lancia un errore chiaro e non invia nulla.
- Pagina Diventa Incaricato: pulsante «Invia di nuovo il codice» (`POST /area/diventa-incaricato/codice`, 5 al minuto).
- Controllo messa online: con driver `email` la voce «Firma con codice per email: invio attivo» (BLOCCO se la posta è spenta o su «log»); il driver `fake` resta BLOCCO.
- Test: `EmailSignatureTest` (5); suite 905/909 (restano i due fallimenti già presenti).

## 2026-10-16 (pomeriggio) — Stabilità e colli di bottiglia
- Saldo wallet e importi impegnati calcolati dal database con `SUM` (prima si caricavano tutte le righe in PHP).
- Immagini del negozio: cache di 30 giorni, `ETag`/`Last-Modified` e risposta 304 se il browser le ha già.
- Calcoli lunghi del periodo: tempo di esecuzione esteso (300 s), non si interrompono se la pagina viene chiusa, il blocco si rilascia anche dopo un errore fatale.
- Email ai clienti accodabili (`ShouldQueueAfterCommit`, 3 tentativi): con `QUEUE_CONNECTION=database` il cron lancia il lavoro di coda ogni minuto (`lamess:coda`); con `sync` nulla cambia. Codice di firma e reset password restano immediati.
- Test: `StabilityAndPerformanceTest` (3); suite 908/912, i 3 che non passano falliscono anche prima di questa modifica (ShopAdmin, ConsultantList, IncaricatiCareerProgress).

## 2026-10-16 (sera) — Colli di bottiglia, seconda parte
- Elenco clienti dell'incaricato (`/area/clienti`): 50 per pagina con navigazione, ricerca e filtri mantenuti; conteggi e riepilogo restano sul totale.
- Elenco incaricati dell'admin: `ConsultantList` è ora una sola istanza per richiesta (prima ripeteva ~100 query su piano e periodi, una per riga); con 20 righe il test «nessuna query per riga» torna verde.
- Reinvio fatture al gestionale (`InvoiceService::retryPending`): massimo 200 documenti per giro orario.
- I PDF (fatture, estratti, ricevute, tesserino, certificato) si generano uno alla volta su richiesta: non c'è una generazione in blocco da alleggerire.
- Test: `AreaPagesTest` (+1). Suite 909/913: restano `ShopAdminTest` e `IncaricatiCareerProgressTest` (già presenti prima) e `SubscriptionsAndMultiOrdersTest::test_customer_area_pages_and_actions`, che fallisce già sul codice precedente a queste modifiche (arriva dagli ultimi lavori sugli abbonamenti).


## 2026-10-19 — Revisione di documentazione e manuali
- Allineati ai lavori del 16/10: **1° liv.** al posto di «Diretti» (scheda 360° e manuale admin), elenco clienti dell'incaricato a 50 per pagina e blocco dell'accesso per troppi tentativi (manuale utente e guida breve), reinvio fatture a 200 per giro, `lamess:coda` e `withoutOverlapping` fra le operazioni pianificate (architettura), sezioni su sicurezza, prestazioni, email in coda e firma con codice nel manuale sviluppatori (§10).
- Decisioni aperte: commissione di prelievo 2,50 € tolta da OPEN-54 e A-01 (eliminata il 15/10, `LAMESS_BANK_FEE` = 0,00); A-12 riporta la richiesta di Laura sugli XML per email (invio mensile o a pulsante); A-03 annota che il test non compare fra i rossi dell'ultima esecuzione.
- README: elenco dei test rossi allineato al registro del 16/10.
- Dossier `docs/dossier.html` rigenerato (era fermo al 14/10).
- **Unione con il lavoro locale (19/10, mattina)**: il codice presente solo sul computer di Laura è stato unito a `main` (un solo conflitto, in `InvoiceService`, risolto tenendo il tetto di 200 per giro). Contiene: invio fatture «in blocco» (`LAMESS_SDI_SEND_MODE`, pulsante in *Fatture*, invio il 1° del mese alle 08:30), pagina pubblica `/regolamento` con il Regolamento v2.0 (link nel piè di pagina e nella domanda), `LAMESS_LEGACY_PLAN_HIDDEN` predefinito acceso, tolto dal modulo del piano il campo «giorni» della protezione qualifiche (resta la data fissa), test `PasswordRecoveryTest` e di invio in blocco.
- Non toccati: `CHANGELOG.md` (è quello di Laravel, non del portale), i documenti della vecchia piattaforma Odoo in `Documentazione/`.
- Non verificato qui: la suite di test non è stata rieseguita (dipendenze non installabili in questo ambiente).

## 2026-10-19 — Richieste del cliente (nazione, promemoria, barcode, founder, bimestrale, rete)
- **Nazione nel checkout**: campo «Nazione» in fatturazione e nel nuovo indirizzo di spedizione (Italia di default); ogni indirizzo salvato ha la sua nazione. Cambiando nazione la pagina si ricarica senza perdere i dati e ricalcola la spedizione (tariffe per paese in *Spedizioni*). Le nazioni aperte si decidono con `LAMESS_SHOP_COUNTRIES` nel `.env` (es. `IT,DE,FR`); finché non ne è aperta un'altra il menu mostra solo Italia.
- **Promemoria pre-addebito**: 7 giorni prima dell'addebito il cliente riceve un'email con data, importo indicativo e cosa controllare (carta o saldo KMoney). Una sola email per scadenza, nessuna agli abbonamenti in pausa o con addebito fallito. Parte dal comando `lamess:abbonamenti` (04:00). Giorni di preavviso in *Impostazioni di sistema → Abbonamenti e spedizione* (0 = spento). L'email di errore (carta/KMoney) esisteva già.
- **Codice a barre**: campo «Codice a barre (EAN)» nel prodotto (EAN-13 o EAN-8, cifra di controllo verificata), colonna e ricerca nell'elenco prodotti, azione «Codice a barre» con anteprima e download SVG per la stampa.
- **Ordini manuali**: il pulsante *Nuovo ordine* in *Vendite → Ordini* esisteva già (cliente, prodotti, pagamento, «già pagato»); la nazione ora mostra il nome.
- **Prezzi founder**: interruttore «founder» per incaricato (azione «Segna come founder» nel menu della riga, solo chi ha il permesso di ristrutturare la rete, con registro audit) e «Prezzo founder IVA inclusa» nella scheda prodotto (solo prodotti senza varianti). Il founder vede e paga il prezzo founder in negozio, carrello, checkout, ordini manuali e abbonamenti; cambia solo il prezzo, i punti restano quelli del prodotto. Gli sconti si applicano sopra il prezzo founder.
- **Abbonamento bimestrale**: nuova scelta nel checkout, un ordine ogni 2 mesi con spedizione sempre gratuita. Saltare un ciclo sposta l'ordine di 2 mesi; l'impegno di 12 mesi diventa 6 ordini; KMoney riceve la cadenza reale.
- **Rete**: nella scheda di ogni incaricato del proprio ramo l'incaricato vede email, telefono, città e provincia; admin e super_admin decidono cosa mostrare in *Impostazioni di sistema → Rete* (due interruttori). I clienti indiretti restano senza recapiti. Chi sta per diventare incaricato vede il codice dello sponsor, non il nome.
- Migrazioni nuove: `2026_11_07_100000` … `2026_11_07_140000` (nazione indirizzi, promemoria, barcode, founder, cadenza).
- Non ancora: variazione dei dati fiscali con nuova firma della Lettera di Incarico (in attesa di decisioni).

## 2026-10-20 — Fatture verso l'estero, Natura/bollo, annullo ordini in sospeso
- **Clienti esteri in fattura elettronica**: il paese del cliente (`country_code` dell'ordine) finisce in `IdPaese` e `Nazione`; codice destinatario `XXXXXXX` (nessuna PEC); partita IVA senza prefisso del paese; un privato estero senza partita IVA riceve `IdCodice 0000000`; provincia solo per l'Italia; CAP non a 5 cifre → `00000`. Il codice fiscale è obbligatorio al checkout solo per i privati italiani. Fatture già emesse senza paese restano italiane.
- **Natura e bollo**: le righe a IVA 0 portano `Natura` (riga e riepilogo) e `RiferimentoNormativo`. Cliente fuori UE → `N3.1`; azienda UE con partita IVA → `N3.2`; altrimenti `LAMESS_VAT_ZERO_NATURE` (default `N2.2`). Per N1/N2.x/N3.5/N3.6/N4 sopra 77,47 € si scrive il bollo virtuale da 2 €. File validati contro lo schema FatturaPA nei test.
- **Annullo ordini in sospeso alla chiusura del mese**: `PeriodService::close()` annulla da solo gli ordini ancora «in attesa di pagamento» creati fino a fine mese (o con righe PV in attesa nel mese): righe PV annullate, magazzino e sconti liberati, quota KMoney già incassata riaccreditata, evento sull'ordine e riga nel registro audit. Un ordine non annullabile resta e blocca la chiusura come prima.
- **Ancora aperto (commercialista)**: quale IVA applicare ai clienti UE/extra-UE (oggi si applica l'aliquota del prodotto) e OSS; scelta della Natura per i casi diversi da quelli sopra.

## 2026-10-21 — Nuovo cliente da admin e assegnazione libera dei punti
- **Nuovo cliente**: pulsante in *Rete → Clienti (FAN)* per admin (permesso `network.manage`) e super_admin. Privato o azienda, stessi controlli della registrazione (`CustomerService::createByAdmin`), incaricato di riferimento facoltativo, password facoltativa (altrimenti casuale + invio del link per sceglierla). Log `customer.created_by_admin`.
- **Punti assegnati**: nuova voce *Compensi → Punti assegnati* e azione **Assegna punti** (permesso `periods.manage`). Beneficiario, punti, data, causale e, a scelta, solo punteggio / cliente valido per N mesi senza compensi / come un acquisto; opzione per assegnare anche allo sponsor. Movimento «grant» nel registro (`PointsGrant`), annullabile con motivo finché il periodo è aperto. Log `points.granted` e `points.cancelled`.
- **Motore**: `SnapshotBuilder` legge ora anche i movimenti senza ordine che hanno un cliente indicato (`pv_movements.client_user_id`) per l'identità del cliente, le validità e il primo acquisto; i movimenti con ordine si calcolano come prima.
- **Tesserino**: documentato cosa vi finisce (nome, codice, foto, validità e sponsor dalla lettera; niente indirizzo, codice fiscale o IBAN).
- Migrazione nuova: `2026_11_08_100000` (cliente dei movimenti senza ordine). Test: `PointsGrantTest`, `PointsAndCustomersAdminTest`.
- **SQL per phpMyAdmin** (sul sito online non si lancia `migrate`; da eseguire *prima* del deploy, non rompe il codice vecchio):
  ```sql
  ALTER TABLE `pv_movements` ADD COLUMN `client_user_id` BIGINT UNSIGNED NULL;
  ALTER TABLE `pv_movements` ADD CONSTRAINT `pv_movements_client_user_id_foreign` FOREIGN KEY (`client_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
  INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_11_08_100000_pv_movements_client_user', MAX(`batch`) FROM `migrations`;
  ```

## 2026-10-21 — Tasca, statini mensile e settimanale, durata per pezzo
- Il **wallet** si chiama ora **Tasca** («i tuoi documenti»): raccoglie statini mensili, statini settimanali dei bonus, ricevute e fatture, ed è il modo di pagamento standard: l'incaricato **chiede il prelievo** al posto del bonifico automatico del 15. Il prelievo non richiede il documento fiscale: fattura o ricevuta si **aggiungono dopo** (facoltative), con download per l'incaricato e per l'admin.
- **Statino mensile**: in alto l'importo totale, poi **Dirette** e **Indirette** (gli estesi stanno dentro le Indirette). **Statino settimanale dei Bonus di Struttura**, separato, con PDF (area incaricato → «Statini bonus»; un statino a settimana). I Bonus di Struttura confermati sono accreditati nella Tasca **una sola volta**.
- Prodotto: nuovo interruttore **«I pezzi moltiplicano i mesi»** (acceso di default): se acceso, i pezzi di una riga moltiplicano i mesi di validità cliente.
- Migrazioni `2026_11_09_100000_product_months_per_piece` e `2026_11_09_110000_tasca`: sul sito live esegui **prima del deploy**, in phpMyAdmin → SQL:
  ```sql
  ALTER TABLE `products` ADD COLUMN `months_per_piece` TINYINT(1) NOT NULL DEFAULT 1;
  ALTER TABLE `structure_bonuses` ADD COLUMN `wallet_credited_at` TIMESTAMP NULL DEFAULT NULL;
  ALTER TABLE `withdrawal_requests`
    ADD COLUMN `document_path` VARCHAR(255) NULL,
    ADD COLUMN `document_name` VARCHAR(255) NULL,
    ADD COLUMN `document_added_at` TIMESTAMP NULL DEFAULT NULL;
  INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_11_09_100000_product_months_per_piece', MAX(`batch`) FROM `migrations`;
  INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_11_09_110000_tasca', MAX(`batch`) FROM `migrations`;
  ```
- Test: `TascaTest`, `StructureBonusTest`, `ProductVapAndValidityTest`.
- Rettifica manuale della Tasca (22/10): corretto l'errore 500 all'invio (mancava l'etichetta dell'incaricato nel campo di scelta).

## 2026-10-23 — Agenti regalati (aiuti di qualifica)
- Nuova voce **Compensi → Agenti regalati** e opzione nel modulo **Assegna punti** («Livello degli agenti», «Quanti agenti», «Valgono fino al»): l'azienda aggiunge **N agenti virtuali di una qualifica** sotto un incaricato per aiutarlo a qualificarsi (esempio: 2 Pearl sotto un Pearl per fargli raggiungere Crystal; fino a 50). Contano al 1° livello **solo per i requisiti della qualifica**: non generano compensi, non sono clienti, non entrano nei requisiti dei livelli indiretti e non compaiono nella rete. Valgono dalla data indicata fino alla scadenza (vuota = finché non vengono annullati); ogni assegnazione e annullamento è nel log. Dopo l'assegnazione il calcolo del mese riparte subito.
- Migrazione `2026_11_10_100000_rank_helps`: sul sito live esegui **prima del deploy**, in phpMyAdmin → SQL:
  ```sql
  CREATE TABLE IF NOT EXISTS `rank_helps` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `consultant_id` BIGINT UNSIGNED NOT NULL,
    `rank_code` VARCHAR(40) NOT NULL,
    `quantity` SMALLINT UNSIGNED NOT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `starts_at` DATETIME NOT NULL,
    `valid_until` DATE NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'confirmed',
    `created_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `rank_helps_status_starts_at_index` (`status`, `starts_at`),
    CONSTRAINT `rank_helps_consultant_id_foreign` FOREIGN KEY (`consultant_id`) REFERENCES `consultants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `rank_helps_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_11_10_100000_rank_helps', MAX(`batch`) FROM `migrations`;
  ```
- Test: `RankHelpGrantTest`, `KosmosPlanTest`.

## 2026-10-21 — Segno «Easyfatt» sulle fatture e note di credito
- **Elenco Fatture e Note di credito**: nuova colonna **Easyfatt** (verde «Esportata» con data nel suggerimento, grigio «Da esportare»), filtro *Tutte / Esportate / Da esportare* e azione di riga per segnare o rimettere da esportare a mano.
- **Esporta per Easyfatt**: ora ha tre interruttori. «Solo i documenti non ancora esportati» (acceso), «Includi le ricevute» (spento), «Segna i documenti come esportati» (acceso: spegnilo per una prova). Il segno si mette quando il file viene scaricato; l'operazione finisce nel log di audit (`export.easyfatt`).
- Migrazione nuova: `2026_11_08_110000` (`invoices.easyfatt_exported_at` e `credit_notes.easyfatt_exported_at`). Test: `EasyfattXmlTest`.
- **SQL per phpMyAdmin** (da eseguire *prima* del deploy: la colonna è facoltativa e il codice vecchio non la usa):
  ```sql
  ALTER TABLE `invoices` ADD COLUMN `easyfatt_exported_at` TIMESTAMP NULL DEFAULT NULL;
  ALTER TABLE `credit_notes` ADD COLUMN `easyfatt_exported_at` TIMESTAMP NULL DEFAULT NULL;
  INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_11_08_110000_easyfatt_exported_at', MAX(`batch`) FROM `migrations`;
  ```
- **Punti assegnati visibili**: nella scheda incaricato (admin, Riepilogo) e nella panoramica dell'area dell'incaricato (riquadro «Punti assegnati», solo se ne ha; l'incaricato non vede il nome del cliente indicato). Nel form «Assegna punti» il cliente predefinito è il beneficiario stesso.

## 2026-10-21 — Nuova pagina «Modifica incaricato»
- La pagina di modifica dell'incaricato era un modulo generato dalle colonne del database (solo super_admin, nomi tecnici come «Chiave anagrafica», senza recapiti né IBAN). Ora è una **scheda a sezioni** (Anagrafica · Contatti e residenza · Pagamenti e fisco · Iscrizione e stato · Piano e compensi) con intestazione riassuntiva, date in formato italiano e pulsante «Salva modifiche» sempre visibile.
- **Aperta anche agli admin** con il permesso `network.manage` (prima solo il super_admin); pulsante «Modifica dati» nella scheda e nel menu ⋮ dell'elenco.
- Salva in un colpo solo i dati di incaricato, utente di accesso e profilo (email, nome, codice fiscale, recapiti). Senza accesso, scrivere l'email lo crea.
- Restano protetti: sponsor e stato (si cambiano dalla scheda), codice attività (solo super_admin), IBAN (mascherato; per cambiarlo si scrive il nuovo). Log di audit con valori prima e dopo.
- Nessuna migrazione, nessun SQL da eseguire. Test: `EditConsultantTest`.

## 2026-10-21 — Tesserino generato alla firma della lettera
- Il tesserino (numero e validità) ora nasce **alla firma della lettera d'incarico**, non più all'accettazione dell'admin; vale e si stampa subito. Anche per gli incaricati iscritti dall'admin (la data di firma vale come firma). Se la generazione del PDF fallisce, la firma o l'iscrizione non si bloccano: l'errore va nei log.
- Per chi era già iscritto senza tesserino: si genera al primo «Salva modifiche» nella pagina di modifica.
- Nessuna migrazione. Test aggiornati: `CustomersAndIvdTest`, `SitePagesTest`, `ConsultantAccessTest`, `EditConsultantTest`.

## 2026-10-21 — Nuova pagina «Modifica cliente»
- Stessa impostazione della pagina incaricato: **scheda a sezioni** (Anagrafica · Contatti e residenza · Registrazione) con intestazione riassuntiva (tipo, incaricato, ordini pagati e spesa, abbonamenti attivi) e pulsante «Salva modifiche» sempre visibile.
- **Aperta agli admin** con `network.manage` (prima solo il super_admin); pulsante «Modifica dati» sulla riga dell'elenco.
- Privato o azienda: per l'azienda servono ragione sociale e partita IVA. L'email deve essere unica. Salva insieme utente, profilo e cliente; log `customer.updated` con valori prima e dopo.
- L'incaricato assegnato non si cambia da qui: c'è **Riassegna** in alto (permesso `network.restructure`, con motivo). Nessuna migrazione. Test: `EditCustomerTest`.

## 2026-10-22 — Tutte le pagine «Modifica» con lo stesso aspetto
- Le pagine di modifica generate dalla struttura del database (periodi, liquidazioni, pagamenti, ordini, fatture, note di credito, abbonamenti, ticket, sanzioni, ecc., riservate al super_admin) ora hanno: **intestazione riassuntiva** (nome o numero, stato in chiaro, date di creazione e ultima modifica), campi **raggruppati in sezioni** (Dati principali · Collegamenti · Importi e quantità · Stato e date · Note e dettagli), date in formato italiano, «Salva modifiche» sempre visibile, «Annulla» e «Torna all'elenco».
- Le liquidazioni mostrano lo stato in italiano (Aperta, Accreditata sul portafoglio, Pagata) invece del codice.
- Le pagine già curate (omaggi, eventi, news, download, spedizioni) sono divise in sezioni con spiegazione; tutte le pagine di modifica hanno i pulsanti fissi in basso e tornano all'elenco dopo il salvataggio.
- Nessuna migrazione. Il comportamento dei dati non cambia.

## 2026-10-23 — Checkout, KMoney, "I miei clienti", accesso come, etichette
- Checkout: indirizzo di fatturazione bloccato dopo il primo salvataggio (si cambia da «I miei dati»); «Salva i dati» obbligatorio prima di abilitare «Conferma ordine»; la bozza (metodo di pagamento, abbonamento, privacy) non si perde nel passaggio da KMoney.
- KMoney: collegamento obbligatorio prima dell'ordine, massimale suggerito di 1500 KY (`KMONEY_MANDATE_LIMIT_KY`) per il pagamento automatico a un clic; conferme ridotte; niente «Conferma e paga» dopo il pagamento; importi KMoney in «KY» senza decimali nelle email; email e footer in italiano.
- «I miei clienti»: l'incaricato è il primo cliente (acquisti propri), filtro per mese, colori per chi dà il punto / sta per scadere / senza ordine (vale 0). «Team 1° liv.» diventa «1° liv.».
- Super admin: «Accedi come» da Utenti, Clienti, Incaricati (fascia di avviso, pagamenti/password/firme/IBAN/prelievi bloccati, log di audit `impersonation.*`).
- Etichette di spedizione: singola (ordine) o in blocco su foglio A4 (8 per foglio) dall'elenco ordini.

## 2026-11-03 — Esportazione ZIP per il commercialista (ECONS di TeamSystem)
- **Fatture → «Esporta per il commercialista (ZIP)»**: dato un periodo, scarica uno ZIP con gli XML FatturaPA delle fatture e note di credito emesse (cartella `xml/`, gli stessi file dello SdI), un `riepilogo.csv` (una riga per documento e aliquota IVA: tipo TD01/TD04, numero, data, cliente, CF, P.IVA, paese, aliquota, natura, imponibile, imposta, totale, metodo di pagamento, ordine, file XML, esito) e un `LEGGIMI.txt`. Note di credito con importi negativi, ricevute dei privati escluse (contate nel messaggio), documenti con dati incompleti senza XML e con «ERRORE» nella colonna Esito.
- Non segna i documenti come esportati e non cambia nulla nel portale: si sceglie il periodo. Operazione nel log di audit (`export.accounting`). Nessuna migrazione.
- Richiede l'estensione PHP `zip` sul server (se manca il pulsante non compare).
- Codice: `AccountingExport`, azione `accountingZip` in `ListInvoices`. Test: `AccountingExportTest` (da eseguire sulla macchina di sviluppo).

## 2026-11-03 — «Dettaglio compensi» nell'area dell'incaricato
- Nuova pagina **`/area/compensi`** (solo incaricati, sola lettura): per il mese scelto mostra **diretti**, **indiretti** (con riepilogo per livello), **Bonus istantaneo** (Bonus di Struttura, con stato In calcolo / Confermato / Pagato / In Tasca), storni, **compensi non maturati** con ciò che manca per sbloccarli e le regole del piano. Ogni riga ha la frase che dice da quale ordine nasce, base, percentuale, catena e requisiti: la stessa spiegazione della pagina admin «Dettaglio compensi».
- Il riquadro **Compensi provvisori** della panoramica e quello della pagina «Il mio ramo» ora aprono questa pagina; il riquadro Bonus di Struttura porta alla sua sezione. Nuova voce **Dettaglio compensi** nel menu *Compensi*.
- `CommissionExplainer::for()` ha un terzo parametro `$forConsultant`: nella vista dell'incaricato non ci sono link al back-office, nomi dei clienti altrui sugli indiretti né avvisi per l'admin. L'admin non cambia.
- Codice: `AreaController::earnings`, viste `site/area/earnings` e `_earning-line`. Nessuna migrazione. Test aggiunti a `CommissionExplainerTest` (da eseguire sulla macchina di sviluppo).

## 2026-11-09 — Sito in 6 lingue (it, en, nl, fr, de, es) con gestione dal pannello
- **Come funziona**: il testo italiano scritto nelle viste è la chiave (`__('Testo italiano')`); le traduzioni stanno nei file `lang/xx.json` consegnati con il codice e in due tabelle (`languages`, `translation_strings`) che il super_admin modifica dal pannello e che vincono sui file. Le lingue di partenza sono in **bozza**: le vede solo chi ha accesso al back-office, finché non si attivano.
- **Pubblico**: la lingua è un prefisso nell'indirizzo (`/en/negozio`; l'italiano resta senza prefisso), si ricorda nel cookie `lm_lang` e, per chi ha un account, in `users.locale` (anche per le email al cliente). Selettore con bandiere nel sito; tag `hreflang` per i motori di ricerca.
- **Admin (solo super_admin)**: *Lingue* (crea una lingua nuova, attiva/disattiva, ordine, lingua predefinita) e *Traduzioni* (filtri per lingua e stato, modifica anche del testo italiano, «Traduci le mancanti» con il motore scelto in `I18N_DRIVER`, «Approva», esporta/importa CSV per il traduttore, «Cerca nuove stringhe»). Stati: mancante · automatica (da rivedere) · approvata. Impostazione di sistema «Mostra le traduzioni non ancora riviste».
- **Cosa è tradotto in questa fase**: sito pubblico, negozio, carrello e checkout, accesso/registrazione/candidature, pagine legali (di cortesia: fa fede l'italiano), pagina «Perché Lamèss» e studi, pagine di errore. **Restano in italiano**: pannello admin, PDF fiscali e fatture SdI, lettera d'incarico firmata, area riservata dell'incaricato, notifiche e testi presi dal database (prodotti, categorie, news). Si faranno nella fase 2.
- **Primo rilascio solo vetrina e negozio**: l'area riservata dell'incaricato (tutto `/area/...` tranne carrello, checkout, ordini e abbonamenti del cliente) e le pagine personali `/i/...` restano sempre in italiano e senza selettore di lingua; l'elenco delle parti tradotte dentro `/area` è `translated_area` in `config/i18n.php`. Il back-office è sempre in italiano.
- **Le ~1.150 frasi sono tradotte in automatico e vanno riviste da un traduttore** (stato «automatica»): l'approvazione si fa da Admin → Traduzioni. I nomi dei prodotti, i marchi (VERISOL®, Lamèss, KMoney) e le fonti bibliografiche non si traducono.
- **Migrazione (MySQL, da incollare in phpMyAdmin)** — crea le due tabelle, aggiunge `users.locale` e inserisce le 6 lingue (solo l'italiano attivo):

```sql
CREATE TABLE `languages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(8) NOT NULL,
  `name` varchar(60) NOT NULL,
  `native_name` varchar(60) NOT NULL,
  `flag` varchar(20) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'draft',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `sort` smallint unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `languages_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `translation_strings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `locale` varchar(8) NOT NULL,
  `key_hash` char(32) NOT NULL,
  `source` longtext NOT NULL,
  `text` longtext DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'missing',
  `orphan` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `translation_strings_locale_key_hash_unique` (`locale`,`key_hash`),
  KEY `translation_strings_locale_status_index` (`locale`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users` ADD COLUMN `locale` varchar(8) NULL AFTER `email`;

INSERT INTO `languages` (`code`,`name`,`native_name`,`flag`,`status`,`is_default`,`sort`,`created_at`,`updated_at`) VALUES
('it','Italian','Italiano','it','active',1,1,NOW(),NOW()),
('en','English','English','gb','draft',0,2,NOW(),NOW()),
('nl','Dutch','Nederlands','nl','draft',0,3,NOW(),NOW()),
('fr','French','Français','fr','draft',0,4,NOW(),NOW()),
('de','German','Deutsch','de','draft',0,5,NOW(),NOW()),
('es','Spanish','Español','es','draft',0,6,NOW(),NOW());

INSERT INTO `migrations` (`migration`,`batch`) VALUES ('2026_11_20_100000_i18n_languages_and_strings', (SELECT IFNULL(MAX(b.batch),0)+1 FROM (SELECT batch FROM migrations) b));
```
- **Dopo la migrazione**: nel pannello, *Traduzioni → «Cerca nuove stringhe»* porta nel database le frasi dei file `lang/xx.json` (stato «automatica»); poi *Lingue → Attiva* quando il traduttore ha finito. Nel `.env` basta `I18N_DRIVER` (default `fake`: nessuna chiamata esterna); per tradurre in automatico le frasi future servono `DEEPL_API_KEY`, `GOOGLE_TRANSLATE_API_KEY` o `ANTHROPIC_API_KEY`.
- Test: `LocaleRoutingTest` (12), `TranslationAdminTest` (16).
