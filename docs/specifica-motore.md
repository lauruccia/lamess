# Specifica del motore

Fonte: `PROMPT_Sviluppo_Portale_Lamess_Laravel.md` §9 (S1–S13) e PMK Prelancio 070726 (testo ufficiale in corso, indicato da Laura il 02/10/2026).

| Passo | Regola implementata | Classe |
|---|---|---|
| S1 | Punti: PV/CV per movimento, base provvigionale `commission_base_per_pv` | `PeriodCalculator`, `Movement` |
| S2 | PR di carriera, regola del 50% per linea, struttura su linee diverse a qualsiasi profondità | `CareerState` |
| S3 | Promozione anche di più livelli; efficace dal 1° del mese successivo (Consulente subito) | `CareerState` |
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

Esempio verificato (G26, simulazione): Pearl Direct 18%→19% su 1.000 PV: Direct +10, Team −10,
Matching +1 (variazione netta per l'upline Diamond: −9).

I valori del piano ufficiale sono in `config/lamess/plan-prelaunch-2026-07-07.json`
(somma Rank Bonus 522.500 €; FSB step 1–9: 38.440 €).

## Allineamento al PMK 070726 (02/10/2026)

- ATTIVO: solo PVF (`activity_points_source = own_customers_pvf`).
- Acquisti personali: nessun Direct a chi compra, differenza allo sponsor (`personal_purchase_direct_beneficiary = upline_differential`, OPEN-35).
- Fast Start Bonus attivo (`fsb_enabled = true`); `direct_min_pv = 100` per ogni diretto, PV personali cumulati dalla registrazione (OPEN-04: da confermare col cliente).
- PV: fisso per prodotto (`discount_pv_policy = catalog`); il valore calcolato imponibile / 1,23 vale solo per i prodotti senza PV.
- Pagamento: con `payout_mode = monthly_auto` il comando `lamess:pagamenti` (ogni giorno alle 05:00) prepara i pagamenti «da pagare» di ogni mese chiuso dal giorno `payout_day` (15) del mese successivo, una sola volta per mese (`periods.payouts_prepared_at`). Con `on_request` si prepara a mano. Il bonifico in banca resta manuale.
- Nel portale: abbonamento mensile con carta, ordine multiplo (più destinatari, una sola spedizione) e spedizione gratuita con impegno di almeno 12 mesi (RF-A01…A05; regole aperte OPEN-40/41).
