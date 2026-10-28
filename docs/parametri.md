# Parametri del piano e del portale

> Aggiornato al 28 ottobre 2026 sul codice (`config/lamess/*.json`, `PlanConfig`, `KosmosConfig`, `PolicySpecs`, `config/lamess.php`, `SystemSettings`).
> Le sezioni 1–11 descrivono il **Piano Incaricati**, il portale e le variabili del `.env` e sono scritte a mano. La sezione 12 (piano precedente) è la tabella prodotta da `php artisan lamess:parametri`: vedi la nota sulla rigenerazione in fondo.

## Come leggere questo documento

- Il portale conosce **due motori di calcolo**, scelti dalla versione del piano in vigore (`settings.engine`):
  - `kosmos_v1` = **Piano Incaricati** (compensi in euro, niente punti PV, niente stato ATTIVO);
  - `multilevel_v3` = **piano precedente (nascondibile)**, a PR/PV: Direct, Team, Matching, Rank Bonus, Fast Start Bonus. Resta nel codice e nei dati; un interruttore del super_admin lo nasconde da tutte le schermate (vedi la specifica del motore).
- **Dove stanno i valori.** Il file `config/lamess/plan-incaricati-2026-10-08.json` è solo il modello di partenza: serve a creare la bozza («Crea bozza “Piano Incaricati”» in Compensi → Piano compensi). I valori che governano il calcolo sono quelli della **versione del piano in vigore** (`comp_plan_versions.data`), che si modificano da Piano compensi (bozza → simulazione → pubblicazione, con approvazione a quattro occhi se attiva e data di efficacia). Una versione già pubblicata non si tocca: si duplica e si modifica la copia.
- Le percentuali nel JSON sono **punti percentuali** (`"10"` = 10%). Gli importi sono in euro, **IVA inclusa**.
- La bozza del file porta l'etichetta «BOZZA da validare»: i valori sono quelli delle presentazioni «Lamèss Incaricati» e «Seconda parte» e delle conferme di Laura dell'8 ottobre 2026.
- Terminologia: nel prodotto si dice «Incaricato/Incaricati»; nel codice restano i nomi tecnici `Consultant`, `consultants`, `consultant_id` e il codice di qualifica `CONSULTANT` (nome mostrato: «Incaricato»).

## 1. Qualifiche e requisiti (`ranks`)

La qualifica è **solo il nome della pietra**. La qualifica vale **appena raggiunta** (nello stesso mese) e si aggiorna subito dopo ogni pagamento, reso, movimento inserito a mano, «Assegna punti» o «Forza/Mantieni qualifica» (ricalcolo live in coda, `LiveRecalcDispatcher`) e, in ogni caso, col ricalcolo notturno delle 02:45. Tutti i requisiti si modificano per qualifica nel modulo del piano e si possono spegnere uno per uno (`disabled_requirements`: `clients`, `pearl`, `structure`, `columns`).

