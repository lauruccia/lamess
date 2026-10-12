# Architettura

## Livelli

```
App\Domain\Engine         puro PHP, nessuna dipendenza da Laravel/DB
  Support/   Num (BigDecimal), PeriodKey (mese solare Europe/Rome), Canonical (hash input)
  Policy/    PolicySpecs (registro D01–D12), Policies (valori validati)
  Plan/      PlanConfig, RankDef, StructureReq, FsbStepDef, PlanLoader
  Model/     Node, Movement, PriorState, PeriodInput → Line, PartnerStat, PeriodResult
  Calc/      Tree, CareerState, Activity, DirectTeam, Matching, RankBonus, Clawback,
             PeriodCalculator (orchestratore), ImpactReport (simulazioni)
config/lamess/plan-prelaunch-2026-07-07.json   piano ufficiale (modificabile)
```

Sopra al motore ci sono persistenza (Eloquent + migrazioni), servizi applicativi (periodi, ordini, fatture, pagamenti, abbonamenti) e le interfacce (back-office Filament e area del consulente): vedi le sezioni seguenti.

## Flusso di un calcolo

`PeriodInput` (snapshot) → `PeriodCalculator::calculate()` → `PeriodResult`.

1. Validazione movimenti (duplicati, fuori periodo, titolare sconosciuto).
2. Punti e carriera: PR, regola del 50% per linea, struttura, promozioni multiple.
3. Stato attivo e qualifica «pagata come».
4. Direct/Team (differenziale con compressione) oppure Clawback per resi di periodi chiusi.
5. Matching (6 livelli compressi sugli attivi).
6. Rank Bonus (rateizzato oltre soglia; trattenuta/perdita per inattivi).
7. Risultati con spiegazione per riga, decisioni usate e hash canonico dell'input.

Stesso input ⇒ stesso risultato e stesso hash (riproducibilità e audit).

## Scelte

- Id normalizzati a stringa; periodo = mese solare in `Europe/Rome`.
- Percentuali nel JSON come punti percentuali (`"18"` = 18%).
- Decimali: `brick/math`, arrotondamento `HalfUp`; rate: l'ultima assorbe la differenza.
- Pest/Larastan non installabili nell'ambiente di sviluppo cloud (download bloccato): test con PHPUnit.

## Persistenza (aggiunta 01/10/2026)

```
App\Models                 Consultant, CompPlanVersion, Period, PvMovement, CalculationRun,
                           CommissionLine, ConsultantPeriodStat, RankAchievement, RankAward, RankBonusInstallment
App\Domain\Ledger          PvLedger  → unico punto di scrittura dei movimenti PV
App\Domain\Periods         SnapshotBuilder (DB → PeriodInput), PeriodService (apri / calcola / chiudi),
                           CommissionLineLocker, PeriodStateException
```

