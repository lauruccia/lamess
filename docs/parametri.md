# Parametri del motore

> Generato da `php artisan lamess:parametri --write=...`. Non modificare a mano.

## Impostazioni numeriche

| Chiave | Descrizione | Default | Fonte |
|---|---|---|---|
| `activity_threshold` | Soglia punti per lo stato ATTIVO | `100` | PMK p.14 |
| `commission_base_per_pv` | Euro di base provvigionale per 1 PV/CV | `1` | PMK p.25–28 (8.000 PV × 9% = 720 €) |
| `fifty_rule_share` | Quota massima di una linea (regola del 50%) | `0.5` | PMK p.19–20 |
| `indirect_min_rank` | Qualifica minima per le commissioni indirette | `PEARL` | PMK p.24 |
| `matching_min_rank` | Qualifica minima per il Matching | `SAPPHIRE` | PMK p.29 |
| `rank_bonus_installment_threshold` | Soglia oltre cui il Rank Bonus è rateizzato (€) | `50000` | PMK p.32 |
| `rank_bonus_installments` | Numero di rate del Rank Bonus | `12` | PMK p.32 |
| `rank_bonus_hold_months` | Mesi di trattenuta del Rank Bonus da inattivo | `3` | OPEN-06 |
| `fsb_enabled` | Fast Start Bonus attivo | `true` | PMK 070726 p.33–35 |
| `payout_mode` | Pagamento delle provvigioni (monthly_auto | on_request) | `monthly_auto` | PMK 070726 p.14: bonifico mensile entro il 15 del mese successivo (Reg. 6.1.a: su richiesta) — OPEN-33 |
| `payout_day` | Giorno del mese successivo entro cui si paga | `15` | PMK 070726 p.14 |
| `min_payout` | Pagamento minimo (€) | `15` | lamess.config (Odoo): Minimum Payout |
| `scn_threshold` | Soglia SCN (€) | `5000` | lamess.config (Odoo): SCN Threshold — significato da chiarire |
| `move_node_window_days` | Finestra per spostare un consulente nell'albero (giorni) | `7` | lamess.config (Odoo): Move Node Window — significato da chiarire |

## Politiche

| Decisione | Politica | Default | Stato | Opzioni | Fonte |
|---|---|---|---|---|---|
| D01 | `activity_points_source` — Punti validi per lo stato ATTIVO | `own_customers_pvf` | client_decided | `own_customers_pvf_plus_own_pvc` = PVF dei propri clienti + PVC dei propri acquisti; `own_customers_pvf` = Solo PVF dei propri clienti | PMK 070726 p.24 («100 PVF generati da almeno 1 Cliente»); Laura 02/10/2026. Sostituisce la call del 15/09 (K1=C) |
| D01 | `activity_first_time` — Prima soglia raggiunta | `current_and_next` | client_decided | `current_and_next` = Vale per il mese corrente e il successivo; `current_only` = Solo mese corrente | Call 15/09/2026 (C1); PMK p.24 |
| D01 | `activity_month_rule` — Mesi successivi: quale mese conta | `current` | provisional | `current` = Mese corrente; `previous` = Mese precedente (PMK p.14); `current_or_previous` = Corrente oppure precedente | PMK p.14 vs p.24; call 15/09/2026: da chiarire |
| D01 | `activity_first_month_free` — Primo mese da consulente attivo d'ufficio (mese solare) | sì | certain | sì/no | PMK p.14 (N.B.) |
| D03 | `commission_base` — Base delle provvigioni | `pv` | provisional | `pv` = PV; `cv` = CV (promo Double CV di M1) | PMK p.25–28 (esempi su PV); M1 §3 (CV) |
| D02 | `discount_pv_policy` — PV su righe scontate | `catalog` | provisional | `proportional` = PV ridotti in proporzione allo sconto; `catalog` = PV di catalogo invariati | PMK 070726 p.14 e Webinar s.23–25: il Box vale 100 PV anche a prezzo promo (OPEN-34); catalogo Odoo |
| D03 | `pr_source` — Punti che alimentano i PR | `pv` | provisional | `pv` = PV; `cv` = CV | PMK p.13 (1 PR per ogni PV); M1 §3 (CV carriera) (OPEN-19) |
| D04 | `personal_purchase_direct_beneficiary` — Direct Bonus sugli acquisti personali del consulente | `upline_differential` | client_decided | `upline_differential` = Nessun Direct a chi compra; lo sponsor e la linea sopra maturano la differenza (Team); `sponsor` = Allo sponsor, come vendita diretta; `self` = Al consulente stesso; `none` = Nessuno | Laura 02/10/2026 («guadagno sulle vendite, non sugli acquisti propri») (OPEN-35); sostituisce la call del 15/09 (K2=B) |
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
| D09 | `fsb_step10_deadline` — Step 10: termine per i tre consulenti | `own_window` | provisional | `own_window` = Step 9 nella loro finestra (210 giorni), nessuna scadenza per lo sponsor (PMK 070726 p.35) | Guida FSB p.7 vs PMK p.35 (OPEN-08) |
| D11 | `refund_after_close` — Reso dopo la chiusura del periodo | `clawback_next_open` | provisional | `clawback_next_open` = Storno nel primo periodo aperto; `no_clawback` = Nessun recupero | M1 §1 (refund) (OPEN-26) |