| Codice | Nome | Clienti personali minimi (`min_clients`) | Pearl (o superiori) al 1° livello (`min_pearl_first_level`) | Altri requisiti |
|---|---|---|---|---|
| `CONSULTANT` | Incaricato | 0 | 0 | nessuno (qualifica d'ingresso) |
| `PEARL` | Pearl | 3 | 0 | |
| `CRYSTAL` | Crystal | 6 | 2 | |
| `SAPPHIRE` | Sapphire | 6 | 3 | struttura: 2 Crystal |
| `RUBY` | Ruby | 6 | 4 | colonne: 3 colonne con almeno 90 clienti ciascuna (`min_columns` = 3, `min_column_clients` = 90) |
| `EMERALD` | Emerald | 6 | 5 | struttura: 2 Sapphire + 2 Ruby |
| `DIAMOND` | Diamond | 6 | 6 | struttura: 3 Emerald (ultima qualifica) |

- **Clienti personali**: quelli contati secondo la regola cliente e la politica K01 (sezioni 6 e 9). Con K04 acceso l'acquisto dell'incaricato conta come un suo cliente.
- **Pearl al 1° livello**: incaricati diretti non cessati con qualifica raggiunta almeno Pearl.
- **Struttura**: qualifiche richieste in **linee diverse**, a qualsiasi profondità (`structure_depth` = `any_depth`); una linea riempie un solo posto.
- **Colonne**: una colonna è un diretto con tutta la sua discendenza; conta se i clienti personali dei suoi componenti, sommati, raggiungono `min_column_clients`.
- **Agenti regalati** (aiuti di qualifica, tabella `rank_helps`, Compensi → Agenti regalati o «Assegna punti»): l'azienda aggiunge fino a 50 agenti virtuali di una qualifica sotto un incaricato. Contano al 1° livello, ciascuno in una colonna diversa, **solo per i requisiti della qualifica** (Pearl al 1° livello e struttura); non sono clienti, non generano compensi, non contano per i requisiti dei livelli indiretti e non compaiono nella rete. Valgono dalla data indicata fino alla scadenza (vuota = finché non si annullano).
- I campi `pr_required`, `fifty_percent_rule`, `direct_pct`, `rank_bonus` restano nel file ma nel Piano Incaricati valgono 0/falso (servono al piano precedente).

## 2. Compensi diretti (`kosmos.direct_tiers`)

Aliquota sul V.A.P. di ogni acquisto dei clienti dell'incaricato, in base a quanti **clienti personali** ha (si applica lo scaglione intero raggiunto).

| Clienti da | % |
|---|---|
| 0 | 10 |
| 3 | 15 |
| 6 | 20 |
| 9 | 25 |
| 12 | 30 |
| 24 | 35 |
| 48 | 40 |
| 96 | 50 |

Gli scaglioni devono avere clienti minimi strettamente crescenti.

## 3. Compensi indiretti (`kosmos.indirect_levels`)

L'incaricato matura sugli acquisti dei clienti degli incaricati che stanno **sotto di lui** fino a 5 livelli, se soddisfa i requisiti del livello. I livelli sono consecutivi (1, 2, 3…).

| Livello | % | Clienti personali minimi | Pearl minimi al 1° livello |
|---|---|---|---|
| 1 | 8 | 1 | 0 |
| 2 | 4 | 2 | 1 |
| 3 | 2 | 3 | 2 |
| 4 | 1 | 3 | 2 |
| 5 | 8 | 6 | 3 |

(Livello 5 all'8%: valore del file, confermato da Laura.) Per gli indiretti conta la qualifica **pagata** nel mese (anche per contare i Pearl al 1° livello). Restano esclusi cessati, sospesi e incaricati con onboarding incompleto (politica D06 `onboarding_incomplete_indirect`, predefinita «non maturano»); un blocco compensi (`bonus_hold`) li trattiene.

Acquisto dell'incaricato (K04, interruttore): se acceso, l'incaricato è cliente di sé stesso e lo sponsor matura l'indiretto di 1° livello come per un cliente di un suo diretto.

## 4. Indiretti estesi (`kosmos.extended_pct`, `extended_stop_levels`, `extended_requires_level`)

| Chiave | Valore nel file | Significato |
|---|---|---|
| `extended_pct` | RUBY 1 · EMERALD 1 · DIAMOND 1 | % sui livelli **oltre il 5°**, per qualifica pagata |
| `extended_stop_levels` | 5 | il compenso arriva fino al 5° livello del successivo incaricato con la stessa qualifica (K02: «uguale o superiore» come alternativa) |
| `extended_requires_level` | vuoto | livello di cui valgono anche i requisiti (clienti, Pearl) per maturare gli estesi; vuoto = nessun requisito in più |

## 5. Bonus di Struttura / PearlQ (`kosmos.structure_*`)

| Chiave | Valore | Significato |
|---|---|---|
| `structure_clients` | 3 | clienti da portare per diventare «PearlQ» |
| `structure_window_days` | 30 | giorni dall'ingresso in rete (`network_since`, altrimenti data della lettera) entro cui portarli |
| `structure_bonus` | CRYSTAL 60 · SAPPHIRE 90 · RUBY 120 · EMERALD 150 · DIAMOND 180 | € per qualifica dell'upline |
| `pearl_rank` | PEARL | qualifica che definisce «Pearl» nei requisiti |

Ogni upline riceve `bonus(propria qualifica) − bonus(più alta qualifica già incontrata risalendo)`, se positivo. Esempio dei test: PearlQ sotto un Crystal, sotto uno Sapphire, sotto un Diamond → Crystal 60, Sapphire 30, Diamond 90 €.
Con il pagamento «su richiesta» (Tasca, sezione 8) un Bonus di Struttura confermato viene accreditato **una sola volta** nella Tasca dell'incaricato (`structure_bonuses.wallet_credited_at`), risulta «pagato» con riferimento «Tasca» e compare nello **statino settimanale dei Bonus di Struttura** (settimana lunedì–domenica, ora di Roma, quella della conferma). Il Bonus di Struttura **non entra nel totale mensile del motore**: ha un registro suo (stati calcolato → confermato → pagato), ricalcolato ogni settimana se attivo (`structure_bonus_auto`, `structure_bonus_weekday`, `structure_bonus_hour`) e a mano dalla pagina «Bonus di Struttura».

## 6. Chi è cliente, V.A.P. e validità

| Chiave (`kosmos.`) | Valore nel file | Significato |
|---|---|---|
| `client_rule` | `min_amount` | `any_order` = basta un ordine; `min_amount` = un ordine di almeno `client_min_amount` € (IVA inclusa; le righe dello stesso ordine si sommano; conta il **totale dell'ordine**, anche solo accessori). In alternativa un ordine rende cliente se contiene un prodotto con «Rende cliente a prescindere dall'importo» (`products.makes_client`, spento di default, congelato su riga d'ordine e movimento) |
| `client_min_amount` | 150 | importo minimo (€, IVA inclusa). Con `min_amount` un ordine in promozione sotto l'importo (es. Box a 129,90 €) non rende cliente |
| `vap_share_pct` | 66,66666 | quota dell'importo (IVA inclusa, già scontato) che diventa V.A.P. quando il prodotto non ne ha uno proprio (150 € → 100 €) |

**Per prodotto** (scheda prodotto, sezione «Commissioni e durata (Piano Incaricati)»):
- `vap_mode` + `vap_value`: V.A.P. come `percent` del prezzo IVA inclusa o `fixed` in euro per pezzo; vuoto = vale `vap_share_pct`. Le varianti ereditano dal prodotto principale se non impostano il proprio.
- `customer_validity_months`: per quanti mesi l'acquisto rende valido il cliente per l'incaricato (vuoto = 1).
- `months_per_piece` («I pezzi moltiplicano i mesi», acceso di default, la variante eredita dal prodotto): se acceso una riga da N pezzi vale `customer_validity_months` × N mesi; se spento vale la durata del prodotto una volta sola (`Product::customerValidityMonthsFor`).
- `customer_count`: quanti clienti vale l'ordine (vuoto = 1; tra più righe vale il valore più alto).
- `kmoney_percent`: quota pagabile in KMoney (0, 25, 50, 75, 100; predefinito 100).
I valori sono congelati sulla riga d'ordine (`order_items.vap_mode/vap_value/validity_months/client_count`) e copiati sul movimento (`pv_movements.vap_amount/validity_months/client_count`): cambiare il prodotto non tocca gli ordini già fatti.

**Punti assegnati dall'azienda** (Compensi → Punti assegnati, `PointsGrant`): movimento di tipo `grant` nel periodo del mese indicato, con tre effetti: solo punteggio registrato, «cliente per N mesi» (conta per qualifiche e requisiti, nessun compenso sulla vendita) oppure «come un acquisto» di un importo in euro (cliente per N mesi e compensi sul V.A.P. del piano).

**Movimenti senza importo** (storico importato): `pv_movements.amount` vuoto → importo = punti × `settings.commission_base_per_pv` (1,5: 100 punti = 150 €).

## 7. Mantenimento della qualifica

Con la politica K05 = `requirements` (predefinita dal 15/10/2026) ogni mese si paga la qualifica più alta di cui l'incaricato rispetta ancora tutti i requisiti (clienti personali, Pearl al 1° livello, struttura, colonne); con `clients` contano solo i clienti. Due protezioni evitano il ribasso per mancanza di clienti (la qualifica può sempre salire):

| Parametro | Dove | Significato |
|---|---|---|
| `kosmos.rank_grace_days` | piano | giorni dall'efficacia della versione (`effective_from`, altrimenti giorno di attivazione) in cui nessuno scende; ogni mese iniziato nella finestra è protetto per intero. Predefinito vuoto (la protezione è la data fissa 31/12/2026); vuoto o 0 = nessuna protezione a giorni |
| `kosmos.rank_grace_until` | piano | data fissa (AAAA-MM-GG) fino a cui nessuno scende; vuoto = nessuna |
| `consultants.rank_hold_until` e `rank_hold_reason` | singolo incaricato | «Mantieni qualifica» fino a una data scelta dall'admin oppure **fino a revoca** (`2099-12-31`); vuoto = nessuna protezione |

- Se ci sono sia i giorni sia la data fissa vale la **più lontana**. Sono protetti i mesi che **finiscono** entro la data (per proteggere tutto ottobre: 31/10).
- «Mantieni qualifica» si imposta dalla scheda incaricato, dal menu riga, in massa o su tutti i registrati non cessati. Richiede il permesso `network.restructure` (non è in nessun ruolo di dettaglio come `admin_network`: lo concede il super_admin a un admin, utente per utente, oppure col profilo «completo»; il super_admin lo ha sempre) e la funzionalità `consultant360.action.hold_rank`. Ogni modifica scrive nel log di audit `rank.hold` o `rank.hold_removed`. Vale dal prossimo ricalcolo (notturno).
- **Forza qualifica** (`TreeOperations::forceRank`, permesso `network.restructure`): scrive la qualifica con fonte `forced` (mostrata come «forzata») e, se si indica una data o «fino a revoca», imposta anche la stessa protezione di «Mantieni qualifica»; senza data né revoca il ricalcolo notturno può riabbassarla se mancano i requisiti. Dopo la forzatura e dopo «Mantieni qualifica» il calcolo live riparte subito.
- Con K05 = `none` le protezioni non servono: la qualifica raggiunta resta.

## 8. Impostazioni generali del piano (`settings`)

Nel Piano Incaricati il motore usa solo queste chiavi; le altre (`activity_threshold`, `fifty_rule_share`, `matching_min_rank`, `rank_bonus_*`, `fsb_enabled`) sono nel file per compatibilità con il modulo del piano e non hanno effetto.

| Chiave | Valore nel file | Significato |
|---|---|---|
| `engine` | `kosmos_v1` | sceglie il motore; con `kosmos_v1` la sezione `kosmos` è obbligatoria |
| `indirect_min_rank` | `CONSULTANT` | qualifica minima (pagata) per maturare gli indiretti: nessuna soglia |
| `commission_base_per_pv` | 1,5 | euro per punto, solo per convertire i movimenti storici senza importo |
| `payout_mode` | `on_request` (dal 21/10/2026; prima `monthly_auto`) | `on_request` = **Tasca**: gli accrediti maturano nella Tasca dell'incaricato, che **chiede il prelievo** (documento fiscale facoltativo, aggiungibile dopo; minimo `min_payout`; pagamento entro 5 giorni lavorativi dall'approvazione); `monthly_auto` = i pagamenti si preparano da soli dal `payout_day`. Vale la versione del piano **in vigore**: il file è solo il modello per le nuove bozze |
| `payout_day` | 15 | giorno (1–28) del mese successivo da cui si preparano i pagamenti (solo con `monthly_auto`) |
| `min_payout` | 15 | pagamento minimo (€): sotto, si accumula |
| `scn_threshold` | 5000 | soglia SCN (€), significato da chiarire: oggi è solo un valore salvato |
| `move_node_window_days` | 7 | finestra per spostare un incaricato nell'albero (giorni), significato da chiarire: oggi è solo un valore salvato |

## 9. Politiche del Piano Incaricati (K01–K06)

Le politiche D01–D12 del piano precedente stanno nella sezione 12. Quelle del Piano Incaricati sono (valori predefiniti):

| Decisione | Politica | Predefinito | Stato | Opzioni |
|---|---|---|---|---|
| K01 | `kosmos_client_count` | `period_buyers` | provvisoria | `period_buyers` = clienti con un acquisto valido nel mese (compresi i mesi di validità dei prodotti già acquistati); `cumulative_buyers` = clienti che hanno acquistato almeno una volta fino a fine mese |
| K02 | `kosmos_extended_stop` | `same_or_higher` | deciso da Laura (14/10/2026) | `same_rank` = indiretti estesi fino al 5° livello del successivo con la stessa qualifica; `same_or_higher` = uguale o superiore |
| K03 | (nessuna politica) | | | la sigla K03 compare solo nelle spiegazioni delle righe del Bonus di Struttura |
| K04 | `kosmos_own_purchase_as_client` | `on` | deciso dal cliente | `on` = l'acquisto dell'incaricato conta come cliente di sé stesso e genera compensi; `off` = gli acquisti personali non generano compensi |
| K05 | `kosmos_rank_maintenance` — Mantenimento della qualifica | `requirements` | deciso da Laura (15/10/2026: retrocede se non rispetta almeno un requisito) | `requirements` = si paga la qualifica più alta di cui si rispettano ancora tutti i requisiti; `clients` = solo i clienti personali; `none` = nessun mantenimento |
| K06 | `kosmos_compression` — Compressione della linea negli indiretti | `skip` | deciso da Laura (15/10/2026) | `skip` = chi non rispetta i requisiti è saltato e i livelli si contano solo tra chi li rispetta; `none` = livello genealogico |

Nel Piano Incaricati valgono anche D06 (`onboarding_incomplete_indirect`) e D11 (`refund_after_close`, storno nel primo periodo aperto): vedi la sezione 11.

## 10. Altri parametri del portale che toccano il piano

Si cambiano dal pannello (Impostazioni di sistema, che ha la precedenza sul `.env`) oppure nel `.env`; elenco completo in `config/lamess.php` e `App\Domain\Settings\SystemSettings::DEFINITIONS`.

| Chiave | Predefinito | Significato |
|---|---|---|
| `legacy_plan_hidden` (`LAMESS_LEGACY_PLAN_HIDDEN`) | sì (dal 19/10/2026; prima no) | nasconde il piano precedente in tutto il portale; lo commuta solo il super_admin dalla pagina «Visibilità piano precedente» (Compensi) |
| `vat_zero_nature` (`LAMESS_VAT_ZERO_NATURE`) | `N2.2` | Natura delle righe a IVA 0 verso clienti italiani (N2.2 non soggette, N4 esenti); extra-UE usa N3.1, azienda UE con P.IVA N3.2. Da confermare con il commercialista |
| `eu_countries` | elenco UE | paesi UE per scegliere la Natura |
| `analytics.enabled` (`LAMESS_ANALYTICS`) | sì | statistiche del sito (pagina «Statistiche sito»): spento non registra più nulla |
| `analytics.retention_days` (`LAMESS_ANALYTICS_RETENTION_DAYS`) | 180 | giorni di conservazione di visite e pagine viste (minimo 30); la pulizia gira ogni notte alle 03:10 |
| `sdi.send_mode` (`LAMESS_SDI_SEND_MODE`) | `immediate` | invio degli XML delle fatture: `immediate` all'emissione, `batch` in blocco (pulsante o 1° del mese alle 08:30) |
| `period_auto_close` | `calculate` | chiusura mensile automatica: `off` · `calculate` (solo calcolo ufficiale) · `close` |
| `commission_calc_day`, `commission_calc_hour` | `1`, `1` | quando parte il calcolo del mese: `end` (ultimo giorno) oppure giorno 1–28 del mese successivo, all'ora indicata (ora italiana) |
| `structure_bonus_auto`, `structure_bonus_weekday`, `structure_bonus_hour` | no, 1 (lunedì), 6 | calcolo settimanale del Bonus di Struttura |
| `plan_four_eyes` (`LAMESS_PLAN_FOUR_EYES`) | sì | la pubblicazione del piano richiede un secondo amministratore |
| `subscription.reminder_days` | 7 | giorni di preavviso del promemoria prima dell'addebito (0 = nessun promemoria) |
| `network.downline_contacts`, `network.downline_place` | acceso, acceso | cosa vede l'incaricato dei sotto-incaricati: email/telefono e città/provincia |
| `shop_countries` (`LAMESS_SHOP_COUNTRIES`) | IT | nazioni aperte nello shop, separate da virgola |
| `subscription.retry_days`, `subscription.max_retries`, `subscription.max_skip_ahead_months` | `[1, 3, 5]`, 4, 3 | giorni di attesa dopo il 1°, 2° e 3° addebito fallito (l'ultimo valore vale per gli eventuali successivi; dal pannello si imposta un numero solo, «sempre uguale»); `max_retries` = addebiti falliti dopo i quali l'abbonamento va in pausa (il 1° addebito più 3 nuovi tentativi); mesi che il cliente può saltare. L'impegno minimo configurabile non esiste più: i nuovi abbonamenti partono con impegno di 12 mesi **non vincolante** e con spedizione sempre gratuita |
| `max_shipping_addresses` | 10 | indirizzi di spedizione per utente (valore fisso in `config/lamess.php`) |
| `kmoney_driver` (`LAMESS_KMONEY_DRIVER`) | `fake` | `fake` (sviluppo) · `api` (produzione) · `off` |
| `kmoney_bonus_percent` | 25 | vantaggio KMoney mostrato nel checkout (0 = nessun messaggio) |
| `fiscal.receipts_enabled` (`LAMESS_RECEIPTS_ENABLED`) | no | ricevute provvigionali: **spente** finché non risponde il commercialista |
| `fiscal.withholding_enabled` (`LAMESS_WITHHOLDING_ENABLED`) | no | ritenuta d'acconto: **spenta** (INPS: `LAMESS_INPS_ENABLED`, spenta) |
| `annual_fee_enabled`, `annual_fee_amount` | sì, 19.00 | canone annuale (IVA compresa); spento = nessun canone |
| `return_window_days` | 14 | giorni dalla consegna per chiedere un reso |
| `subscription.monthly_enabled`, `subscription.bimonthly_enabled` | sì, sì | abbonamento mensile e bimestrale attivabili dal cliente (quelli già attivi proseguono); `subscriptions.interval_months` = 1 o 2 |
| `subscription.card_expiry_days` | 30 | avviso al cliente quando la carta salvata scade entro questi giorni o prima del prossimo addebito (0 = nessun avviso) |
| `orders.admin_mail_to` (`LAMESS_ORDINI_MAIL_TO`) | vuoto | destinatari dell'avviso «Nuovo ordine»; vuoto = utenti admin e super admin |
| `subscription.admin_mail_to` (`LAMESS_ABBONAMENTI_MAIL_TO`) | vuoto | destinatari del riepilogo giornaliero degli abbonamenti (08:15, parte solo se c'è qualcosa da seguire); vuoto = admin e super admin |
| `order_for_others` (`LAMESS_ORDER_FOR_OTHERS`) | no | «Ordine per altri» nel carrello (OPEN-41): nascosto di default dal 23/10/2026; codice e rotte restano |
| `recalc_on_payment` | sì | dopo ogni pagamento, reso, movimento a mano, «Assegna punti» e «Forza/Mantieni qualifica» parte il ricalcolo live del mese aperto (a risposta inviata); solo `config/lamess.php` |
| `sepa.debtor_name`, `sepa.debtor_iban`, `sepa.debtor_bic` (`LAMESS_SEPA_*`) | vuoti | conto aziendale del file SEPA; i pagamenti con IBAN **fuori area SEPA** non vanno nel file: escono in un CSV a parte e richiedono il BIC/SWIFT del beneficiario |
| `wallet_bank_fee` (`LAMESS_BANK_FEE`) | 0,00 | commissione sui prelievi su richiesta: eliminata il 15/10/2026, il campo resta |
| `payment_driver` (`LAMESS_PAYMENT_DRIVER`), `signature_driver` (`LAMESS_SIGNATURE_DRIVER`) | `stripe` in produzione (altrimenti `fake`), `fake` | pagamento con carta e firma della lettera (`email` = OTP per email); `fake` dà BLOCCO nel Controllo messa online |

## 11. Variabili del `.env`

Sono quelle lette da `config/lamess.php`, `config/site.php` e `config/services.php` (controllo del 28 ottobre 2026: `grep -rhoE "env\('[A-Z_]+'" config`). Le colonne «`.env.example`» e «`.env.cpanel.example`» dicono se il modello del repository contiene già la riga (anche commentata): una variabile con «—» **non è obbligatoria**, vale il predefinito indicato, ma va aggiunta a mano nel `.env` se si vuole cambiarla. Le impostazioni che hanno anche un campo in **Impostazioni di sistema** seguono la regola «il pannello vince sul `.env`».

| Variabile | Predefinito | Cosa fa | `.env.example` | `.env.cpanel.example` |
|---|---|---|---|---|
| **Sito, accesso e sicurezza** | | | | |
| `LAMESS_SITE_OPEN` | `false` | sito aperto al pubblico (`config/site.php`); il valore salvato in Impostazioni → Sito vince sul `.env` | sì | sì |
| `ADMIN_2FA_REQUIRED` | `true` | 2FA (app di autenticazione) obbligatoria per tutto il back-office | — | sì |
| `LAMESS_INSTALL_TOKEN` | vuoto | token di almeno 24 caratteri che abilita `/installazione` (una volta); da togliere dopo l'uso | — | sì |
| `LAMESS_AUDIT_KEY` | vuoto (usa `APP_KEY`) | chiave della catena di hash del log di audit; cambiarla rende illeggibile il log precedente | — | sì |
| `LAMESS_AUDIT_KEY_PREVIOUS` | vuoto | `APP_KEY` dell'ambiente da cui sono state importate le prime righe del log (vedi «Messa online») | — | sì |
| `LAMESS_AUDIT_KEY_PREVIOUS_UNTIL` | `0` | id dell'ultima riga del log firmata con la chiave precedente | — | sì |
| `LAMESS_MAIL_ENABLED` | `false` | interruttore generale delle email (con `false` tutto va solo nel log); pannello: Impostazioni → Email | sì | sì |
| `LAMESS_ANALYTICS` | `true` | statistiche del sito; solo `.env` | — | — |
| `LAMESS_ANALYTICS_RETENTION_DAYS` | `180` | giorni di conservazione (minimo 30); solo `.env` | — | — |
| **Rete, incaricati, onboarding** | | | | |
| `LAMESS_REENROLLMENT_DAYS` | `180` | attesa prima di poter tornare incaricato dopo una cessazione (Reg. 2.10.b) | — | — |
| `LAMESS_REFERRAL_COOKIE_DAYS` | `15` | durata del cookie del link referral | — | — |
| `LAMESS_REFERRAL_INVITES_PER_DAY` | `50` | inviti che un incaricato può creare in 24 ore (0 = nessun limite) | — | — |
| `LAMESS_DEFAULT_CONSULTANT_CODE` | vuoto | codice dell'incaricato «casa» per i clienti senza referral (OPEN-20) | — | — |
| `LAMESS_REQUIRE_FIRST_BOX` | `false` | la richiesta di diventare incaricato richiede il primo ordine pagato | — | — |
| `LAMESS_CODE_PREFIX` | `LM` | prefisso del codice incaricato (poi 5 cifre) | — | — |
| `LAMESS_IVD_TEMPLATE` | `2026-2` | etichetta della Lettera di Incarico finché non esiste una versione salvata in `ivd_letter_versions` | — | — |
| `LAMESS_SIGNATURE_DRIVER` | `fake` | `fake` (solo sviluppo, OTP a schermo) · `email` (OTP per email, serve la posta attiva) | — | sì |
| `LAMESS_KYC_DRIVER` | `manual` | `manual` · `stripe_identity` | — | — |
| `LAMESS_KYC_REQUEST_DUE_DAYS` | `7` | giorni per rispondere a una richiesta di documenti (0 = nessuna scadenza) | — | — |
| `LAMESS_ANNUAL_FEE_ENABLED` | `true` | canone annuale acceso/spento | — | — |
| `LAMESS_ANNUAL_FEE` | `19.00` | canone annuale, IVA compresa | — | — |
| `LAMESS_FEE_GRACE_DAYS` | `0` | giorni di grazia sul canone non pagato (OPEN-24) | — | — |
| `LAMESS_TRANSFER_FEE` | `50.00` | contributo per la cessione del codice (Reg. 9.1) | — | — |
| `LAMESS_INHERITANCE_LIMBO_DAYS` | `180` | giorni oltre i quali, senza subentro, il codice di un incaricato deceduto cessa | — | — |
| `LAMESS_PLAN_FOUR_EYES` | `true` | la pubblicazione del piano richiede un secondo amministratore | — | — |
| `LAMESS_LEGACY_PLAN_HIDDEN` | `true` | nasconde il piano precedente (lo commuta il super_admin dal pannello) | — | — |
| **Azienda e venditore** | | | | |
| `LAMESS_COMPANY_NAME` | Consorzio Kadeo Group | dati societari del piè di pagina (anche `_BRAND`, `_ADDRESS`, `_VAT`, `_PEC`, `_EMAIL`) | — | — |
| `LAMESS_SELLER_NAME` | «ragione sociale da configurare» | intestazione delle fatture (anche `_VAT`, `_ADDRESS`, `_EMAIL`, `_TAX_CODE`, `_ZIP`, `_CITY`, `_PROVINCE`; `_REGIME` = `RF01`); pannello: Impostazioni → Venditore | — | — |
| **Negozio, pagamenti, fatture, avvisi** | | | | |
| `LAMESS_PAYMENT_DRIVER` | `stripe` se `APP_ENV=production`, altrimenti `fake` | carta: `fake` (solo sviluppo) · `stripe` | — | — |
| `STRIPE_SECRET` | vuoto | chiave segreta Stripe (solo `.env`, `config/services.php`) | — | — |
| `STRIPE_WEBHOOK_SECRET` | vuoto | segreto di firma del webhook Stripe (senza, i pagamenti non si confermano) | — | — |
| `LAMESS_KMONEY_DRIVER` | `fake` | `fake` · `api` · `off` | sì | — |
| `KMONEY_BASE_URL` | vuoto | indirizzo di KMoney (anche `KMONEY_CLIENT_ID`, `KMONEY_CLIENT_SECRET`, `KMONEY_SELLER_ACCOUNT`, `KMONEY_API_TOKEN`, `KMONEY_WEBHOOK_SECRET`: solo `.env`) | sì | — |
| `KMONEY_MANDATE_LIMIT_KY` | `1500` | tetto per acquisto proposto nel mandato (KY; 0 = non proporre) | sì | — |
| `KMONEY_REGISTER_URL` | vuoto | pagina «Registrati» di KMoney; vuoto = `{KMONEY_BASE_URL}/register` | — | — |
| `LAMESS_SHOP_COUNTRIES` | `IT` | nazioni aperte nello shop, separate da virgola | — | — |
| `LAMESS_EUR_PER_PV` | `1.23` | solo storico: PV suggerito = imponibile / 1,23 | — | — |
| `LAMESS_VAT_ZERO_NATURE` | `N2.2` | Natura delle righe a IVA 0 verso clienti italiani | sì | sì |
| `LAMESS_BANK_INSTRUCTIONS` | «IBAN da configurare…» | testo delle istruzioni per il bonifico (pannello: Ordini e fatture) | — | — |
| `LAMESS_PRIVATE_DOCUMENT` | `invoice` | `invoice` · `receipt` per i privati | — | — |
| `LAMESS_INVOICE_SINK` | `fake` | consegna dell'XML: `fake` · `file` · `mail` | — | — |
| `LAMESS_SDI_MAIL_TO` | vuoto | destinatario del driver `mail` | — | — |
| `LAMESS_SDI_SEND_MODE` | `immediate` | `immediate` · `batch` (in blocco) | — | — |
| `LAMESS_ORDER_FOR_OTHERS` | `false` | «Ordine per altri» nel carrello: spento = nascosto (codice e rotte restano) | sì | — |
| `LAMESS_ORDINI_MAIL_TO` | vuoto | destinatari dell'avviso «Nuovo ordine» (indirizzi separati da virgola); vuoto = tutti gli utenti admin e super admin | — | — |
| `LAMESS_ABBONAMENTI_MAIL_TO` | vuoto | destinatari del riepilogo giornaliero degli abbonamenti (08:15); vuoto = admin e super admin | — | — |
| **Compensi, fisco, pagamenti agli incaricati** | | | | |
| `LAMESS_RECEIPTS_ENABLED` | `false` | ricevute provvigionali (da validare col commercialista) | — | — |
| `LAMESS_WITHHOLDING_ENABLED` | `false` | ritenuta d'acconto (anche `LAMESS_WITHHOLDING_PCT` = `23`, `LAMESS_TAXABLE_PCT` = `78`) | — | — |
| `LAMESS_INPS_ENABLED` | `false` | contributo INPS (anche `LAMESS_INPS_THRESHOLD` = `5000`, `LAMESS_INPS_RATE` = `33.72`, `LAMESS_INPS_WORKER_SHARE` = `33.33`) | — | — |
| `LAMESS_BANK_FEE` | `0.00` | commissione sui prelievi su richiesta (eliminata il 15/10/2026) | — | — |
| `LAMESS_DISPUTE_DAYS` | `10` | giorni per un reclamo su estratti e ricevute | — | — |
| `LAMESS_SEPA_NAME` | vuoto | conto aziendale da cui partono i bonifici (anche `LAMESS_SEPA_IBAN`, `LAMESS_SEPA_BIC`); pannello: Bonifici SEPA | — | — |
| `LAMESS_WATERMARK_FAIL_OPEN` | `true` | se la filigrana non riesce il file esce lo stesso e il registro lo segnala | — | — |

- Le variabili standard di Laravel (`APP_*`, `DB_*`, `SESSION_*`, `CACHE_STORE`, `QUEUE_CONNECTION`, `MAIL_*`, `LOG_*`) hanno i valori per cPanel in `.env.cpanel.example`. `REDIS_*`, `MEMCACHED_*`, `AWS_*`, `POSTMARK_*`, `RESEND_*` e `SLACK_*` ci sono nei file di configurazione dello scheletro ma il portale non li usa.
- **Valori che non hanno una variabile `.env`** e si cambiano solo dal pannello o in `config/lamess.php`: `lamess.subscription.*` (tentativi, preavviso, scadenza carta, mesi saltabili), `lamess.payments.*`, `lamess.kmoney_bonus_percent`, `lamess.period_auto_close`, `commission_calc_*`, `structure_bonus_*`, `lamess.recalc_on_payment` (predefinito `true`: ricalcolo live dopo ogni pagamento, reso o movimento), `lamess.max_shipping_addresses`, `lamess.return_window_days`.
- Per i soli nomi e il significato delle chiavi di KMoney e Stripe sul sito live vedi `installazione-cpanel.md` (A2) e `messa-online.md` (punto 5).

---

## 12. Piano precedente (nascondibile): impostazioni e politiche D01–D12

> Questa parte è prodotta da `php artisan lamess:parametri`. Descrive i valori **predefiniti** del registro (`PlanConfig::SETTINGS`, `PolicySpecs`), che per il piano precedente coincidono con il file `config/lamess/plan-prelaunch-2026-07-07.json`. Il comando elenca anche `engine` e le politiche K01–K06 già descritte sopra.
> **Rigenerazione**: `--write=docs/parametri.md` **sovrascrive l'intero file** e cancellerebbe le sezioni 1–10. Per aggiornare solo le tabelle usa `php artisan lamess:parametri --md` e incolla il risultato qui sotto; oppure scrivi su un altro percorso e riporta le righe cambiate. Il comando non conosce il Piano Incaricati (legge solo il piano di pre-lancio).

### Impostazioni numeriche

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
| `payout_mode` | Pagamento delle provvigioni (monthly_auto o on_request) | `monthly_auto` | PMK 070726 p.14: bonifico mensile entro il 15 del mese successivo (Reg. 6.1.a: su richiesta) — OPEN-33 |
| `payout_day` | Giorno del mese successivo entro cui si paga | `15` | PMK 070726 p.14 |
| `min_payout` | Pagamento minimo (€) | `15` | lamess.config (Odoo): Minimum Payout |
| `scn_threshold` | Soglia SCN (€) | `5000` | lamess.config (Odoo): SCN Threshold — significato da chiarire |
| `engine` | Motore di calcolo (multilevel_v3 o kosmos_v1) | `multilevel_v3` | multilevel_v3 = piano precedente (Direct/Team/Matching/Rank Bonus/Fast Start); kosmos_v1 = Piano Incaricati (presentazioni 08/10/2026). Con kosmos_v1 servono le tabelle «Piano Incaricati» |
| `move_node_window_days` | Finestra per spostare un incaricato nell'albero (giorni) | `7` | lamess.config (Odoo): Move Node Window — significato da chiarire |

### Politiche

| Decisione | Politica | Default | Stato | Opzioni | Fonte |
|---|---|---|---|---|---|
| D01 | `activity_points_source` — Punti validi per lo stato ATTIVO | `own_customers_pvf` | client_decided | `own_customers_pvf_plus_own_pvc` = PVF dei propri clienti + PVC dei propri acquisti; `own_customers_pvf` = Solo PVF dei propri clienti | PMK 070726 p.24 («100 PVF generati da almeno 1 Cliente»); Laura 02/10/2026. Sostituisce la call del 15/09 (K1=C) |
| D01 | `activity_first_time` — Prima soglia raggiunta | `current_and_next` | client_decided | `current_and_next` = Vale per il mese corrente e il successivo; `current_only` = Solo mese corrente | Call 15/09/2026 (C1); PMK p.24 |
| D01 | `activity_month_rule` — Mesi successivi: quale mese conta | `current` | provisional | `current` = Mese corrente; `previous` = Mese precedente (PMK p.14); `current_or_previous` = Corrente oppure precedente | PMK p.14 vs p.24; call 15/09/2026: da chiarire |
| D01 | `activity_first_month_free` — Primo mese da incaricato attivo d'ufficio (mese solare) | sì | certain | sì/no | PMK p.14 (N.B.) |
| D03 | `commission_base` — Base delle provvigioni | `pv` | provisional | `pv` = PV; `cv` = CV (promo Double CV di M1) | PMK p.25–28 (esempi su PV); M1 §3 (CV) |
| D02 | `discount_pv_policy` — PV su righe scontate | `catalog` | provisional | `proportional` = PV ridotti in proporzione allo sconto; `catalog` = PV di catalogo invariati | PMK 070726 p.14 e Webinar s.23–25: il Box vale 100 PV anche a prezzo promo (OPEN-34); catalogo Odoo |
| D03 | `pr_source` — Punti che alimentano i PR | `pv` | provisional | `pv` = PV; `cv` = CV | PMK p.13 (1 PR per ogni PV); M1 §3 (CV carriera) (OPEN-19) |
| D04 | `personal_purchase_direct_beneficiary` — Direct Bonus sugli acquisti personali dell’incaricato | `upline_differential` | client_decided | `upline_differential` = Nessun Direct a chi compra; lo sponsor e la linea sopra maturano la differenza (Team); `sponsor` = Allo sponsor, come vendita diretta; `self` = All’incaricato stesso; `none` = Nessuno | Laura 02/10/2026 («guadagno sulle vendite, non sugli acquisti propri») (OPEN-35); sostituisce la call del 15/09 (K2=B) |
| D05 | `team_ineligible_upline` — Team Bonus con upline non idoneo | `compress` | provisional | `compress` = Compressione: il differenziale risale all'upline idoneo; `absorb` = La quota dell'upline non idoneo si perde | PMK p.24 (compressione dinamica) (OPEN-14) |
| D05 | `matching_level_counting` — Conteggio livelli Matching | `compressed_active` | provisional | `compressed_active` = Solo incaricati attivi contano come livello; `genealogical` = Livelli genealogici fissi | PMK p.24, p.29–31 (OPEN-15) |
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
| D09 | `fsb_step10_deadline` — Step 10: termine per i tre incaricati | `own_window` | provisional | `own_window` = Step 9 nella loro finestra (210 giorni), nessuna scadenza per lo sponsor (PMK 070726 p.35) | Guida FSB p.7 vs PMK p.35 (OPEN-08) |
| K01 | `kosmos_client_count` — Come si contano i clienti personali (scaglioni, livelli, qualifiche) | `period_buyers` | provisional | `period_buyers` = Clienti distinti con un acquisto confermato nel mese; `cumulative_buyers` = Clienti distinti che hanno acquistato almeno una volta fino a fine mese | Presentazione «Seconda parte» s.6–7: non dice se il conteggio è mensile o cumulativo |
| K02 | `kosmos_extended_stop` — Indirette estese: dove si fermano | `same_or_higher` | client_decided | `same_rank` = Al 5° livello del successivo con la stessa qualifica; `same_or_higher` = Al 5° livello del successivo con qualifica uguale o superiore | Presentazione «Seconda parte» s.8 («fino al 5° livello del Ruby seguente») |
| K04 | `kosmos_own_purchase_as_client` — Gli acquisti dell’incaricato valgono come acquisto di un suo cliente | `on` | client_decided | `on` = Sì: l’incaricato è cliente di sé stesso (conta come cliente per sé stesso e genera compensi); lo sponsor matura come per un cliente di un suo incaricato diretto; `off` = No: gli acquisti personali non generano compensi | Laura 08/10/2026: 5 clienti + acquisto proprio mensile = 6 clienti; deve poterlo disabilitare admin/superadmin |
| K05 | `kosmos_rank_maintenance` — Mantenimento della qualifica | `requirements` | client_decided | `requirements` = Ogni mese si paga la qualifica più alta di cui l’incaricato rispetta ancora tutti i requisiti (clienti, Pearl, colonne, altri incaricati/colonne); `clients` = Ogni mese si paga la qualifica più alta per cui i clienti personali sono sufficienti; `none` = Nessun mantenimento: la qualifica raggiunta resta | Laura 08/10/2026: «mantenere i 6 clienti e quindi la qualifica»; Laura 15/10/2026: retrocede se non rispetta almeno un requisito della qualifica attuale |
| K06 | `kosmos_compression` — Compressione della linea negli indiretti | `skip` | client_decided | `skip` = Salta e scala: chi non rispetta i requisiti (o non può maturare) è saltato; i livelli si contano solo tra chi li rispetta e nessuno incassa due volte sulla stessa vendita; `none` = Nessuna compressione: il livello è la distanza genealogica | Laura 15/10/2026: la linea si comprime e paga il primo che rispetta i requisiti |
| D11 | `refund_after_close` — Reso dopo la chiusura del periodo | `clawback_next_open` | provisional | `clawback_next_open` = Storno nel primo periodo aperto; `no_clawback` = Nessun recupero | M1 §1 (refund) (OPEN-26) |
