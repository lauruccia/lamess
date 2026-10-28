# Architettura

Aggiornata al 14 ottobre 2026 sul codice. Per la cronologia dei passi vedi `registro-aggiornamenti.md`.

## Terminologia e nomi tecnici

Nel prodotto (schermate, email, manuali) si dice **Incaricato/Incaricati**; nel codice e nel database restano i nomi tecnici storici. Non rinominarli: toccherebbe migrazioni, permessi e dati.

| Prodotto | Codice / database |
|---|---|
| Incaricato | modello `App\Models\Consultant`, tabella `consultants`, colonne `consultant_id`, `sponsor_id`; ruolo del portale `consultant`; `App\Domain\Consultants\Consultant360` (scheda 360), `ConsultantResource`, `ConsultantList`, `ConsultantLevelsPage` |
| Qualifica d'ingresso «Incaricato» | codice `CONSULTANT` (nome mostrato «Incaricato»); le altre: `PEARL`, `CRYSTAL`, `SAPPHIRE`, `RUBY`, `EMERALD`, `DIAMOND` (il nome è solo la pietra) |
| Piano Incaricati | motore `kosmos_v1`: `KosmosConfig`, `KosmosCalculator`, sezione `kosmos` del JSON del piano; politiche K01–K06 |
| Piano precedente (nascondibile) | motore `multilevel_v3` (a PR/PV); si nasconde con `App\Domain\Plan\LegacyPlan` |
| Codice incaricato | `consultants.code`, prefisso `lamess.consultant_code_prefix` |
| Permessi | `area.azione` (`network.view`, `network.restructure`, `plan.manage`…): la sigla non cambia con la terminologia |

`PlanConfig::fromArray()` toglie la vecchia dicitura «Consulente» dai nomi delle qualifiche dei piani salvati prima del cambio.

## Livelli

```
App\Domain\Engine         puro PHP, nessuna dipendenza da Laravel/DB
  Support/   Num (BigDecimal), PeriodKey (mese solare Europe/Rome), Canonical (hash input)
  Policy/    PolicySpecs (registro D01–D12 e K01–K06), Policies (valori validati)
  Plan/      PlanConfig, RankDef, StructureReq, FsbStepDef, KosmosConfig (piano Incaricati), PlanLoader
  Model/     Node, Movement, PriorState, PeriodInput → Line, PartnerStat, PeriodResult (+ AchievedRank, RankAwardResult…)
  Calc/      Tree, CareerState, BonusContext, PeriodCalculator (orchestratore), ImpactReport (simulazioni)
             Piano Incaricati:    KosmosCalculator
             Piano precedente:    Activity, DirectTeamCalculator, MatchingCalculator, RankBonusCalculator, FsbCalculator
             Comune:              ClawbackCalculator
config/lamess/plan-incaricati-2026-10-08.json   modello del Piano Incaricati (da cui si crea la bozza)
config/lamess/plan-prelaunch-2026-07-07.json    modello del piano precedente (caricato dall'installazione guidata)
```

