# Specifica del motore

Aggiornata al 14 ottobre 2026 sul codice di `App\Domain\Engine` e sul registro aggiornamenti. I valori numerici sono in «Parametri del piano e del portale» (`docs/parametri.md`).

Il portale ha **due motori di calcolo**, scelti dalla versione del piano in vigore (`settings.engine`, `PlanConfig::engine()`):

| Motore | Nome | Stato |
|---|---|---|
| `kosmos_v1` | **Piano Incaricati** (presentazioni «Lamèss Incaricati» e «Seconda parte», conferme di Laura dell'8 ottobre 2026) | quello in uso |
| `multilevel_v3` | **piano precedente (nascondibile)**, a PR/PV | resta nel codice e nei dati; si nasconde dalle schermate, non si cancella |

Lo stesso `PeriodCalculator::calculate()` serve entrambi: se il piano ha la sezione `kosmos` usa `KosmosCalculator`, altrimenti la pipeline a PR. Stesso input ⇒ stesso risultato e stesso hash canonico (riproducibilità e audit). Il motore è codice puro: non legge database né orologio.

## Piano Incaricati (`kosmos_v1`)

### Principi

- **Niente PV.** I compensi si calcolano su importi in euro: `pv_movements.amount` è l'importo **IVA inclusa** della riga d'ordine, già al netto degli sconti di prezzo (i resi lo stornano). Le colonne PV/CV/PR restano come dato tecnico e per il piano precedente: il PV è calcolato dal prezzo (`lamess.eur_per_pv`), un movimento nasce solo da righe con PV maggiore di zero e gli storni sono proporzionali al rapporto tra PV del reso e PV originale. I movimenti storici senza importo si convertono con `commission_base_per_pv` (1,5: 100 punti = 150 €).
- **Base di calcolo = V.A.P.** (valore a provvigione): deciso dal prodotto (percentuale del prezzo IVA inclusa o importo fisso per pezzo); senza V.A.P. di prodotto vale `vap_share_pct` (66,66666%) dell'importo.
- **Chi è cliente lo decide il piano** (`client_rule`): `any_order` (basta un ordine) oppure `min_amount` (un ordine di almeno `client_min_amount` €, IVA inclusa; le righe dello stesso ordine si sommano). Predefinito: ordine da 150 €. Ogni prodotto può valere più clienti (`customer_count`) e per più mesi (`customer_validity_months`).
- **Nessuno stato ATTIVO.** Non c'è spesa mensile minima: diretti, indiretti, indiretti estesi e Bonus di Struttura maturano a prescindere. Restano esclusi dagli indiretti cessati, sospesi e incaricati con onboarding incompleto (D06); un blocco compensi (`bonus_hold`) trattiene gli importi. Badge e sezione «Attività» sono nascosti nelle schermate quando il piano è Incaricati (`ActiveStatus::applies()`).
- **La qualifica è solo il nome della pietra** (Incaricato, Pearl, Crystal, Sapphire, Ruby, Emerald, Diamond) e **vale appena raggiunta**: nello stesso mese, con il ricalcolo notturno.
- **Non esistono** Team Bonus, Matching, Rank Bonus e Fast Start Bonus: il motore Incaricati non li calcola.

### Passi del calcolo

| Passo | Regola | Classe |
|---|---|---|
| 1 | Movimenti del periodo (solo confermati e non esclusi); duplicati, fuori periodo o titolare sconosciuto = errore. I resi di periodi chiusi sono «storni» | `PeriodCalculator`, `SnapshotBuilder` |
| 2 | Cliente di ogni movimento: l'acquirente per le vendite (`pvf`); per l'acquisto dell'incaricato il cliente «`self:{id}`» se K04 è acceso. Un ordine rende cliente se `orderQualifies()` | `KosmosCalculator::clientKey`, `qualifyingPurchases`, `KosmosConfig::orderQualifies` |
| 3 | Clienti personali per incaricato (K01): `period_buyers` = clienti validi nel mese (acquisto del mese più quelli ancora coperti dai mesi di validità dei prodotti, ogni cliente pesa «quanti clienti vale»); `cumulative_buyers` = clienti con un acquisto valido fino a fine mese | `KosmosCalculator::clientCounts`, `coverageIntervals` |
| 4 | Carriera: ogni qualifica ha requisiti propri (clienti personali, Pearl al 1° livello, struttura in linee diverse, colonne con clienti); promozione anche di più livelli, valida dal giorno in cui si raggiunge. Ogni requisito si può spegnere per qualifica | `CareerState::qualification`, `checkpoint`, `effectiveSeq` |
| 5 | Qualifica pagata (K05 = `clients`): ogni mese si paga la più alta per cui i clienti bastano. **Nessun ribasso** se il mese finisce entro la protezione del piano (`rank_grace_days` / `rank_grace_until`, vale la più lontana) o entro la data «Mantieni qualifica» dell'incaricato (`rank_hold_until`); la qualifica può sempre salire | `PeriodCalculator`, `KosmosConfig::ranksProtectedIn`, `Node::rankHeldIn` |
| 6 | Abilitazione: cessato = escluso; sospeso = niente indiretti; onboarding incompleto = niente indiretti (D06); `bonus_hold` = importi trattenuti. I diretti non richiedono altro | `BonusContext`, `PeriodCalculator::withoutActiveStatus` |
| 7 | **Compensi diretti**: % dello scaglione di clienti personali dell'incaricato × V.A.P. del movimento. Vale anche per l'acquisto dell'incaricato se K04 è acceso | `KosmosCalculator::calculate` |
| 8 | **Compensi indiretti** livelli 1–5: ogni upline matura la % del livello × V.A.P. se ha i clienti personali e i Pearl al 1° livello richiesti dal livello. I Pearl si contano sulla qualifica pagata. Lo sponsor matura sull'acquisto di un suo diretto come su un cliente di quest'ultimo | `KosmosCalculator::calculate` |
| 9 | **Indiretti estesi** oltre il 5° livello per Ruby, Emerald e Diamond (1%): arrivano fino al 5° livello del successivo incaricato con la stessa qualifica (K02: «uguale o superiore» come alternativa) | `KosmosCalculator::calculate` |
| 10 | **Bonus di Struttura (PearlQ)**: chi porta il 3° cliente entro 30 giorni dall'ingresso in rete diventa PearlQ; ogni upline riceve la differenza tra il bonus della propria qualifica e il più alto già incontrato risalendo. Fuori dal totale mensile: registro settimanale | `KosmosCalculator::structureBonuses`, `StructureBonusService` |
| 11 | Reso su periodo chiuso (D11): riga negativa `clawback` nel primo periodo aperto, in proporzione al reso, su diretti e indiretti (anche estesi). Il Bonus di Struttura non si storna da qui | `ClawbackCalculator` |
| 12 | Spiegazione per riga (formula, clienti, requisiti), decisioni usate (K01, K02, K03 per la struttura, D11), hash canonico dell'input | `Line`, `Canonical` |

Esempi dai test (`tests/Unit/Engine/KosmosPlanTest.php`): con 3 clienti l'aliquota diretta è 15%; 8% di 100 € di V.A.P. allo sponsor con 1 cliente; Bonus di Struttura sotto Crystal, Sapphire e Diamond: 60, 30 e 90 €.

### Tipi di riga

`direct` (diretto), `indirect` (indiretto, con `level` 1–5), `indirect_ext` (esteso), `structure` (Bonus di Struttura, non salvato nelle righe mensili), `clawback` (storno). Stati: `accrued` (maturato), `held` (trattenuto), `excluded` (escluso, con motivo).

### Quando e come si calcola

- **Ogni notte** (02:45, `lamess:ricalcolo-notturno`): calcolo «live» del primo mese non chiuso; con il Piano Incaricati riscrive anche le qualifiche ufficiali del mese (`source = calculated`: quelle raggiunte, e toglie quelle che un reso ha reso non più valide). Non tocca premi, righe bloccate né qualifiche forzate.
- **Calcolo mensile ufficiale** (`lamess:chiusura-mese`, ogni ora, agisce nel giorno e all'ora scelti nelle impostazioni): modalità `off`, `calculate` o `close`. La chiusura blocca le righe e scrive le qualifiche definitive.
- **Bonus di Struttura**: calcolo settimanale (se attivo) e alla chiusura del mese; conferma e pagamento dell'admin.
- Le **versioni del piano** entrano in vigore alla data di efficacia (`lamess:piano-in-vigore`, ogni giorno alle 00:10); l'`activation_date` per i giorni di protezione viene dalla versione (`effective_from`, altrimenti attivazione).

### Come si nasconde il piano precedente

`App\Domain\Plan\LegacyPlan` (interruttore `legacy_plan_hidden`, commutabile **solo dal super_admin** dalla pagina «Visibilità piano precedente»): nasconde in un colpo menu, schede 360, widget, rete, estratti, esportazioni e testi salvati che parlano di PV/PR/Team/Matching/Rank Bonus/Fast Start. Non cancella e non ricalcola nulla; il log di audit non viene toccato.
- Le funzionalità del `FeatureRegistry` marcate `legacy` sono spente **per tutti, super_admin compreso**, finché il piano è nascosto (`Feature::enabled()`).
- `LegacyPlan::scrub()` ripulisce i testi memorizzati (etichette, messaggi) sostituendo le frasi note con equivalenti neutri e le sigle PV/PVF/PVC/PR con «valori»; gli importi dei bonus del vecchio piano si raccolgono sotto «Compensi del periodo precedente» (`collapseBonusTypes`).
- Con il piano nascosto, il modulo «Nuova bozza» parte dal modello del Piano Incaricati.

## Piano precedente (nascondibile) — motore `multilevel_v3`

Fonte: `PROMPT_Sviluppo_Portale_Lamess_Laravel.md` §9 (S1–S13) e PMK Prelancio 070726 (indicato da Laura il 02/10/2026). Valori in `config/lamess/plan-prelaunch-2026-07-07.json`.

| Passo | Regola implementata | Classe |
|---|---|---|
| S1 | Punti: PV/CV per movimento, base provvigionale `commission_base_per_pv` | `PeriodCalculator`, `Movement` |
| S2 | PR di carriera, regola del 50% per linea, struttura su linee diverse a qualsiasi profondità | `CareerState` |
| S3 | Promozione anche di più livelli; efficace dal 1° del mese successivo (Incaricato subito) | `CareerState` |
| S4 | Attività: 100 **PVF** da almeno 1 cliente (gli acquisti personali non contano), primo mese d'ufficio, prima soglia valida anche il mese dopo | `Activity` |
| S5 | Qualifica pagata = efficace, con eventuale tetto (`rankCap`) | `CareerState::effectiveSeq` |
| S6 | Direct Bonus: % della propria qualifica sulle vendite ai propri clienti. Chi compra non guadagna sul proprio acquisto: lo sponsor e la linea sopra maturano la differenza (Team) | `DirectTeamCalculator` |
| S7 | Team Bonus: differenziale tra aliquote, con compressione upline non idoneo | `DirectTeamCalculator` |
| S8 | Indiretti solo da Pearl in su, attivi e con onboarding completo | `DirectTeamCalculator` |
| S9 | Matching: 6 livelli, minimo Sapphire, matrice 5/10 | `MatchingCalculator` |
| S10 | Rank Bonus: premio per qualifica, 12 rate sopra 50.000 € | `RankBonusCalculator` |
| S11 | Fast Start Bonus: 9 step entro 7…210 giorni dalla firma (diretti con ≥100 PV, PV cumulati con regola del 50%, qualifica raggiunta) + step 10 (3 linee con step 9). Premi già pagati nei periodi chiusi non si ripetono | `FsbCalculator` |
| S12 | Resi su periodi chiusi: storno nel primo periodo aperto | `ClawbackCalculator` |
| S13 | Spiegazione per riga, hash canonico dell'input | `Line`, `Canonical` |

Esempio verificato (G26, simulazione, `GoldenTest`): Pearl Direct 18%→19% su 1.000 PV: Direct +10, Team −10, Matching +1 (variazione netta per l'upline Diamond: −9).

I valori del piano ufficiale sono in `config/lamess/plan-prelaunch-2026-07-07.json` (somma Rank Bonus 522.500 €; FSB step 1–9: 38.440 €).

### Allineamento al PMK 070726 (02/10/2026)

- ATTIVO: solo PVF (`activity_points_source = own_customers_pvf`).
- Acquisti personali: nessun Direct a chi compra, differenza allo sponsor (`personal_purchase_direct_beneficiary = upline_differential`, OPEN-35).
- Fast Start Bonus attivo (`fsb_enabled = true`); `direct_min_pv = 100` per ogni diretto, PV personali cumulati dalla registrazione (OPEN-04: da confermare col cliente).
- PV: fisso per prodotto (`discount_pv_policy = catalog`); il valore calcolato imponibile / 1,23 vale solo per i prodotti senza PV.

## Regole comuni ai due motori (portale)

- **Pagamento**: con `payout_mode = monthly_auto` il comando `lamess:pagamenti` (ogni giorno alle 05:00) prepara i pagamenti «da pagare» di ogni mese chiuso dal giorno `payout_day` (15) del mese successivo, una sola volta per mese (`periods.payouts_prepared_at`). Con `on_request` si prepara a mano. Il bonifico in banca resta manuale. Soglia minima `min_payout`.
- **Ricevute e ritenuta d'acconto** sono spente (`LAMESS_RECEIPTS_ENABLED`, `LAMESS_WITHHOLDING_ENABLED`): il bonifico è pari al lordo finché il commercialista non convalida i valori fiscali.
- **Abbonamenti**: mensili, con carta o KMoney; ogni ciclo è un ordine normale. I nuovi abbonamenti partono con impegno di 12 mesi **non vincolante** (si interrompono quando si vuole; la disdetta anticipata viene solo registrata) e con **spedizione sempre gratuita**. L'impegno minimo configurabile e la sua soglia non esistono più (RF-A01…A05; regole aperte OPEN-40/41).
- **Ordine per conto di altri**: più destinatari (clienti assegnati e incaricati diretti), una sola spedizione; i compensi seguono il destinatario di ogni riga.
- **Importi IVA inclusa**: prezzi e riepiloghi sono mostrati IVA inclusa (cliente e admin; dell'IVA si indica solo l'aliquota). Nel database restano prezzo netto a 8 decimali e aliquota: l'IVA inclusa si ricava con `Product::grossPrice()` e `OrderService::grossUnit()`, e il totale fattura si arrotonda una sola volta per aliquota.
