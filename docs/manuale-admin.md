# Manuale amministratore

> Stato: motore, ordini, chiusura mensile, liquidazioni, pagamenti, statini ed export sono disponibili nel pannello (menu Liquidazioni, Pagamenti, Abbonamenti; scheda del periodo per chiusura, export e riapertura).
> Questo manuale viene esteso a ogni passo.

## Il mese in breve
1. **Durante il mese**: ordini e punti arrivano da soli; puoi vedere una stima (*Periodi → Stima live*).
2. **Il giorno 1 del mese dopo (01:00)**: se la chiusura automatica è accesa, parte il calcolo ufficiale del mese appena finito (e, se scelto, la chiusura).
3. **Revisione**: apri il mese in *Periodi*, controlla i numeri e premi **Chiudi periodo**. Alla chiusura nascono le **liquidazioni** (maturato e trattenuto di ciascun consulente).
4. **Dal 15 (05:00)**: i pagamenti «da pagare» vengono preparati da soli.
5. **Tu**: *Pagamenti → Esporta bonifici da fare (CSV)*, esegui i bonifici in banca, poi **Segna pagato** (riferimento e data).
6. I consulenti vedono lo statino del mese in *I miei statini* (pagina e PDF).

## Attenzione
- Le politiche con stato `provisional` sono default provvisori: non approvare pagamenti reali basati su di esse finché il cliente non conferma.
- Un cambio di piano non deve alterare periodi già chiusi: ogni risultato conserva l'hash dell'input.
- Un consulente è pagabile solo con **KYC verificato** e **IBAN** inseriti, e senza blocco bonus. Gli altri restano in saldo e compaiono tra i «bloccati» alla preparazione.
- Sotto i 15 € (parametro `min_payout`) l'importo non si paga e si somma al mese dopo. I saldi negativi si riportano.
- Prima dell'uso vero imposta in *Impostazioni → Venditore* la ragione sociale e i dati: compaiono su fatture e statini.

## Ciclo mensile da riga di comando (aggiunta 01/10/2026)
Con il pannello non serve, ma in locale o in emergenza il periodo si gestisce anche con `php artisan lamess:periodo`:
1. `apri AAAA-MM` — apre il mese con il piano attivo.
2. `live AAAA-MM` — stima dei compensi, ripetibile.
3. `runtime AAAA-MM` — calcolo ufficiale (il periodo passa in «review»).
4. `chiudi AAAA-MM` — richiede: runtime eseguito, nessun movimento in attesa di pagamento, dati non cambiati dopo il runtime. Le righe vengono bloccate e il periodo non è più modificabile.
Dopo la chiusura le correzioni (resi) si registrano nel periodo aperto successivo; il sistema calcola gli storni.

