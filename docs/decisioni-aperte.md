# Decisioni aperte e default adottati

Ogni punto ambiguo della documentazione è una **politica configurabile** (registro `PolicySpecs`).
Il motore parte con il default sotto indicato; cambiarlo non richiede modifiche al codice
(campo `policies` del file di piano).

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
| OPEN-23 | Canone: primo anno | gratuito, canone dal secondo anno (19 €) | `annual_fee_first_paid_year`, `annual_fee_amount` |
| OPEN-24 | Canone non pagato | scadenza senza giorni di grazia; contratto scaduto, consulente cessato, torna FAN | `annual_fee_grace_days` |

Testo legale della Lettera IVD: segnaposto da sostituire con quello del Consorzio.

## Decisioni del negozio (F2) adottate come default

| Id | Tema | Default adottato | Dove si cambia |
|---|---|---|---|
| OPEN-18 | Spedizione e PV | non genera PV | codice `OrderService::place` |
| OPEN-25 | Periodo di competenza del PV | mese di conferma del pagamento | `OrderService::markPaid` |
| OPEN-31 (nuova) | Privati: fattura o ricevuta | fattura per tutti | `lamess.private_document` |
| OPEN-32 (nuova) | Periodo non esistente al primo pagamento del mese | si apre da solo con il piano attivo | `lamess.auto_open_period` |
| OPEN-33 (nuova) | Reso della spedizione | non rimborsata (solo righe prodotto) | codice `ReturnService` |
| OPEN-34 (nuova) | Prezzi del listino Odoo | **IVA inclusa** (verificato: KIT GOLD 2.440 € = 2.000 € + IVA 22%; PV 1.600 dalla colonna «PR numerico»). PV esplicito per prodotto | `lamess:importa-prodotti --netti` se un file fosse al netto |
| OPEN-40 | Disdetta abbonamento prima dei 12 mesi | consentita, registrata come «disdetta anticipata» (nessuna penale) | `SubscriptionService::cancel` |
| OPEN-41 (nuova) | Chi può ricevere un ordine per conto di altri | clienti assegnati al consulente e consulenti attivi da lui sponsorizzati direttamente | `OrderService::recipientsFor` |
| OPEN-42 (nuova) | Mesi storici già pagati fuori dal portale | si segnano «storico già pagato» a mano, mese per mese | scheda del periodo → «Segna come storico già pagato» |
| OPEN-43 (nuova) | Chiusura mensile automatica | «solo calcolo ufficiale»: la chiusura la conferma l'admin | Impostazioni → Chiusura mensile |
| OPEN-44 (nuova) | Pagamento delle provvigioni | preparato in automatico dal 15 del mese successivo; il bonifico in banca resta manuale | piano: `payout_mode`, `payout_day` |
| OPEN-45 (nuova) | Avvisi email | solo ai consulenti (statino pronto, bonifico effettuato), mai ai clienti; spenti dall'interruttore generale | Impostazioni → Email |
| OPEN-46 (nuova) | Bonifici ai consulenti | file SEPA pain.001.001.03 da caricare in banca; da far validare dalla banca al primo uso | Pagamenti → Esporta file SEPA |
| OPEN-47 (nuova) | Mesi chiusi con la regola precedente | gennaio–settembre sono stati calcolati con la vecchia regola K2: ricalcolarli oggi darebbe totali diversi; da decidere se tenerli come storico o ricalcolare | Periodi |
| OPEN-48 (nuova) | Primo mese pagato dal portale | per evitare pagamenti arretrati (febbraio–settembre) quando arriveranno gli IBAN: scegliere il primo mese e marcare i precedenti «storico già pagato» | Pagamenti |
| OPEN-49 (nuova) | Firma elettronica reale | il sistema di prova non basta per aprire ai clienti: scegliere il fornitore | Impostazioni |

## Decisioni dei moduli 1-7 (05/10/2026): default adottati e punti per il cliente

