# Registro aggiornamenti

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
- Allineati ai lavori del 16/10: **Team 1° liv.** al posto di «Diretti» (scheda 360° e manuale admin), elenco clienti dell'incaricato a 50 per pagina e blocco dell'accesso per troppi tentativi (manuale utente e guida breve), reinvio fatture a 200 per giro, `lamess:coda` e `withoutOverlapping` fra le operazioni pianificate (architettura), sezioni su sicurezza, prestazioni, email in coda e firma con codice nel manuale sviluppatori (§10).
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