## Back-office web (aggiunta 01/10/2026)
Accesso: `/admin`. Il super_admin si crea con `php artisan lamess:superadmin email` (o dall'installazione guidata); gli altri utenti del back-office li crea il super_admin dalla pagina **Utenti** (password temporanea mostrata una volta sola). `lamess:admin-user` resta per uso da terminale e non crea mai super_admin.

**Piano compensi** — elenco versioni (bozza / attivo / archiviato). «Nuova bozza» parte dal piano ufficiale e valida il JSON con il motore. Sulla versione: **Simula impatto** (ricalcola un periodo con questa versione e mostra le differenze per bonus, senza scrivere nulla), **Attiva** (archivia la versione precedente; i periodi già aperti mantengono il loro piano), **Duplica come bozza**. Le versioni attive non si modificano.

**Periodi** — «Apri periodo» (AAAA-MM). Nel dettaglio: **Stima (live)** (non definitiva), **Runtime ufficiale** (periodo in «review»), **Chiudi periodo** (blocca le righe; bloccato se ci sono movimenti in attesa di pagamento o se i dati sono cambiati dopo il runtime).

**Movimenti PV** — registrazione di vendite, rettifiche e resi (reso = PV negativo con riferimento al movimento stornato). Un movimento non si modifica: si conferma o annulla se «in attesa di pagamento»; in un periodo chiuso non si tocca più. Una data fuori dal periodo viene rifiutata.

**Consulenti** — iscrizione (sponsor non modificabile dopo), **Sospendi**, **Riattiva**, **Cessa** (motivazione obbligatoria; i diretti passano al primo ascendente attivo). La scheda mostra sponsor attuale e originale e la cronologia degli stati.

Nota: un consulente senza onboarding completo non matura le commissioni indirette (politica D06, default provvisorio). L'onboarding/KYC si gestisce dalle sezioni descritte sotto.

## Onboarding, KYC e canoni (aggiunta 01/10/2026)
- **Contratti IVD**: elenco predefinito «firmati». **Accetta** (genera tesserino PDF e invia la mail di benvenuto) o **Rifiuta** (il consulente torna FAN; i movimenti dei periodi non chiusi passano allo sponsor). **Lettera firmata** apre il PDF (accessibile solo a chi ha il permesso).
- **Documenti KYC**: predefinito «da verificare». **Apri**, **Approva**, **Rifiuta** (motivazione visibile al consulente). Quando tutti i documenti obbligatori sono approvati, con IBAN e dati completi, l'onboarding risulta completo.
- **Canoni annuali**: predefinito «da pagare». **Segna pagato** (bonifico/carta/wallet) rinnova il contratto. Il comando `php artisan lamess:canoni` (schedulato ogni notte alle 03:30) crea i canoni, invia i promemoria e fa scadere i contratti non pagati.
- **Clienti (FAN)**: elenco in sola lettura.

## Ruoli, 2FA e cruscotto (aggiunta 02/10/2026)
**Ruoli** (creare utenti: `php artisan lamess:admin-user email --role=NOME`; ricalcolare la matrice: `php artisan lamess:ruoli`):

| Ruolo | Può |
|---|---|
| super_admin | tutto |
| admin_network | consulenti (anche crearli/cessarli), contratti IVD, KYC, vede KPI e periodi |
| admin_finance | canoni, periodi e movimenti PV (anche scrivere), vede KPI e rete |
| admin_compplan | piano compensi (creare/attivare), vede periodi e rete |
| support | vede rete e clienti, verifica KYC |
| board | sola lettura: KPI, rete, periodi, piano |

Le azioni non consentite al tuo ruolo non compaiono. I ruoli `consultant` e `customer` non entrano nel back-office.

**2FA**: con `ADMIN_2FA_REQUIRED=true` (predefinito) al primo accesso il pannello chiede di collegare un'app di autenticazione (Google Authenticator, Authy…) dalla pagina **Profilo**; vengono mostrati i codici di recupero (da conservare). Ai login successivi serve il codice a 6 cifre.

**Cruscotto**: scegli il periodo in alto. Mostra fatturato (CV), PV, compensi maturati e trattenuti, payout ratio (compensi/CV), consulenti attivi, nuovi FAN e consulenti, code di lavoro (contratti da accettare, KYC, canoni) e due grafici (qualifiche, compensi per tipo). I dati di periodo vengono dall'ultimo calcolo: ufficiale se c'è, altrimenti stima live.

## Negozio, ordini e fatture (aggiunta 03/10/2026)
- **Prodotti**: SKU, prezzo netto, IVA, PV e CV. **PV vuoto = imponibile / 1,23**; **CV vuoto = PV** (doppio se «Promo Rank»). I valori si congelano sull'ordine: cambiare un prodotto non tocca gli ordini già fatti. Un **kit** ha componenti a prezzo e PV zero. «Paesi» vuoto = tutti i paesi abilitati (`lamess.shop_countries`).
- **Omaggi (bundle)**: «compra N di X → M omaggi di Y», ripetibile a ogni multiplo, con periodo di validità. Gli omaggi hanno PV zero.
- **Spedizioni**: tariffa per paese (non genera PV).
- **Ordini**: **Conferma pagamento** (bonifico ricevuto → genera i punti nel mese di oggi e la fattura), **Spedisci** (tracking), **Annulla** (solo se non pagato), **Reso** (scegli righe e quantità, motivazione obbligatoria: emette la nota di credito e registra il movimento PV negativo).
- **Reso e periodi**: se il periodo del movimento originale è ancora aperto lo storno va lì; se è chiuso va nel mese corrente e il calcolo recupera i compensi (clawback).
- **Fatture / Note di credito**: elenco con PDF di cortesia e stato di consegna al gestionale (Da inviare / Inviata / Errore). **Reinvia** riprova; un invio automatico orario (`lamess:fatture`) riprova da solo. Un errore del gestionale non blocca mai l'ordine.
- Numerazione progressiva per anno: `FT/2026/00001`, `NC/2026/00001`, ordini `ORD-2026-00001`.
- Il periodo del mese si **apre da solo** al primo pagamento se non esiste (parametro `auto_open_period`).
- Permessi: `orders.view`, `orders.manage`, `catalog.manage` (vedi tabella ruoli: admin_finance gestisce, support/board/admin_network leggono).

## Importare il catalogo (aggiunta 04/10/2026)
Il listino reale (35 prodotti «preferiti» del listino Odoo, prezzi IVA inclusa, PV dalla colonna «PR numerico») è in `database/data/catalogo-odoo.csv`.
```
php artisan lamess:importa-prodotti --prova     # solo riepilogo, non scrive
php artisan lamess:importa-prodotti             # importa o aggiorna (per SKU, nessun duplicato)
php artisan lamess:importa-prodotti mio-file.csv --netti   # un altro file, con prezzi già al netto IVA
```
Formato CSV (separatore `;`, UTF-8): `sku;nome;prezzo_ivato;pv;attivo;note`. Dopo l'importazione ritocca tutto da **Prodotti**.
Da verificare con il cliente: i prodotti con stesso nome e prezzi diversi (es. «1 BOX COSMETIC COFFEE» a 129,90 / 114,90 / 104,92) sono stati importati ma **disattivati** (resta attivo il prezzo più alto), come i listini «RESELLER» (PV zero, da attivare solo con una regola riservata). Gli omaggi a prezzo zero non si vendono direttamente: si usano nelle regole **Omaggi**.

## Tre livelli di accesso e log di audit (aggiunta 02/10/2026)
- **super_admin** (solo il titolare del sistema): pagine *Utenti*, *Impostazioni di sistema*, *Log di audit* e *Verifica del log*. Non è visibile agli altri ruoli: non compare in elenchi, ricerche, esportazioni; un indirizzo diretto risponde 404. Nei campi «approvato da» dei dati di business compare «Amministrazione».
- **admin** (il cliente): vede e fa solo ciò che il super_admin gli concede, partendo da un modello (es. Finanza, Rete). Non può mai ricevere gestione utenti, impostazioni di sistema o log.
- **Log di audit**: registra accessi, cambi di ruoli/permessi, modifiche a utenti e impostazioni, importazioni. È a sola aggiunta con catena di hash: *Verifica del log* controlla ogni riga e mostra l'ancora da annotare.
- **Password dimenticata**: dal sito (`/password-dimenticata`) e dal pannello. Le email partono solo se l'invio è acceso nelle Impostazioni di sistema.


## Pagamenti automatici e controllo del cron
- Dal 15 del mese successivo (parametro `payout_day` del piano) i pagamenti dei mesi chiusi vengono preparati da soli. Resta da fare il bonifico in banca: scarica «Esporta bonifici da fare (CSV)» e, a bonifico fatto, usa «Segna pagato».
- Il riquadro «Operazioni automatiche» della dashboard deve dire **Attivo**. Se dice «Fermo» o «Mai partito», il cron di cPanel non sta girando: nulla di automatico (canoni, fatture, abbonamenti, pagamenti) parte.

## Chiusura mensile automatica
In *Impostazioni → Chiusura mensile* scegli: **Spenta**, **Solo calcolo ufficiale** (il mese va in revisione e lo chiudi tu da *Periodi*) oppure **Calcola e chiude da sola**. Parte la notte del giorno 1 alle 01:00. Se ci sono movimenti PV in attesa di pagamento si ferma e riprova ogni notte (lo vedi in dashboard). I mesi più vecchi di 45 giorni, e quelli che hanno davanti un mese ancora aperto, vanno chiusi a mano.
In locale il cron non c'è: per provare le operazioni automatiche lancia `php artisan schedule:work` in un terminale e lascialo aperto.

## Mesi chiusi prima dei pagamenti nel portale
I mesi già pagati con altri strumenti (gen–set 2026) non hanno liquidazioni. Per ciascuno apri *Periodi* → il mese → **Segna come storico già pagato**: da quel momento non entrano più in nessun pagamento. Fallo prima del primo «Prepara pagamenti» e, se i dati sono veri, solo per mesi che hai davvero già pagato.

## Liquidazioni, pagamenti, statini ed export
- **Liquidazioni** (menu): una riga per consulente e mese, con maturato, trattenuto e stato (aperta / pagata).
- **Pagamenti** (menu): *Prepara pagamenti* (a mano, se non vuoi aspettare il 15), *Esporta bonifici da fare (CSV)* (separatore `;`, IBAN, importo e causale), *Segna pagato*, *Annulla* (con motivo: le liquidazioni tornano disponibili).
- **Scheda del periodo**: *Esporta provvigioni (CSV)*, *Genera liquidazioni* (solo per mesi chiusi senza liquidazioni), *Segna come storico già pagato*, *Riapri il mese* (solo super_admin, con motivo; solo l'ultimo mese chiuso e solo se nessun importo è già pagato).
- Ogni esportazione è registrata nel log di audit.
- Lo statino di un consulente si apre anche dall'admin: `/admin-files/statement/AAAA-MM/ID`.

## File SEPA, avvisi email e dati del venditore
- **File SEPA**: in Impostazioni → Bonifici SEPA inserisci intestatario e IBAN del conto aziendale. Poi Pagamenti → «Esporta file SEPA (XML)» e carica il file nell'home banking. I pagamenti senza IBAN valido sono saltati e ti vengono elencati. Dopo il bonifico segna comunque ogni pagamento «pagato». La prima volta fai controllare il file alla banca.
- **Avvisi email**: Impostazioni → Email. «Statino pronto» e «bonifico effettuato» vanno solo ai consulenti, mai ai clienti. Finché «Invio email attivo» è spento non parte nessuna email.
- **Venditore**: Impostazioni → Venditore. Nel campo «Denominazione» scrivi la ragione sociale se sei una società, altrimenti nome e cognome. La dashboard avvisa finché c'è il testo provvisorio.

## Abbonamenti
Menu *Abbonamenti*: elenco con stato, impegno, spedizione gratuita, cicli fatti, prossimo addebito e ultimo errore. Azioni: *Pausa*, *Riprendi*, *Annulla* (con motivo). Impostazioni in *Abbonamenti e spedizione*: spedizione gratuita sì/no, mesi di impegno minimo (12), giorni tra i tentativi, tentativi prima della pausa. Gli abbonamenti partono ogni notte alle 04:00 (`lamess:abbonamenti`).

## Ordini per conto di altri
Un consulente attivo può aggiungere al carrello prodotti per i suoi clienti e per i consulenti che ha sponsorizzato direttamente. Un solo ordine, una sola spedizione; i punti vanno a chi riceve (PVF per il consulente del cliente, PVC per il consulente destinatario). Interruttore `lamess.order_for_others`.

## Impostazioni di sistema (menu Impostazioni)
Gruppi: **Email** (interruttore invio), **Rete** (giorni per ri-iscriversi, cookie referral), **Ordini e fatture** (documento per i privati), **Abbonamenti e spedizione** (spedizione gratuita, impegno minimo, tentativi), **Chiusura mensile** (spenta / solo calcolo / calcola e chiude), **Venditore** (ragione sociale, partita IVA, indirizzo, email: compaiono su fatture e statini). Ogni modifica è nel log di audit.

## Il nuovo menu (05/10/2026)
Le voci sono raccolte in 4 menu a tendina nella barra in alto: **Rete** (consulenti, clienti, albero, contratti, documenti, canoni), **Compensi** (piano, periodi, movimenti PV, liquidazioni, pagamenti), **Vendite** (ordini, abbonamenti, prodotti, regole, spedizioni, fatture, note di credito), **Sistema** (utenti, impostazioni, log, verifica log, riconciliazioni).

## Pagamento con carta (Stripe) (aggiunta 05/10/2026)
- Con `LAMESS_PAYMENT_DRIVER=stripe` il cliente, dopo il checkout, viene mandato alla **pagina sicura di Stripe**. L'ordine resta «in attesa di pagamento» finché Stripe non avvisa il portale (webhook): a quel punto passa a «pagato», nascono i punti e si emette la fattura. Se il cliente chiude la pagina può riprovare dalla pagina dell'ordine con **Paga con carta**.
- Il portale **non conferma** un ordine se l'importo o la valuta comunicati da Stripe non coincidono con l'ordine (resta in attesa e il caso va controllato a mano, con una riga nel registro del server). Un avviso ripetuto da Stripe non crea pagamenti doppi.
- Un ordine «in attesa» con carta da molto tempo: verifica in Stripe (Pagamenti) se è stato incassato; se sì, **Ordini → Conferma pagamento** come per il bonifico. Se non risulta incassato, annulla.
- Abbonamenti: con Stripe i rinnovi automatici non sono ancora attivi (servirebbero le carte salvate). Un abbonamento il cui addebito non riesce va in pausa e il cliente lo riprende con un ordine pagato dalla pagina.

## Fatture elettroniche (FatturaPA) (aggiunta 05/10/2026)
- Ogni fattura (**TD01**) e nota di credito (**TD04**) viene trasformata in XML FatturaPA 1.2.2, validato contro lo schema ufficiale. Le **ricevute** (privati, se `private_document = receipt`) non sono fatture elettroniche e non vengono trasmesse.
- **Vendite → Fatture / Note di credito**: azione **XML** per scaricare il file, **PDF** per la copia di cortesia, **Reinvia** per ritentare la consegna. Colonna di invio: *pendente / inviata / errore* (con il motivo).
- Driver (`LAMESS_INVOICE_SINK`): `file` scrive l'XML in `storage/app/private/sdi-out`; `mail` fa lo stesso e lo spedisce in allegato a `LAMESS_SDI_MAIL_TO` (serve anche «Invio email attivo»). La trasmissione allo SdI vera e propria la fa chi riceve il file (gestionale, commercialista, PEC): il portale non parla direttamente con lo SdI.
- Errori tipici in colonna «errore»: dati del venditore incompleti (CAP, comune, provincia, partita IVA), cliente senza codice fiscale né partita IVA, indirizzo del cliente incompleto. Si correggono i dati e si preme **Reinvia**; ogni ora il portale riprova da solo (`lamess:fatture`).
- Il codice destinatario SdI del cliente è `0000000` se è un privato o se manca; per le aziende vale il codice SDI indicato nel checkout (o la PEC).

## Verifica d'identità online (aggiunta 05/10/2026)
Con `LAMESS_KYC_DRIVER=stripe_identity` il consulente vede nell'onboarding il pulsante **Verifica ora** (documento + selfie dal telefono). Il documento d'identità risulta approvato quando Stripe conferma; la **foto del tesserino** va comunque caricata e verificata a mano. Se la verifica fallisce (`requires_input`) il consulente può riprovarla o caricare i documenti. In caso di cessione o successione le verifiche precedenti diventano «sostituite». L'esito positivo è nel log di audit.

## Approvazione a quattro occhi del piano (aggiunta 05/10/2026)
Per default (`Impostazioni → Piano compensi`, `LAMESS_PLAN_FOUR_EYES`) una nuova versione del piano non si pubblica da sola:
1. Chi ha il permesso `plan.manage` prepara la bozza e preme **Chiedi approvazione** (data di efficacia e motivo; con i quattro occhi spenti il pulsante si chiama **Attiva**): la bozza passa «in approvazione» e non si può più modificare.
2. Un **secondo** amministratore con `plan.approve` (profilo «Piano compensi») la esamina con **Simula impatto** e sceglie **Approva e pubblica** oppure **Rifiuta** (motivo obbligatorio: la versione torna bozza).
3. Chi ha chiesto la pubblicazione **non può approvarla**, nemmeno il super_admin. Può **Ritirare la richiesta** finché non c'è una decisione.
Tutto è nel log di audit. Spegnere l'impostazione permette la pubblicazione diretta.

## Assistenza: ticket e reclami (aggiunta 05/10/2026)
Menu **Assistenza → Ticket e reclami** (permessi `support.view` / `support.manage`).
- Categorie: domanda generale, problema tecnico, **reclamo su estratto conto**, **segnalazione su ricevuta**, segnalazione di comportamento, altro. I consulenti scelgono liberamente solo le prime e le ultime; i reclami nascono dalla pagina dell'estratto o della ricevuta.
- Azioni: **Prendi in carico**, **Rispondi** (anche come *nota interna*, invisibile al richiedente), **Chiudi**, **Riapri**.
- **Reclami sugli estratti conto** (Reg. 5.2): ogni estratto si pubblica alla chiusura del mese; il consulente ha **10 giorni** (`dispute_days`) per un reclamo circostanziato. Senza reclamo l'estratto si considera **accettato** (`lamess:estratti`, ogni notte). Per un reclamo usa **Decidi il reclamo** (accolto / respinto, con nota): l'eventuale correzione economica si fa con una **rettifica** nel mese aperto, non toccando il mese chiuso.
- Dalla scheda di un ticket di tipo «segnalazione di comportamento» **Apri un caso di conformità** (permesso `compliance.manage`).

## Wallet, prelievi e ricevute (aggiunta 05/10/2026)
Attivi **solo se il piano in vigore ha il pagamento «su richiesta»** (`payout_mode = on_request`); con il pagamento mensile automatico (default del piano di prelancio) restano inattivi e i consulenti non vedono il wallet.
- **Wallet** (Compensi → Movimenti wallet): registro dei movimenti; il saldo è sempre la loro somma. Alla chiusura del mese le liquidazioni diventano accrediti; gli storni successivi restano a debito e si compensano con gli accrediti dopo. **Rettifica manuale** (motivo obbligatorio, nel log).
- **Prelievi su richiesta** (Compensi → Prelievi su richiesta): il consulente richiede un importo (soglia minima del piano, 15 €, e non oltre la quota disponibile). Ciclo: *richiesto → approvato → pagato* (oppure *respinto* con motivo, *annullato* dal consulente finché non è approvato). **Approva** (permesso `payouts.manage`) ricalcola trattenute e saldo e crea il pagamento «da pagare», che segue lo stesso percorso degli altri (CSV, file SEPA, *Segna pagato*). Il bonifico va eseguito entro **5 giorni lavorativi** dalla richiesta. Un mese non si riapre se dal wallet sono già stati pagati prelievi.
- **Trattenute** (Impostazioni → Ricevute e trattenute, **valori provvisori da validare col commercialista**): ritenuta d'acconto 23% sulla quota imponibile (78%), contributo INPS Gestione Separata solo sulla parte oltre la soglia annua (5.000 €), commissione bancaria sui prelievi (2,50 €). Tutto **spento di default**: con le trattenute spente il bonifico è pari al lordo. I consulenti con **partita IVA** (azione *Regime fiscale* sulla scheda del consulente) non subiscono trattenute e fatturano da sé.
- **Ricevute provvigionali** (Compensi → Ricevute provvigionali): emesse per conto del consulente a ogni pagamento eseguito, solo se «Ricevute attive». Il consulente ha 10 giorni per segnalare anomalie; il reclamo si decide da Assistenza.

## Novità, materiali ed eventi (aggiunta 05/10/2026)
Menu **Assistenza** (permesso `content.manage`, che hanno finanza e assistenza).
- **Novità**: articoli con data di pubblicazione e pubblico (tutti, solo consulenti attivi, solo clienti/FAN).
- **Materiali scaricabili**: carichi un file e scegli a chi è visibile (tutti, solo consulenti attivi, solo clienti) ed eventualmente la **qualifica minima** per scaricarlo. Nei PDF, JPG e PNG viene impressa una **filigrana col codice del consulente** a ogni scaricamento. Se la filigrana non riesce (es. PDF compresso) il file esce comunque e il **Registro download** lo segnala (`LAMESS_WATERMARK_FAIL_OPEN=false` per bloccare invece il download).
- **Eventi e corsi**: data, posti, iscrizioni aperte/chiuse. Se i posti finiscono i consulenti vanno in lista d'attesa; quando qualcuno annulla, il primo in lista sale (o con l'azione **Riempi i posti liberi** sull'evento).
- **QR del referral**: ogni consulente trova il proprio link e il QR in *Area → Referral*.

## Conformità, sanzioni e materiali approvati (aggiunta 05/10/2026)
Menu **Assistenza** (permessi `compliance.view` / `compliance.manage`: lo ha l'amministratore di rete; assistenza e direzione in sola lettura).
- **Casi di conformità**: aperti a mano o da un ticket di segnalazione. Azioni: **Apri un caso**, istruttoria, **Applica una sanzione** o **Archivia**. Le sanzioni si revocano con **Revoca** (Assistenza → Sanzioni).
- **Sanzioni**: *richiamo* (solo registrato), *blocco compensi* (blocca i bonus; si ripristina allo scadere o alla revoca), *sospensione* (il consulente passa a «sospeso»), *cessazione* (chiude il codice come per la cessazione normale). Le sanzioni a tempo scadono da sole (`lamess:sanzioni`, ogni notte) e ripristinano lo stato precedente, salvo altre sanzioni ancora attive. Ogni sanzione e revoca è nel log. Un codice sanzionato o con casi aperti **non può essere ceduto**.
- **Materiali da approvare**: i consulenti propongono i propri materiali promozionali (*Area → Conformità*); l'amministratore **Approva** (assegna il codice `MA-AAAA-nnnn`, da quel momento usabile) o **Non approvare** con motivo; il consulente può ritirarli. I materiali ufficiali del Consorzio si pubblicano già approvati con **Pubblica materiale ufficiale**.

## Cessione del codice e successione (aggiunta 05/10/2026)
**Cessione** (Reg. 9.1) — menu *Rete → Cessioni del codice*, permessi `fees.manage` (contributo) e `network.manage` (decisione):
1. Il consulente la richiede da *Area → Cessione* indicando il cessionario (un utente già registrato che possa diventare consulente).
2. Il **contributo di cessione** (default 50 €, `LAMESS_TRANSFER_FEE`) si segna pagato con **Fee pagata** (chi ha `fees.manage`).
3. **Approva / Respingi** (`network.manage`). L'approvazione è bloccata se il cedente ha un saldo compensi non pagato, a meno che l'amministratore decida espressamente che segua il codice. Un codice sanzionato o con casi aperti non si cede.
4. All'approvazione cambia **solo l'intestatario**: codice, sponsor, qualifica e referral restano. Si azzerano IBAN e verifica KYC (i vecchi documenti diventano «sostituiti»), la lettera precedente risulta «ceduta» e il consulente passa a **sospeso** finché il cessionario non firma la nuova lettera e il Consorzio non la accetta (riattivazione automatica).

**Successione** (Reg. 9.2) — menu *Rete → Successioni (decessi)* (stessi permessi della rete e dei pagamenti):
1. **Segnala un decesso**: la lettera risulta «cessata», il consulente sospeso e i bonus bloccati.
2. Con **Carica documento** ed **Esamina documento** si raccolgono e verificano i documenti (atti di successione, identità degli eredi).
3. **Eredi e quote** (la somma deve fare 100%), poi **Verifica la pratica**.
4. **Liquida i compensi agli eredi**: i compensi maturati e non pagati si dividono tra gli eredi per quota (l'eventuale resto di centesimi va alla quota maggiore). Le liquidazioni risultano «ereditate» e il wallet registra il debito «liquidazione eredi».
5. **Segna un pagamento**: bonifico manuale per ciascun erede (permesso `payouts.manage`), registrato fuori dal flusso mensile.
6. Infine **Subentro di un erede** (stessa procedura della cessione, con nuova lettera) oppure **Fai cessare il codice**. Se non si decide entro **180 giorni** (`LAMESS_INHERITANCE_LIMBO_DAYS`) il codice cessa da solo (`lamess:successioni`).

## Ruoli e permessi aggiornati (05/10/2026)
Nuovi permessi: `plan.approve`, `support.view/manage`, `content.manage`, `compliance.view/manage`. Assegnazione dei profili: *Rete e anagrafiche* → conformità (vedere e gestire); *Finanza e ordini* → assistenza e contenuti; *Piano compensi* → può anche approvare; *Assistenza* → assistenza, contenuti, conformità in lettura; *Direzione* → sola lettura. Dopo ogni aggiornamento che aggiunge permessi: `php artisan lamess:ruoli` (la prima installazione li crea da sola).

## Operazioni pianificate (cron `schedule:run`) (05/10/2026)
Orari in Europe/Rome: 00:10 piano programmato che diventa attivo · 00:20 sanzioni a tempo · 00:30 successioni scadute · 01:00 chiusura mensile · 02:15 backup · 03:30 canoni · 04:00 abbonamenti · 05:00 pagamenti · 06:00 estratti accettati per decorrenza dei termini · ogni ora reinvio fatture · ogni minuto segnale di vita del cron.