Sopra al motore ci sono persistenza (Eloquent + migrazioni), servizi applicativi (periodi, ordini, fatture, pagamenti, abbonamenti) e le interfacce (back-office Filament e area dell'incaricato): vedi le sezioni seguenti.

## Flusso di un calcolo

`PeriodInput` (snapshot) → `PeriodCalculator::calculate()` → `PeriodResult`. Lo snapshot lo costruisce `SnapshotBuilder` dal database; per il Piano Incaricati porta anche `customerFirstPurchase` e `customerCoverage` (clienti e mesi di validità), `rankHoldUntil` per incaricato e l'`activation_date` della versione del piano.

Piano Incaricati (`kosmos_v1`), in ordine:
1. Validazione movimenti (duplicati, fuori periodo, titolare sconosciuto).
2. Clienti personali per incaricato (regola cliente del piano, K01, K04).
3. Carriera: qualifiche per clienti, Pearl al 1° livello, struttura, colonne; valgono appena raggiunte.
4. Qualifica pagata con mantenimento (K05, protezione del piano, `rank_hold_until`).
5. Compensi diretti, indiretti (5 livelli), indiretti estesi, Bonus di Struttura; storni per i resi.
6. Risultati con spiegazione per riga, decisioni usate e hash canonico dell'input.

Piano precedente (`multilevel_v3`): validazione → punti e carriera (PR, regola del 50%, struttura) → stato attivo e qualifica «pagata come» → Direct/Team (differenziale con compressione) o Clawback → Matching → Rank Bonus (rateizzato oltre soglia) → Fast Start Bonus → risultati.

Stesso input ⇒ stesso risultato e stesso hash (riproducibilità e audit). Dettagli delle regole: `specifica-motore.md`.

## Scelte

- Id normalizzati a stringa; periodo = mese solare in `Europe/Rome`.
- Percentuali nel JSON come punti percentuali (`"18"` = 18%).
- Decimali: `brick/math`, arrotondamento `HalfUp`; rate: l'ultima assorbe la differenza. Niente `float` per il denaro.
- I campi opzionali nuovi dell'input (importo, V.A.P., clienti, data di mantenimento…) sono esclusi dall'hash quando vuoti (`Canonical::OPTIONAL_WHEN_EMPTY`), così gli hash dei calcoli esistenti non cambiano.
- Test con PHPUnit (non Pest).

## Persistenza

```
App\Models                 Consultant, CompPlanVersion, Period, PvMovement, CalculationRun,
                           CommissionLine, ConsultantPeriodStat, RankAchievement, RankAward, RankBonusInstallment,
                           StructureBonus (registro settimanale del Bonus di Struttura)
App\Domain\Ledger          PvLedger  → unico punto di scrittura dei movimenti PV
App\Domain\Periods         SnapshotBuilder (DB → PeriodInput), PeriodService (apri / calcola / chiudi / riapri),
                           PeriodAutomation (chiusura mensile automatica), NightlyRecalculation (ricalcolo notturno),
                           CommissionLineLocker, PeriodStateException
```

- Il piano è salvato in `comp_plan_versions.data` (JSON identico a `config/lamess/*.json`); `CompPlanVersion::plan()` aggiunge `kosmos.activation_date` (data di efficacia o di attivazione). L'installazione guidata carica il piano di pre-lancio (`PrelaunchPlanSeeder`); il **Piano Incaricati** si crea poi da Piano compensi («Crea bozza “Piano Incaricati”»).
- **Calcolo live** (stima) e **runtime ufficiale** creano un `calculation_run` con righe (`commission_lines`, stato `draft`) e statistiche per incaricato; rilanciarli sostituisce il run precedente dello stesso tipo (idempotenza). Le righe `structure` non entrano nelle righe mensili: hanno il loro registro (`structure_bonuses`).
- **Movimenti** (`pv_movements`): oltre a PV/CV hanno `amount` (euro IVA inclusa), `vap_amount`, `validity_months`, `client_count`, `order_id`, `order_item_id`. Sono scritti da `OrderService` all'incasso (un movimento per riga con PV > 0) tramite `PvLedger`.
- **Qualifiche**: `rank_achievements` (`source` = `calculated` | `forced`…). Con il Piano Incaricati il ricalcolo notturno e la chiusura le riallineano (`PeriodService::syncCalculatedRanks`); premi e rate del Rank Bonus esistono solo nel piano precedente.
- **Chiusura**: prima annulla in automatico gli ordini in attesa di pagamento (`cancelPendingOrders`); richiede runtime ufficiale, nessun movimento `pending_payment` rimasto, dati invariati (hash dell'input uguale). Poi: righe `locked`, qualifiche scritte, periodo `closed`.
- Periodo chiuso immutabile: calcolo e nuovi movimenti sollevano `PeriodStateException`. I resi su periodi chiusi si registrano nel periodo aperto come movimento negativo con `reverses_movement_id` → il motore produce lo storno (clawback).
- Stato precedente (qualifiche, rate, attività del piano precedente) si legge dall'ultimo periodo chiuso: così ogni periodo è ricalcolabile in modo riproducibile.
- Lock per periodo con `Cache::lock("period:{id}")`. Su cPanel la cache è su file (`CACHE_STORE=file`), che supporta i lock.
- Rate del Rank Bonus (solo piano precedente): la prima rata è `paid` se matura nel periodo di conseguimento, le altre `due` con scadenza mensile; una rata trattenuta (`held`) non viene riproposta automaticamente.
- **Mantenimento della qualifica per incaricato**: colonne `consultants.rank_hold_until` e `rank_hold_reason` (migrazione `2026_11_04_100000_consultants_rank_hold_until`), scritte solo da `App\Filament\Support\RankHoldAction` (azione condivisa tra scheda, elenco, azione di massa e «tutti i registrati»; permesso `network.restructure`; funzionalità `consultant360.action.hold_rank`; log di audit `rank.hold` e `rank.hold_removed`). `SnapshotBuilder` le passa al motore come `Node::rankHoldUntil`.

## Rete e anagrafica

`App\Domain\Network\NetworkService`:
- `enroll()` — iscrizione con sponsor; blocca sponsor non attivi e re-iscrizioni prima di `lamess.reenrollment_days` (180, in `config/lamess.php` / `.env`) dalla cessazione della stessa persona (`person_key` = codice fiscale).
- `changeStatus()` — transizioni ammesse: `pending→active|terminated`, `active→suspended|terminated`, `suspended→active|terminated`; `terminated` è definitivo. Ogni cambio è in `consultant_status_changes`.
- `terminate()` — i diretti passano al primo ascendente con contratto in essere (Reg. 2.8): `sponsor_id` aggiornato, `original_sponsor_id` invariato, riga in `sponsor_history` (motivo `compression`), closure table ricostruita.
- Lo sponsor non è modificabile (Reg. 2.10.a): il modello solleva `NetworkException` se `sponsor_id` cambia fuori da `NetworkService`/`TreeOperations`.
- `network_paths` (closure table: ancestor, descendant, depth, leg_root) aggiornata all'iscrizione e ricostruita alla cessazione (ricostruzione completa: da ottimizzare su reti molto grandi).
- Il motore applica comunque la trasparenza dei cessati: anche con dati importati non ricollegati il risultato non cambia.

Altri servizi di rete: `TreeOperations` (sposta ramo / cambia sponsor con anteprima d'impatto, riassegnazione clienti, qualifica forzata; permesso `network.restructure`, ogni operazione con motivo e log), `ConsultantConversion` (da incaricato a cliente e viceversa), `LevelList` (elenco per livelli: incaricati in ordine di ramo con rientro, limite di livelli, filtro per stato, colonna «Hanno acquistato» = clienti assegnati con almeno un ordine pagato nel periodo scelto; `Order::PAID_STATES`; limite 3000 righe), `Career\CareerProgress` («cosa manca per il livello successivo», legge l'ultimo calcolo), `Plan\RankComparison` (confronto qualifica precedente / nuovo piano, sola lettura).

## Back-office (Filament 4, `/admin`)

Menu in alto con gruppi Rete, Compensi, Vendite, Assistenza, Sistema (`AdminPanelProvider`). Risorse: incaricati, clienti, contratti IVD, documenti KYC, canoni, cessioni, successioni; piano compensi, periodi, movimenti PV, liquidazioni, pagamenti, ricevute, Bonus di Struttura, wallet e prelievi; ordini, fatture, note di credito, prodotti, categorie, sconti, bundle, tariffe di spedizione, abbonamenti; ticket, casi di conformità, sanzioni, materiali, eventi, novità, download; riconciliazioni Odoo, log di audit, utenti. Pagine: Cruscotto (KPI), Albero della rete, Elenco per livelli, Confronto qualifiche, Visibilità piano precedente, Cruscotto vendite, Statistiche sito, Pagamenti e spedizioni, Impostazioni di sistema, Funzionalità e viste, Backup, Controllo messa online, Verifica del log.

- Le scritture passano dai servizi di dominio (`PeriodService`, `PvLedger`, `NetworkService`, `OrderService`…). Unica eccezione voluta: le **modifiche dirette del super_admin** (`SuperadminEditRecord`), che scrivono nel log di audit i valori prima e dopo.
- **Form generici** — `App\Filament\Support\GenericForm` costruisce il modulo di `SuperadminEditRecord` dalla struttura della tabella: nasconde chiavi, timestamp, campi `hidden` del modello e colonne con `secret`/`token`/`hash`; etichette italiane da `GenericFormVocabulary`; riferimenti ad altri record (utente, incaricato, ordine, prodotto…) come menu con ricerca; stati e tipi noti come menu in italiano; date nel fuso di Roma; suffissi €/%; i campi JSON diventano tabella chiave/valore, elenco di etichette, oppure **righe prodotto/quantità** per le colonne con forma nota (`subscriptions.lines`, `subscriptions.for_others`), che **non sono obbligatorie**; solo le strutture complesse restano testo JSON.
- **Accesso**: ruoli spatie/permission + 2FA. `Roles` definisce permessi e profili; l'admin non ha permessi di ruolo: li concede il super_admin utente per utente.
- **Funzionalità e viste** — `Feature::enabled()` legge l'elenco chiuso di `FeatureRegistry` (tab, viste, pulsanti, widget) e le regole per tutti/ruolo/utente. Le funzionalità `legacy` sono spente per tutti, super_admin compreso, quando il piano precedente è nascosto.
- Nota per chi scrive azioni Filament: i parametri delle closure si risolvono **per nome** (`$state`, `$record`, `$data`, `$search`, `$value`).

## Ordini, pagamenti, abbonamenti

```
App\Domain\Orders         OrderService (place → payByCard | payByKMoney | markPaid → ship | cancel), CartPricer / PricedCart / PricedLine,
                          PendingCart, ReturnService, ReturnRequestService, RefundService, PeriodResolver,
                          PaymentProvider (+ FakePaymentProvider, RedirectPaymentProvider, StripeCheckoutProvider, StripeWebhook),
                          KMoneyProvider (+ FakeKMoneyProvider, KMoneyApiProvider, DisabledKMoneyProvider, KMoneySubscriptionSync, PartialKMoneyRefund)
App\Domain\Subscriptions  SubscriptionService (cicli, tentativi, pausa, disdetta; carta o KMoney)
App\Domain\Customers      ReferralResolver, CustomerService, ShippingAddressBook (fino a 10 indirizzi per utente, `lamess.max_shipping_addresses`)
App\Domain\Billing        InvoiceService, FatturaPaBuilder, InvoiceSink (+ Fake / File / Mail), DocumentNumbers
```

- **Carrello in sessione**: `PendingCart` ricorda l'ordine registrato ma non ancora pagato (`cart_pending_order`) e svuota il carrello (`cart`, `cart_others`, `cart_codes`) solo quando l'ordine risulta pagato; «torna al carrello» dalla pagina esterna non lo perde. Gli ordini in attesa vecchi si annullano al nuovo ordine.
- **Prezzi IVA inclusa** in frontend e backend (cliente e admin; dell'IVA si mostra solo l'aliquota). In database restano `net_price` (8 decimali) e `vat_rate`: l'IVA inclusa è `Product::grossPrice()` / `OrderService::grossUnit()`; nel modulo prodotto si digita il prezzo IVA esclusa e l'IVA inclusa si ricava da sola (`ProductResource::grossFromNet`). Il totale fattura si arrotonda una sola volta per aliquota.
- **Abbonamenti**: `Order::subscription()` collega l'ordine all'abbonamento da cui nasce (badge «Tipo ordine», filtro nell'elenco ordini). I nuovi abbonamenti partono con `commitment_months` = 12, **non vincolante**, e spedizione sempre gratuita (`free_shipping`); non esiste più l'impegno minimo configurabile.
- **KMoney**: porta `KMoneyProvider`, driver scelto da `lamess.kmoney_driver`: `fake` (sviluppo), `api` (`KMoneyApiProvider`: OAuth2 + PKCE e mandato di pagamento; abilitato solo con base URL, client id/secret e conto venditore), altro = `DisabledKMoneyProvider`. Il collegamento del cliente è `KMoneyLink` (token cifrati, `mandate_uuid`, `account_number`); il pannello «Conto KMoney» sta nel profilo (`/area/profilo#kmoney`). Rotte: `/area/kmoney/collega|ritorno|mandato|scollega` e webhook firmato `POST /webhooks/kmoney`. Il resto dell'ordine non coperto dalla % KMoney dei prodotti si paga con carta o bonifico (`orders.kmoney_amount`, `kmoney_rest_method`). `lamess:abbonamenti-kmoney` rimanda a KMoney la copia degli abbonamenti pagati con KMoney (non è nello scheduler).

## Chiusura, liquidazioni, pagamenti

```
App\Domain\Payouts     PayoutService (liquidazioni, pagamenti, storico), StatementService (statino + PDF), ExportService (CSV),
                       StatementLifecycle (estratti, reclami), ReceiptService, FiscalCalculator, SepaExport, StructureBonusService
```

- **Tabelle**: `settlements` (per incaricato e mese: maturato, trattenuto, stato), `payouts` (IBAN fotografato al momento della preparazione), `subscriptions`; `periods.payouts_prepared_at`; `orders.subscription_id` e `shipping_waived`; `order_items.recipient_user_id`, `beneficiary_consultant_id`, `pv_kind`.
- **Flusso**: chiusura del periodo → liquidazioni (dalle righe bloccate del run ufficiale) → pagamenti «da pagare» (somma delle liquidazioni aperte fino a quel mese, soglia minima, KYC e IBAN) → bonifico fuori dal portale → «pagato». Le ricevute e la ritenuta d'acconto sono spente di default (`lamess.fiscal.*`).
- Le liquidazioni pagate o già legate a un pagamento non vengono mai riscritte. La riapertura di un mese annulla liquidazioni e pagamenti non pagati e azzera `payouts_prepared_at`.

### Operazioni pianificate
Richiedono `schedule:run` ogni minuto (cron di cPanel); orari Europa/Roma, definiti in `routes/console.php`:
`lamess:sanzioni` 00:20 · `lamess:successioni` 00:30 · piano in vigore 00:10 · backup 02:15 · `lamess:ricalcolo-notturno` 02:45 · `lamess:canoni` 03:30 · `lamess:abbonamenti` 04:00 · `lamess:pagamenti` 05:00 · `lamess:estratti` 06:00 · `lamess:kyc-promemoria` 08:00 · il 1° del mese 08:30 `lamess:fatture --tutte` (solo con invio fatture «in blocco») · ogni ora `lamess:fatture`, `lamess:chiusura-mese` (agisce solo nel giorno e all'ora scelti) e `lamess:bonus-struttura` (solo se attivo, nel giorno e all'ora scelti) · battito del cron ogni minuto (mostrato in dashboard) · ogni minuto `lamess:coda` (esegue la coda delle email, solo se `QUEUE_CONNECTION` non è `sync`). Tutti i comandi, tranne il battito, usano `withoutOverlapping(120)`: se un giro dura troppo il successivo non parte in parallelo.

## Altri moduli
`Plan` (versioni, quattro occhi, `LegacyPlan`, `ActiveStatus`, `PlanFormMapper`, `RankComparison`), `Features`, `Support` (ticket), `Wallet`, `Content` (novità, materiali, eventi, QR), `Compliance` (casi, sanzioni, materiali), `Succession` (cessione ed eredi), `Import` (Odoo), `Audit` (log a catena di hash), `Settings` (`SystemSettings`: impostazioni del pannello che sovrascrivono `config('lamess.*')`), `System` (backup, controllo messa online).

Le integrazioni esterne passano da **porte** (interfacce) con driver scelti da configurazione:

| Porta | Driver | Uso |
|---|---|---|
| `PaymentProvider` / `RedirectPaymentProvider` | `fake`, `stripe` | carta; con Stripe il pagamento si conferma dal webhook |
| `KMoneyProvider` | `fake`, `api`, `off` | pagamento e abbonamenti con KMoney (mandato del cliente) |
| `InvoiceSink` | `fake`, `file`, `mail` | consegna dell'XML FatturaPA |
| `IdentityVerifier` | `manual`, `stripe_identity` | verifica d'identità online |
| `SignatureProvider` | `fake` | firma della lettera (provider reale ancora da scegliere) |

Punti d'ingresso dei provider: `POST /webhooks/stripe` e `POST /webhooks/kmoney` (firma verificata, esenti da CSRF). Nessuna chiamata di rete parte dai test: i driver usano `Http::fake`. La cessione e la successione condividono `AccountHolderService::swap`; le sanzioni agiscono su stato e blocco compensi tramite `NetworkService` e i flag dell'incaricato, senza toccare i periodi chiusi.

## Hosting e rilascio

L'applicazione sta **fuori** dalla cartella pubblica (`$HOME/lamess_app`), in `public_html` va solo il contenuto di `public/`. Il codice arriva da GitHub (`lauruccia/lamess`, branch `main`) con cPanel → Git Version Control e `.cpanel.yml`; il sito live **non ha terminale**. Il deploy non lancia `migrate` né `lamess:ruoli`. Cosa si fa in sviluppo e cosa su cPanel: vedi «Manuale per sviluppatori», sezione 3, e `installazione-cpanel.md`.

## Accesso come e etichette
Vedi manuale sviluppatori: `Domain/Access/Impersonation`, `Domain/Orders/ShippingLabelService`, `Domain/Customers/BillingData`.