| Id | Tema | Default adottato | Dove si cambia / chi decide |
|---|---|---|---|
| OPEN-50 (nuova) | Aggiornamenti dopo la messa online senza terminale | **non risolto**: l'installazione guidata si chiude dopo l'uso e non c'è una pagina che lanci `migrate` e `lamess:ruoli`. Proposta: pagina riservata al super_admin | da decidere; serve una piccola pagina di manutenzione |
| OPEN-51 (nuova) | Fatture: come arrivano allo SdI | il portale genera l'XML FatturaPA e lo scrive in cartella (`file`) o lo spedisce a un indirizzo (`mail`); nessun collegamento diretto allo SdI né a GestionaleK | `LAMESS_INVOICE_SINK`; cliente/commercialista; per GestionaleK servono le loro specifiche |
| OPEN-52 (nuova) | Privati: fattura o ricevuta | fattura per tutti (ricevuta = documento di cortesia, non trasmesso) | `lamess.private_document`; commercialista |
| OPEN-53 (nuova) | Regime fiscale del venditore | RF01 (ordinario) | Impostazioni → Venditore; commercialista |
| OPEN-54 (nuova) | Parametri fiscali sui compensi | ritenuta 23% sulla quota imponibile 78%; INPS Gestione Separata 33,72% oltre 5.000 €, 33,33% a carico del consulente; commissione prelievo 2,50 €; **tutto spento** | Impostazioni → Ricevute e trattenute; **da validare col commercialista** prima di accendere |
| OPEN-55 (nuova) | Wallet e prelievi | attivi solo con pagamento «su richiesta» nel piano; soglia 15 €, pagamento entro 5 giorni lavorativi | piano compensi (`payout_mode`) |
| OPEN-56 (nuova) | Reclami su estratti e ricevute | 10 giorni, poi accettazione tacita; la correzione è una rettifica nel mese aperto | `LAMESS_DISPUTE_DAYS` |
| OPEN-57 (nuova) | Approvazione del piano | quattro occhi attivi: chi chiede non può approvare, nemmeno il super_admin | `LAMESS_PLAN_FOUR_EYES` |
| OPEN-58 (nuova) | Cessione del codice | contributo 50 €; resta codice, sponsor e qualifica; si azzerano IBAN e KYC; il codice è sospeso finché la nuova lettera non è accettata; bloccata con saldo non pagato (salvo decisione esplicita), sanzioni o casi aperti | `LAMESS_TRANSFER_FEE`; Consorzio |
| OPEN-59 (nuova) | Successione agli eredi | quote da verbale (somma 100%), resto dei centesimi alla quota maggiore, bonifici manuali fuori dal flusso mensile, 180 giorni senza decisione e il codice cessa | `LAMESS_INHERITANCE_LIMBO_DAYS`; Consorzio |
| OPEN-60 (nuova) | Sanzioni | richiamo, blocco compensi, sospensione, cessazione; a tempo o senza scadenza; scadono da sole | Assistenza → Sanzioni |
| OPEN-61 (nuova) | Materiali scaricabili | filigrana col codice del consulente su PDF/JPG/PNG; se non riesce il file esce lo stesso e il registro lo segnala | `LAMESS_WATERMARK_FAIL_OPEN` |
| OPEN-62 (nuova) | Carta con Stripe Checkout | il cliente paga sulla pagina di Stripe, conferma via webhook firmato con controllo di importo e valuta; i rinnovi automatici degli abbonamenti **non** sono attivi (servono carte salvate) | `LAMESS_PAYMENT_DRIVER`; cliente: account Stripe e chiavi |
| OPEN-63 (nuova) | Verifica d'identità online | facoltativa (Stripe Identity): vale come documento d'identità approvato, la foto del tesserino resta separata | `LAMESS_KYC_DRIVER` |
| OPEN-64 (nuova) | Nome e cognome nelle fatture dei privati | gli ordini nuovi conservano nome e cognome separati; per quelli vecchi il nome intero si divide al primo spazio | `OrderService`, `FatturaPaBuilder` |
