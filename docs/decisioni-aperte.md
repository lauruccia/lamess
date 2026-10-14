# Decisioni aperte e default adottati

Ogni punto ambiguo della documentazione è una **politica configurabile** (registro `PolicySpecs`).
Il motore parte con il default sotto indicato; cambiarlo non richiede modifiche al codice
(campo `policies` del file di piano).

> **Piano Incaricati (dall'8 ottobre 2026)**: non ha lo stato ATTIVO e il suo file di piano non usa le politiche D01–D11 (campo `policies` vuoto). Le tabelle D01–D11 qui sotto riguardano il **vecchio piano a PR**, che si può nascondere da Compensi → Visibilità piano precedente. Il file del Piano Incaricati è ancora etichettato «BOZZA da validare» e va confermato prima di pubblicarlo.

Stato:
- `certain` — documentato senza ambiguità.
- `client_decided` — deciso dal cliente (call del 15/09/2026).
- `provisional` — default provvisorio: simulabile, **ma i periodi che lo usano non vanno approvati per pagamenti reali** finché il cliente non conferma.

| Decisione | Politica | Default | Stato | Opzioni | Fonte |
|---|---|---|---|---|---|
| D01 | `activity_points_source` — Punti validi per lo stato ATTIVO | `own_customers_pvf` | client_decided | `own_customers_pvf_plus_own_pvc` = PVF dei propri clienti + PVC dei propri acquisti; `own_customers_pvf` = Solo PVF dei propri clienti | PMK 070726 p.24; Laura 02/10/2026 (sostituisce call 15/09, K1=C) |
| D01 | `activity_first_time` — Prima soglia raggiunta | `current_and_next` | client_decided | `current_and_next` = Vale per il mese corrente e il successivo; `current_only` = Solo mese corrente | Call 15/09/2026 (C1); PMK p.24 |
| D01 | `activity_month_rule` — Mesi successivi: quale mese conta | `current` | provisional | `current` = Mese corrente; `previous` = Mese precedente (PMK p.14); `current_or_previous` = Corrente oppure precedente | PMK p.14 vs p.24; call 15/09/2026: da chiarire |
| D01 | `activity_first_month_free` — Primo mese da consulente attivo d'ufficio (mese solare) | sì | certain | sì/no | PMK p.14 (N.B.) |
| D03 | `commission_base` — Base delle provvigioni | `pv` | provisional | `pv` = PV; `cv` = CV (promo Double CV di M1) | PMK p.25–28 (esempi su PV); M1 §3 (CV) |
| D02 | `discount_pv_policy` — PV su righe scontate | `catalog` | provisional | `proportional` = PV ridotti in proporzione allo sconto; `catalog` = PV di catalogo invariati | PMK 070726 p.14, Webinar s.23–25 (OPEN-34) |
| D03 | `pr_source` — Punti che alimentano i PR | `pv` | provisional | `pv` = PV; `cv` = CV | PMK p.13 (1 PR per ogni PV); M1 §3 (CV carriera) (OPEN-19) |
| D04 | `personal_purchase_direct_beneficiary` — Direct Bonus sugli acquisti personali del consulente | `upline_differential` | client_decided | `upline_differential` = Nessun Direct a chi compra, differenza (Team) allo sponsor e sopra; `sponsor`; `self`; `none` | Laura 02/10/2026 (OPEN-35); sostituisce call 15/09 (K2=B) |
| D05 | `team_ineligible_upline` — Team Bonus con upline non idoneo | `compress` | provisional | `compress` = Compressione: il differenziale risale all'upline idoneo; `absorb` = La quota dell'upline non idoneo si perde | PMK p.24 (compressione dinamica) (OPEN-14) |
| D05 | `matching_level_counting` — Conteggio livelli Matching | `compressed_active` | provisional | `compressed_active` = Solo consulenti attivi contano come livello; `genealogical` = Livelli genealogici fissi | PMK p.24, p.29–31 (OPEN-15) |
| D05 | `structure_depth` — Qualificati richiesti nelle linee | `any_depth` | provisional | `any_depth` = A qualsiasi profondità nella linea; `direct_only` = Solo diretti | PMK p.20 («in livelli sottostanti») (OPEN-09) |
| D03 | `personal_pr_in_fifty_rule` — PR personali nella regola del 50% | `uncapped` | provisional | `uncapped` = Contati per intero; `as_line` = Limitati come una linea | PMK p.19–20 (OPEN-10) |
| D06 | `onboarding_incomplete_indirect` — Commissioni indirette con onboarding incompleto | `not_accrued` | provisional | `not_accrued` = Non maturano (PMK p.24); `accrued_held` = Maturano e restano trattenute (M1) | PMK p.24 vs M1 §5 (OPEN-21) |
| D06 | `kyc_held_recovery` — Importi trattenuti per KYC alla verifica | `release_held` | provisional | `release_held` = Si sbloccano; `forfeit` = Si perdono | M1 §5; nessuna regola |
| D07 | `rank_bonus_skipped_ranks` — Qualifiche saltate nello stesso mese | `pay_all` | provisional | `pay_all` = Premio per ogni qualifica; `highest_only` = Solo la più alta | PMK p.32 (OPEN-05) |
| D07 | `rank_bonus_inactive` — Rank Bonus maturato da inattivo | `hold_n_months` | provisional | `hold_n_months` = Trattenuto fino a N mesi, poi perso; `hold_until_active` = Trattenuto finché torna attivo; `forfeit` = Perso | PMK p.32 («per poter riscuotere devi essere attivo») (OPEN-06) |
| D07 | `rank_bonus_installment_start` — Decorrenza prima rata | `achievement_period` | provisional | `achievement_period` = Mese del raggiungimento; `next_period` = Mese successivo | PMK p.32 |
| D07 | `rank_bonus_installment_inactive` — Rata in un mese di inattività | `hold` | provisional | `hold` = Trattenuta; `pay_anyway` = Pagata comunque | PMK p.32 |
| D08 | `fsb_rank_basis` — Qualifica richiesta dal FSB | `achieved` | provisional | `achieved` = Raggiunta; `effective` = Efficace | Guida FSB p.3–7; PMK 070726 p.35 |
| D08 | `fsb_missed_step` — Step mancato | `independent` | provisional | `independent` = Gli step successivi restano validi; `blocks_following` = Blocca i successivi | Guida FSB p.4–7 (OPEN-07) |
| D09 | `fsb_step10_deadline` — Step 10: termine per i tre consulenti | `own_window` | provisional | `own_window` = Step 9 nella loro finestra (210 giorni), nessuna scadenza per lo sponsor | PMK 070726 p.35 (OPEN-08) |
| D11 | `refund_after_close` — Reso dopo la chiusura del periodo | `clawback_next_open` | provisional | `clawback_next_open` = Storno nel primo periodo aperto; `no_clawback` = Nessun recupero | M1 §1 (refund) (OPEN-26) |

## Da chiarire con il cliente (prima del go-live)

Tutte le righe `provisional`, in particolare: regola del mese per l'attività (PMK p.14 vs p.24),
base provvigionale PV/CV, PV su righe scontate, compressione Team, conteggio livelli Matching,
profondità della struttura, onboarding incompleto, regole Rank Bonus per inattivi e qualifiche saltate,
resi dopo la chiusura. FSB attivo dal PMK 070726: D08/D09 restano provvisorie (OPEN-04: i 100 PV del diretto sono personali e cumulati dalla sua registrazione).

## Decisioni dell'onboarding (F1) adottate come default

| Id | Tema | Default adottato | Dove si cambia |
|---|---|---|---|
| OPEN-20 | Cliente senza referral | assegnato al consulente predefinito (se configurato), altrimenti nessuno | `lamess.default_consultant_code` |
| OPEN-21 | Onboarding incompleto e indirette | non maturano (D06) | policy `onboarding_incomplete_indirect` |
| OPEN-22 | Rifiuto della domanda | torna FAN; movimenti dei periodi non chiusi allo sponsor come PVF | codice `IvdService::reject` |
| OPEN-23 | Canone annuale | primo anno gratuito, canone dal secondo anno: **19 € IVA compresa**; interruttore «Canone annuale attivo» (spento: nessun canone, rinnovi gratuiti) — aggiornato il 12/10/2026 | `annual_fee_first_paid_year`, `annual_fee_amount`, `annual_fee_enabled` |
| OPEN-24 | Canone non pagato | scadenza senza giorni di grazia; contratto scaduto, consulente cessato, torna FAN | `annual_fee_grace_days` |

Testo legale della Lettera IVD: segnaposto da sostituire con quello del Consorzio.

## Decisioni del negozio (F2) adottate come default

| Id | Tema | Default adottato | Dove si cambia |
|---|---|---|---|
| OPEN-18 | Spedizione e PV | non genera PV | codice `OrderService::place` |
| OPEN-25 | Periodo di competenza del PV | mese di conferma del pagamento | `OrderService::markPaid` |
| OPEN-31 (nuova) | Privati: fattura o ricevuta | fattura per tutti | `lamess.private_document` |
| OPEN-32 (nuova) | Periodo non esistente al primo pagamento del mese | si apre da solo con il piano attivo | `lamess.auto_open_period` |
| OPEN-33 | Reso della spedizione | **CHIUSA il 09/10/2026**: l'admin può scegliere «Rimborsa anche le spese di spedizione» (una sola volta per ordine, nota di credito con riga spedizione); di default restano escluse | `ReturnService`, scheda del reso |
| OPEN-34 | Prezzi del listino Odoo | **CHIUSA**: prezzi **IVA inclusa** (verificato: KIT GOLD 2.440 € = 2.000 € + IVA 22%); dal 13/10/2026 prezzi e riepiloghi sono IVA inclusa anche nel portale (si mostra solo l'aliquota). PV esplicito per prodotto (solo storico: il Piano Incaricati calcola sugli euro) | `lamess:importa-prodotti --netti` se un file fosse al netto |
| OPEN-40 | Disdetta abbonamento | **CHIUSA il 13-14/10/2026**: gli abbonamenti partono con impegno di 12 mesi **non vincolante**, si possono interrompere quando si vuole (nessuna penale; prima dei 12 mesi è registrata come «disdetta anticipata»); la spedizione è sempre gratuita e l'impegno minimo non è più configurabile | `SubscriptionService::cancel` |
| OPEN-41 (nuova) | Chi può ricevere un ordine per conto di altri | clienti assegnati al consulente e consulenti attivi da lui sponsorizzati direttamente | `OrderService::recipientsFor` |
| OPEN-42 (nuova) | Mesi storici già pagati fuori dal portale | si segnano «storico già pagato» a mano, mese per mese | scheda del periodo → «Segna come storico già pagato» |
| OPEN-43 (nuova) | Chiusura mensile automatica | «solo calcolo ufficiale»: la chiusura la conferma l'admin | Impostazioni → Chiusura mensile |
| OPEN-44 (nuova) | Pagamento delle provvigioni | preparato in automatico dal 15 del mese successivo; il bonifico in banca resta manuale | piano: `payout_mode`, `payout_day` |
| OPEN-45 (nuova) | Avvisi email | agli incaricati (statino pronto, bonifico effettuato, richiesta e promemoria dei documenti KYC, invio documenti) e al cliente per l'ordine pagato e spedito; tutto fermo finché l'interruttore generale è spento; spenti dall'interruttore generale | Impostazioni → Email |
| OPEN-46 (nuova) | Bonifici ai consulenti | file SEPA pain.001.001.03 da caricare in banca; da far validare dalla banca al primo uso | Pagamenti → Esporta file SEPA |
| OPEN-47 (nuova) | Mesi chiusi con la regola precedente | gennaio–settembre sono stati calcolati con la vecchia regola K2: ricalcolarli oggi darebbe totali diversi; da decidere se tenerli come storico o ricalcolare | Periodi |
| OPEN-48 (nuova) | Primo mese pagato dal portale | per evitare pagamenti arretrati (febbraio–settembre) quando arriveranno gli IBAN: scegliere il primo mese e marcare i precedenti «storico già pagato» | Pagamenti |
| OPEN-49 (nuova) | Firma elettronica reale | il sistema di prova non basta per aprire ai clienti: scegliere il fornitore | Impostazioni |

## Decisioni dei moduli 1-7 (05/10/2026): default adottati e punti per il cliente

| Id | Tema | Default adottato | Dove si cambia / chi decide |
|---|---|---|---|
| OPEN-50 (nuova) | Aggiornamenti dopo la messa online senza terminale | **parzialmente risolto (14/10/2026)**: il codice si aggiorna da cPanel → Git Version Control (*Update from Remote* + *Deploy HEAD Commit*, file `.cpanel.yml`) e la cache si svuota a mano da File Manager. **Resta aperto** per le migrazioni: l'installazione guidata si chiude dopo l'uso, il deploy non lancia `migrate` e non c'è una pagina di manutenzione; oggi si applica l'SQL in phpMyAdmin e si registra la riga in `migrations` (vedi «Messa online», punto 4) | da decidere se aggiungere una pagina riservata al super_admin che applichi le migrazioni e sincronizzi i permessi |
| OPEN-51 (nuova) | Fatture: come arrivano allo SdI | il portale genera l'XML FatturaPA e lo scrive in cartella (`file`) o lo spedisce a un indirizzo (`mail`); nessun collegamento diretto allo SdI né a GestionaleK | `LAMESS_INVOICE_SINK`; cliente/commercialista; per GestionaleK servono le loro specifiche |
| OPEN-52 (nuova) | Privati: fattura o ricevuta | fattura per tutti (ricevuta = documento di cortesia, non trasmesso) | `lamess.private_document`; commercialista |
| OPEN-53 (nuova) | Regime fiscale del venditore | RF01 (ordinario) | Impostazioni → Venditore; commercialista |
| OPEN-54 (nuova) | Parametri fiscali sui compensi | ritenuta 23% sulla quota imponibile 78%; INPS Gestione Separata 33,72% oltre 5.000 €, 33,33% a carico dell'incaricato; commissione prelievo 2,50 €; **tutto spento** (`LAMESS_RECEIPTS_ENABLED`, `LAMESS_WITHHOLDING_ENABLED`, `LAMESS_INPS_ENABLED` = false) finché non risponde il commercialista | Impostazioni → Ricevute e trattenute; **da validare col commercialista** prima di accendere |
| OPEN-55 (nuova) | Wallet e prelievi | attivi solo con pagamento «su richiesta» nel piano; soglia 15 €, pagamento entro 5 giorni lavorativi | piano compensi (`payout_mode`) |
| OPEN-56 (nuova) | Reclami su estratti e ricevute | 10 giorni, poi accettazione tacita; la correzione è una rettifica nel mese aperto | `LAMESS_DISPUTE_DAYS` |
| OPEN-57 (nuova) | Approvazione del piano | quattro occhi attivi: chi chiede non può approvare, nemmeno il super_admin | `LAMESS_PLAN_FOUR_EYES` |
| OPEN-58 (nuova) | Cessione del codice | contributo 50 €; resta codice, sponsor e qualifica; si azzerano IBAN e KYC; il codice è sospeso finché la nuova lettera non è accettata; bloccata con saldo non pagato (salvo decisione esplicita), sanzioni o casi aperti | `LAMESS_TRANSFER_FEE`; Consorzio |
| OPEN-59 (nuova) | Successione agli eredi | quote da verbale (somma 100%), resto dei centesimi alla quota maggiore, bonifici manuali fuori dal flusso mensile, 180 giorni senza decisione e il codice cessa | `LAMESS_INHERITANCE_LIMBO_DAYS`; Consorzio |
| OPEN-60 (nuova) | Sanzioni | richiamo, blocco compensi, sospensione, cessazione; a tempo o senza scadenza; scadono da sole | Assistenza → Sanzioni |
| OPEN-61 (nuova) | Materiali scaricabili | filigrana col codice del consulente su PDF/JPG/PNG; se non riesce il file esce lo stesso e il registro lo segnala | `LAMESS_WATERMARK_FAIL_OPEN` |
| OPEN-62 (nuova) | Carta con Stripe Checkout | il cliente paga sulla pagina di Stripe, conferma via webhook firmato con controllo di importo e valuta. **Rinnovi automatici degli abbonamenti: realizzati** (dopo il primo pagamento la carta è salvata e i cicli successivi si addebitano fuori sessione; il cliente può cambiare carta da una pagina di Stripe); se la banca chiede una conferma il ciclo non è pagato. **Da provare con carte di prova prima di fidarsi** | `LAMESS_PAYMENT_DRIVER`; cliente: account Stripe e chiavi |
| OPEN-63 (nuova) | Verifica d'identità online | facoltativa (Stripe Identity): vale come documento d'identità approvato, la foto del tesserino resta separata | `LAMESS_KYC_DRIVER` |
| OPEN-64 (nuova) | Nome e cognome nelle fatture dei privati | gli ordini nuovi conservano nome e cognome separati; per quelli vecchi il nome intero si divide al primo spazio | `OrderService`, `FatturaPaBuilder` |

## Decisioni chiuse dall'8 ottobre 2026 (dal registro aggiornamenti e da Laura)

| Tema | Decisione | Data |
|---|---|---|
| Piano Incaricati: punti e attività | niente PV: compensi e clienti si calcolano sugli **euro** IVA inclusa; nessuno stato ATTIVO e nessuna spesa mensile minima | 08/10 |
| Chi è cliente | lo decide l'admin: `any_order` oppure `min_amount`; predefinito un ordine da 150 € IVA inclusa | 08/10 |
| Mantenimento delle qualifiche | finestra di `rank_grace_days` (90) dall'attivazione del piano, data fissa `rank_grace_until`, e «Mantieni qualifica» per singolo incaricato (`rank_hold_until`) | 08/10, 12/10, 13/10 |
| Spedizione | tariffe per paese e fascia di peso, costo proprio per prodotto, tariffa Italia di 9,00 € IVA inclusa creata dall'installazione | 09/10 |
| Reso | rimborso facoltativo della spedizione; reso richiedibile dal cliente entro 14 giorni dalla consegna (OPEN-33 chiusa) | 09/10 |
| Metodi di pagamento | carta (Stripe), bonifico, KMoney; il vantaggio KMoney mostrato al checkout si regola in Impostazioni di sistema → Metodi di pagamento | 09-13/10 |
| Abbonamenti | 12 mesi non vincolanti, spedizione sempre gratuita, disdetta libera (OPEN-40 chiusa) | 13-14/10 |
| Prezzi | l'admin li inserisce **IVA esclusa** con l'aliquota; cliente e admin li vedono sempre IVA inclusa con la sola aliquota (OPEN-34 chiusa) | 13-14/10 |
| Indiretti | livello 5 all'8%, confermato | 14/10 |
| Dati dell'azienda | KADEO GROUP – CONSORZIO KADEO GROUP, Via G. Ferraris 63, Arezzo, P.IVA 02530190517, PEC kadeogroup@pec.it, nel piè di pagina di ogni pagina del sito | 14/10 |
| Albero dell'incaricato | colori delle pietre anche nell'area riservata | 14/10 |
| Canone annuale | 19 € IVA compresa, con interruttore per spegnerlo | 12/10 |
| Terminologia | «Incaricato/Incaricati» al posto di «Consulente» nel prodotto | 13/10 |
| Vecchio piano a PR | si nasconde con un interruttore (solo super_admin), senza cancellare né ricalcolare | 13/10 |
| Hosting e rilascio | cPanel, database MySQL `lamess_portale`, nessun terminale sul sito live; dominio `lamèss.it` (anche `www`); GitHub privato `lauruccia/lamess` collegato a cPanel → Git Version Control (OPEN-50 in parte) | 14/10 |
| Apertura al pubblico | il portale resta **chiuso** («Lamèss apre presto») finché Laura decide di aprirlo | 14/10 |
| Ricevute e ritenuta d'acconto | restano **spente** finché non risponde il commercialista (la questione sotto resta aperta) | 12/10 |

## Questioni aperte oggi (14 ottobre 2026)

| Id | Questione | Stato / cosa serve | Chi |
|---|---|---|---|
| A-01 | **Commercialista**: ricevute provvigionali, ritenuta d'acconto (23% sul 78%), INPS Gestione Separata, commissione prelievo 2,50 €, privati in fattura o in ricevuta (OPEN-52, OPEN-54) | tutto spento; serve la risposta per accendere | commercialista, Laura |
| A-02 | **Log di audit e vecchio piano**: la pagina di visibilità nasconde il piano a PR in schermate ed esportazioni ma **non tocca il log di audit**, che continua a nominarlo. Nasconderlo anche lì? Il log è a catena di hash con trigger anti-modifica: si potrebbe solo filtrare la vista, non riscrivere le righe | da decidere | Laura |
| A-03 | **Test noto rosso** `SitePagesTest::test_full_consultant_journey_through_the_site`: il tesserino risulta scaricabile subito dopo la firma (200) mentre il test si aspetta 404. Va deciso quale comportamento è giusto e allineare codice o test. Un secondo rosso del 12/10, `InstallerTest::test_install_creates_superadmin_once_and_locks`, dipendeva dai requisiti server dell'ambiente cloud di prova | da decidere/correggere | Laura, sviluppo |
| A-04 | **Token KMoney mostrato in uno screenshot**: va ruotato in KMoney e il nuovo valore messo nel `.env` (`KMONEY_API_TOKEN`); non condividere il `.env` né schermate con token | da fare | Laura |
| A-05 | **Indirizzi di ritorno OAuth di KMoney** (`/area/kmoney/ritorno`): registrarli per `xn--lamss-6ra.it` e `www.xn--lamss-6ra.it`; poi provare un collegamento e un pagamento reali. Il «Controllo messa online» non verifica KMoney | da fare/provare | Laura |
| A-06 | **Migrazioni senza terminale** (OPEN-50): decidere se costruire una pagina riservata al super_admin. Nel frattempo SQL in phpMyAdmin; verificare che quelle del 12-13/10 (`2026_11_03_*`, `2026_11_04_100000_consultants_rank_hold_until`) risultino nella tabella `migrations` del sito | da verificare sul sito | Laura |
| A-07 | **Cache dopo il deploy**: il deploy lancia `optimize:clear` solo se `vendor` e `.env` ci sono; verificare se basta, altrimenti resta la cancellazione manuale di `bootstrap/cache` e `storage/framework/views` | da verificare | Laura |
| A-08 | **Soglia «cliente» e promozioni**: con `min_amount` a 150 € un ordine in promozione sotto la soglia (es. Box a 129,90 €) non rende cliente | da decidere | Laura |
| A-09 | **Validazione del Piano Incaricati**: il file del piano è «BOZZA da validare» (presentazioni dell'8/10); punti da confermare prima di pubblicarlo | da validare | Laura, cliente |
| A-10 | **Mesi già calcolati** (OPEN-47, OPEN-48): gennaio–settembre calcolati con la regola precedente e scelta del primo mese pagato dal portale | da decidere | Laura, cliente |
| A-11 | **Firma elettronica reale** (OPEN-49): esiste solo il driver di prova; il Controllo messa online dà BLOCCO finché è `fake` | da scegliere il fornitore | cliente |
| A-12 | **Fatture allo SdI / GestionaleK** (OPEN-51): nessun collegamento diretto; scelta fra `file` e `mail` | da decidere | commercialista, cliente |
| A-13 | **Stripe live**: chiavi `sk_live_…`, webhook, prova di pagamento e di rinnovo di un abbonamento con carta di prova (OPEN-62) | da fare | cliente, Laura |
| A-14 | **Prova su MySQL/MariaDB** delle migrazioni successive al 5 ottobre e della suite completa (finora provate solo su SQLite) | da fare | sviluppo |