- Il piano è salvato in `comp_plan_versions.data` (JSON identico a `config/lamess/*.json`); il seeder `PrelaunchPlanSeeder` carica il piano ufficiale.
- **Calcolo live** (stima) e **runtime ufficiale** creano un `calculation_run` con righe (`commission_lines`, stato `draft`) e statistiche per consulente; rilanciarli sostituisce il run precedente dello stesso tipo (idempotenza).
- **Chiusura**: richiede runtime ufficiale, nessun movimento `pending_payment`, dati invariati (hash dell'input uguale). Poi: righe `locked`, qualifiche e premi Rank Bonus scritti (`rank_achievements`, `rank_awards`, `rank_bonus_installments`), periodo `closed`.
- Periodo chiuso immutabile: calcolo e nuovi movimenti sollevano `PeriodStateException`. I resi su periodi chiusi si registrano nel periodo aperto come movimento negativo con `reverses_movement_id` → il motore produce lo storno (clawback).
- Stato precedente (PR di apertura, attività del mese precedente, prima soglia, qualifiche, rate) si legge dall'ultimo periodo chiuso: così ogni periodo è ricalcolabile in modo riproducibile.
- Lock per periodo con `Cache::lock("period:{id}")` (in produzione: Redis).
- Rate del Rank Bonus: la prima rata è `paid` se matura nel periodo di conseguimento, le altre `due` con scadenza mensile; una rata trattenuta (`held`) non viene riproposta automaticamente.

## Rete e anagrafica (F1, 01/10/2026)

`App\Domain\Network\NetworkService`:
- `enroll()` — iscrizione con sponsor; blocca sponsor non attivi e re-iscrizioni prima di `lamess.reenrollment_days` (180, in `config/lamess.php` / `.env`) dalla cessazione della stessa persona (`person_key` = codice fiscale).
- `changeStatus()` — transizioni ammesse: `pending→active|terminated`, `active→suspended|terminated`, `suspended→active|terminated`; `terminated` è definitivo. Ogni cambio è in `consultant_status_changes`.
- `terminate()` — i diretti passano al primo ascendente con contratto in essere (Reg. 2.8): `sponsor_id` aggiornato, `original_sponsor_id` invariato, riga in `sponsor_history` (motivo `compression`), closure table ricostruita.
- Lo sponsor non è modificabile (Reg. 2.10.a): il modello solleva `NetworkException` se `sponsor_id` cambia fuori da `NetworkService`.
- `network_paths` (closure table: ancestor, descendant, depth, leg_root) aggiornata all'iscrizione e ricostruita alla cessazione (ricostruzione completa: da ottimizzare su reti molto grandi).
- Il motore applica comunque la trasparenza dei cessati (G20): anche con dati importati non ricollegati il risultato non cambia.

## Back-office (Filament 4, `/admin`)
Risorse: Piano compensi, Periodi, Movimenti PV, Consulenti. Tutte le scritture passano dai servizi di dominio (`PeriodService`, `PvLedger`, `NetworkService`), mai da CRUD diretto.
Accesso: ruoli spatie/permission + 2FA (vedi manuale sviluppatori, «Ruoli, 2FA, KPI»). Risorse anche: Contratti IVD, Documenti KYC, Canoni, Clienti; cruscotto KPI.
Nota per chi scrive azioni Filament: i parametri delle closure si risolvono **per nome** (`$state`, `$record`, `$data`, `$search`, `$value`).

## Fase 6: chiusura, liquidazioni, pagamenti, abbonamenti (05/10/2026)
```
App\Domain\Periods     PeriodService (apri / calcola / chiudi / riapri), PeriodAutomation (chiusura mensile automatica)
App\Domain\Payouts     PayoutService (liquidazioni, pagamenti, storico), StatementService (statino + PDF), ExportService (CSV)
App\Domain\Subscriptions SubscriptionService (abbonamenti: cicli, tentativi, pausa, disdetta)
App\Domain\Orders      OrderService (ordine per altri, spedizione gratuita con abbonamento)
```
- **Tabelle**: `settlements` (per consulente e mese: maturato, trattenuto, stato), `payouts` (IBAN fotografato al momento della preparazione), `subscriptions`; `periods.payouts_prepared_at`; `orders.subscription_id` e `shipping_waived`; `order_items.recipient_user_id`, `beneficiary_consultant_id`, `pv_kind`.
- **Flusso**: chiusura del periodo → liquidazioni (dalle righe bloccate del run ufficiale) → pagamenti «da pagare» (somma delle liquidazioni aperte fino a quel mese, soglia minima, KYC e IBAN) → bonifico fuori dal portale → «pagato».
- **Operazioni pianificate** (richiedono `schedule:run` ogni minuto): `lamess:canoni` 03:30 · `lamess:abbonamenti` 04:00 · `lamess:pagamenti` 05:00 · `lamess:chiusura-mese` 01:00 · `lamess:fatture` ogni ora · piano in vigore 00:10 · battito del cron ogni minuto (mostrato in dashboard).
- Le liquidazioni pagate o già legate a un pagamento non vengono mai riscritte.
- La riapertura di un mese annulla liquidazioni e pagamenti non pagati e azzera `payouts_prepared_at`.

## Moduli 1-7 (05/10/2026)
Oltre ai servizi della fase 6 esistono: `Plan` (versioni e quattro occhi), `Support` (ticket), `Wallet`, `Content` (novità, materiali, eventi), `Compliance` (casi, sanzioni, materiali), `Succession` (cessione ed eredi). Le integrazioni esterne passano da **porte** (interfacce) con driver scelti da configurazione:

| Porta | Driver | Uso |
|---|---|---|
| `PaymentProvider` / `RedirectPaymentProvider` | `fake`, `stripe` | carta; con Stripe il pagamento si conferma dal webhook |
| `InvoiceSink` | `fake`, `file`, `mail` | consegna dell'XML FatturaPA |
| `IdentityVerifier` | `manual`, `stripe_identity` | verifica d'identità online |
| `SignatureProvider` | `fake` | firma della lettera (provider reale ancora da scegliere) |

L'unico punto d'ingresso dei provider è `POST /webhooks/stripe` (firma verificata, esente da CSRF). Nessuna chiamata di rete parte dai test: i driver usano `Http::fake`.
La cessione e la successione condividono `AccountHolderService::swap`; le sanzioni agiscono su stato e blocco compensi tramite `NetworkService` e i flag del consulente, senza toccare i periodi chiusi.

