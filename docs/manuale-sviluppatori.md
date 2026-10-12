# Manuale per sviluppatori

## Requisiti e installazione
PHP ≥ 8.3, Composer, estensioni standard Laravel. `composer install`, poi `.env` e `php artisan key:generate`.

## Test e stile
```bash
php vendor/bin/phpunit tests/Unit/Engine   # 52 test: carriera, bonus, scenari G, proprietà
vendor/bin/pint                            # stile
```
Pest e Larastan non erano installabili nell'ambiente cloud (download da GitHub bloccato).
Sul tuo PC: `composer require --dev pestphp/pest larastan/larastan`; i test PHPUnit restano validi.

## Usare il motore
```php
$plan  = PlanLoader::prelaunch();                       // piano da config/lamess/*.json
$input = new PeriodInput(PeriodKey::parse('2026-10'), $plan, $nodes, $movements, $prior);
$res   = PeriodCalculator::calculate($input);            // PeriodResult
$res->lines;        // righe con bonus, beneficiario, importo, base, aliquota, spiegazione
$res->inputHash;    // hash canonico dello snapshot
ImpactReport::compare($base, $alt);                      // simulazione "cosa cambia se…"
```
Il motore non tocca il database: costruire `Node`, `Movement`, `PriorState` da DB è compito del livello applicativo (fasi successive).

## Scrivere scenari di test
`tests/Support/Scenario.php`:
```php
$s = (new Scenario)->consultant('A', null, 'DIAMOND')->consultant('P', 'A', 'PEARL');
$s->sale('P', 1000);
$r = $s->run();
```
Gli scenari golden G01–G26 sono in `GoldenTest.php`; invarianti su reti casuali in `PropertyTest.php`.

## Aggiungere o modificare un parametro
- **Valore numerico del piano** (aliquote, PR, premi, struttura): modifica il JSON in `config/lamess/`.
- **Impostazione numerica** (soglie, rate…): chiave in `PlanConfig::SETTINGS` + sezione `settings` del JSON.
- **Nuova politica**: voce in `PolicySpecs::all()` (decisione, default, opzioni, stato, fonte), poi usare `$plan->policies->is('nome','valore')` nel calcolo e un test.
- Rigenera la tabella: `php artisan lamess:parametri --write=docs/parametri.md`.

## Regole di codice
- Mai `float` per denaro/punti: `Num::of()`, `Num::money()`.
- Nessun numero magico nel motore.
- Ogni riga di risultato deve avere spiegazione e decisioni usate.
- Aggiornare `docs/registro-aggiornamenti.md` a ogni passo.

## Documentazione
`php artisan lamess:parametri --write=docs/parametri.md` e `php artisan lamess:manuale` (dossier HTML in `docs/dossier.html`) vanno rilanciati a ogni modifica ai parametri o ai manuali.

## Persistenza e periodi (aggiunta 01/10/2026)
```bash
php artisan migrate --seed --seeder=PrelaunchPlanSeeder   # tabelle + piano ufficiale attivo
php artisan lamess:periodo apri 2026-10                   # apre il periodo con il piano attivo
php artisan lamess:periodo live 2026-10                   # stima (calculation_run "live")
php artisan lamess:periodo runtime 2026-10                # runtime ufficiale → stato "review"
php artisan lamess:periodo chiudi 2026-10                 # checklist + lock righe
```
Nel codice: `PvLedger::record(...)` per i movimenti; `app(PeriodService::class)->calculate($period, 'official')`.
Test con database in `tests/Feature/Periods` (SQLite in memoria, trait `Tests\Support\NetworkDb`).
Nota SQLite: i DECIMAL tornano come float; i modelli usano cast `decimal:2` per avere stringhe.

## Rete e back-office (aggiunta 01/10/2026)
```bash
php artisan lamess:superadmin laura@esempio.it     # super_admin (uno solo)
php artisan lamess:admin-user cliente@esempio.it --preset=admin_finance   # admin con permessi di partenza
php artisan lamess:audit-verifica                  # controlla la catena del log
php artisan serve                                  # http://localhost:8000/admin
```
`APP_LOCALE=it` in `.env` per i testi Filament in italiano. Test: `tests/Feature/Network` (G20, closure, stati, re-iscrizione), `tests/Feature/Admin` (pannello via Livewire).

## F1 completo: FAN, IVD, KYC, tesserino, canone (01/10/2026)
```
App\Domain\Customers     ReferralResolver (link → cookie → codice a mano → consulente predefinito OPEN-20), CustomerService
App\Domain\Onboarding    IvdService (apply → requestSignature → sign → accept|reject), SignatureProvider (+ FakeSignatureProvider),
                          KycService, OnboardingService (missing()), BadgeService, FeeService (process / markPaid), Iban
```
- Cookie `lm_ref` 15 giorni (`lamess.referral_cookie_days`), escluso dalla cifratura in `bootstrap/app.php`.
- Firma: interfaccia `SignatureProvider`; driver `fake` (OTP in cache e mostrato in pagina, max 5 tentativi). Per un provider reale implementare l'interfaccia e registrarla in `AppServiceProvider` (`lamess.signature_driver`).
- Il testo della Lettera IVD è un **segnaposto** in `resources/views/pdf/ivd-letter.blade.php` (versione `ivd_template_version`): sostituirlo con il testo approvato dal Consorzio.
- Parametri in `config/lamess.php`: documenti KYC richiesti, importo e giorni del canone, grazia, promemoria, anni gratuiti.
- Test: `tests/Feature/Onboarding`, `tests/Feature/Admin/OnboardingAdminTest`; helper `Tests\Support\OnboardingDb`.

## Ruoli, 2FA, KPI (02/10/2026)
- `App\Domain\Access\Roles`: matrice ruoli → permessi (`area.azione`), `Roles::sync()` idempotente. Il super_admin passa da `Gate::before`.
- Le risorse Filament usano il trait `GatedByPermission` (`viewPermission()` / `managePermission()`); le azioni usano `->authorize('permesso')`. Nuova risorsa = dichiarare i due permessi.
- `User::canAccessPanel()` richiede un ruolo di `Roles::backoffice()`. Il flag `is_admin` è stato rimosso (la migrazione converte gli esistenti in super_admin).
- 2FA: `AppAuthentication` di Filament con codici di recupero; segreto e codici cifrati a riposo. Obbligatorio con `ADMIN_2FA_REQUIRED` (nei test è `false` in `phpunit.xml`).
- KPI: `App\Domain\Kpi\KpiService::snapshot($periodo)` (sola lettura) alimenta i widget `KpiOverview`, `RankDistributionChart`, `CommissionsByTypeChart` e la pagina `Dashboard` con filtro periodo.
- Non ancora: approvazione a quattro occhi della pubblicazione del piano, gestione utenti da interfaccia.

## Database e produzione (cPanel)
Sviluppo su SQLite, produzione su MySQL/MariaDB. Per rieseguire i test su MariaDB: copia `docs/phpunit.maria.xml.esempio` in `phpunit.maria.xml`, adatta host/utente/password e lancia `php vendor/bin/phpunit -c phpunit.maria.xml` (verificato: 115/115). Installazione passo passo: `docs/installazione-cpanel.md`.

## F2: negozio, ordini, fatture (03/10/2026)
```
App\Domain\Orders    OrderService (place → payByCard|markPaid → ship|cancel), ReturnService (refund), PeriodResolver,
                      PaymentProvider (+ FakePaymentProvider)
App\Domain\Billing   InvoiceService (issueForOrder, issueCreditNote, deliver, retryPending, pdf), DocumentNumbers,
                      InvoiceSink (+ FakeInvoiceSink), SinkResult
```
- **PV solo da eventi di pagamento**: `markPaid` crea (o conferma) un movimento per riga con PV > 0, nel periodo del mese di pagamento (OPEN-25), `external_ref` = `order:{numero}:{riga}` (idempotenza). Bonifico: movimento `pending_payment` creato all'ordine se il periodo del mese è già aperto; la chiusura periodo è bloccata finché esiste (G22).
- **Resi**: `ReturnService::refund` → nota di credito + movimento negativo con `reverses_movement_id`. Periodo originale chiuso → storno nel mese corrente (il motore produce il `clawback`, D11).
- **Beneficiario/tipo punti**: acquirente consulente attivo → PVC a sé; cliente → PVF al consulente assegnato (se non cessato); senza consulente = vendita «casa», nessun PV.
- **Totali**: IVA calcolata per aliquota sul totale imponibile dell'aliquota (`InvoiceService::totals`); fattura = ordine al centesimo (verificato nei test).
- **InvoiceSink**: interfaccia verso il gestionale/SDI. Driver `fake` oggi; per GestionaleK implementare `send()` e registrare il driver in `AppServiceProvider` (`lamess.invoice_sink_driver`). Il PDF è solo copia di cortesia; **non c'è ancora il file FatturaPA XML** (modello di riferimento nel progetto: `IT02507740518_1002t_modellofattura.xml`).
- **Pagamento carta**: interfaccia `PaymentProvider`; driver reale (Stripe/Nexi) da fare. `lamess.payment_driver`.
- Il tempo va letto con `CarbonImmutable::now()` (non `new DateTimeImmutable`) per poter usare `travelTo()` nei test.
- Blade: con Livewire installato **non usare `@continue`/`@break` dentro `@foreach`** (rompe la compilazione); usa `@if`.
- Test: `tests/Feature/Shop` (ordini, fatture e resi, pagine), `tests/Feature/Admin/ShopAdminTest`; helper `Tests\Support\ShopDb`. Suite verificata anche su MariaDB.
- `DemoSeeder` crea prodotti di fantasia, un omaggio 5+1, una tariffa e due ordini demo.

## Prezzi e importazione catalogo (04/10/2026)
- `products.net_price` e i prezzi unitari di righe ordine/fattura/nota di credito hanno **8 decimali**: un listino IVA inclusa (159,90) non è tondo al netto (131,06557377). Come nel modello di fattura di riferimento (`PrezzoUnitario` a 8 decimali), il netto resta esatto e si arrotonda **una sola volta sul totale per aliquota** (`InvoiceService::totals`): 159,90 IVA inclusa torna a 159,90 al centesimo.
- Le righe documento mostrano l'importo di riga arrotondato; la somma delle righe può differire di 1 centesimo dal totale (arrotondamento di riepilogo, come nel formato FatturaPA).
- `App\Domain\Catalog\CatalogImporter` + `lamess:importa-prodotti` + `CatalogSeeder`.
- Un prodotto a prezzo zero non è acquistabile direttamente né compare nel negozio (serve come omaggio).

## Fase 6: pagamenti, abbonamenti, automatismi (05/10/2026)
- `PayoutService`: `generateSettlements`, `preparePayouts($upTo)`, `prepareDue($now)` (automatico), `markPaid`, `cancel`, `markHistorical`, `openBalance`, `situation`. Importi sempre `BigDecimal` da stringa (SQLite restituisce float: casting `(string)`).
- `PeriodAutomation::run($now)`: modalità `lamess.period_auto_close` = `off|calculate|close`; solo il primo mese non chiuso, entro 45 giorni; blocchi scritti una volta nel registro (cache `lamess.period_auto.blocked.*`); ultimo esito in cache `lamess.period_auto.last`.
- Abbonamenti: `SubscriptionService::runDue()` dal comando `lamess:abbonamenti`; ogni ciclo è un ordine normale pagato con carta (`PaymentProvider`); nei test si lega un provider finto prima di risolvere i servizi.
- Ordine per altri: `OrderService::place(..., $forOthers, $subscription)`, destinatari in `recipientsFor`.
- Heartbeat del cron: `Cache::forever('lamess.scheduler.heartbeat')` ogni minuto; widget `SchedulerStatus`.
- Test: `tests/Feature/Payouts/PayoutsTest`, `tests/Feature/Shop/SubscriptionsAndMultiOrdersTest`. Totale 305. Esegui `vendor/bin/pint` prima di consegnare.
- Nuovi permessi `payouts.view` / `payouts.manage`: dopo ogni aggiornamento `php artisan lamess:ruoli`.


## SEPA e avvisi (05/10/2026)
`App\Domain\Payouts\SepaExport::build()` genera pain.001.001.03 con DOM (importi con BigDecimal, niente bcmath). Avvisi: `PayoutService::notifyStatementsReady()` (da `PeriodService::close`) e `notifyConsultant()` per `PayoutPaid`; controllano `lamess.mail_enabled` e il flag, sono best-effort (errori solo nel log). Nuovi parametri: `lamess.sepa.*`, `notify_statement_ready`, `notify_payout_paid`.

## Grafica e traduzioni (05/10/2026)
- CSS statici: `public/css/lamess-tokens.css` (token), `lamess-admin.css` (Filament), `lamess-site.css` (sito e aree). Nessun build.
- Menu admin: `topNavigation()` + `navigationGroup` su ogni risorsa/pagina; gruppi dichiarati in `AdminPanelProvider`.
- `App\Support\StatusLabels`: traduce i codici di stato (colonne `status`, `severity`, `source`…) in tutte le tabelle e schede Filament; un `formatStateUsing` proprio sulla colonna ha la precedenza.
- Pagine di errore: `resources/views/errors/*`.

## Moduli 1-7 (05/10/2026)
```
App\Domain\Plan         PlanVersionService: requestApproval / approve / reject / withdraw (quattro occhi, impostazione plan_four_eyes)
App\Domain\Support      TicketService (open, reply anche interna, close, reopen, assign)
App\Domain\Payouts      StatementLifecycle (estratti, reclami 10 giorni, accettazione tacita), ReceiptService, FiscalCalculator
App\Domain\Wallet       WalletService (registro, saldo = somma), WithdrawalService (richiesta → approvato → pagato)
App\Domain\Content      DownloadService + Watermarker (GD / FPDI), EventService, ReferralQr, RankLookup, Audience
App\Domain\Compliance   ComplianceService (casi, sanzioni con effetti automatici), MaterialService (MA-AAAA-nnnn)
App\Domain\Succession   AccountHolderService::swap (usato da cessione e successione), TransferService, InheritanceService
App\Domain\Billing      FatturaPaBuilder, FileInvoiceSink, MailInvoiceSink (+ InvoiceSink / FakeInvoiceSink)
App\Domain\Orders       StripeCheckoutProvider (RedirectPaymentProvider), StripeWebhook, CheckoutSession
App\Domain\Onboarding   IdentityVerifier (+ NoIdentityVerifier, StripeIdentityVerifier), KycService::startOnline / applyOnlineResult
App\Http\Controllers    WebhookController (un solo endpoint /webhooks/stripe per pagamenti e identità, esente da CSRF)
```
- **Porte e driver** (legati in `AppServiceProvider` da `config('lamess.*_driver')`): `payment_driver` (fake|stripe), `invoice_sink_driver` (fake|file|mail), `kyc_driver` (manual|stripe_identity), `signature_driver` (fake). Un driver nuovo = una classe che implementa l'interfaccia + una riga nel `match`.
- **Pagamento che reindirizza**: `OrderService::usesRedirectCheckout()` e `startCardCheckout()`; il controller manda il cliente alla pagina esterna e la conferma arriva solo da `StripeWebhook::handle` → `markPaid` (idempotente: l'ordine non più `pending_payment` viene ignorato; importo o valuta diversi non confermano).
- **Firma del webhook**: `StripeWebhook::verify` (HMAC-SHA256 su `t.payload`, tolleranza 5 minuti, confronto a tempo costante). Nessuna libreria Stripe: solo `Http` di Laravel, quindi nei test si usa `Http::fake`.
- **Fattura elettronica**: `FatturaPaBuilder::build($doc)` restituisce `['filename','xml']` (FPR12, TD01/TD04), con `XMLWriter`; errori di dati → `BillingException`, che il sink trasforma in `SinkResult(false, …)` e `InvoiceService::deliver` salva in `sink_error`. Le ricevute (`type = receipt`) non si trasmettono. Lo schema ufficiale è in `tests/Fixtures/fatturapa/` (con l'import xmldsig reso locale) e i test validano ogni XML con `DOMDocument::schemaValidate`.
- **Cessione e successione**: una sola funzione cambia l'intestatario senza toccare codice, sponsor, qualifica e referral (`AccountHolderService::swap`); crea una nuova `IvdContract` (`kind` transfer|succession) e sospende il consulente finché `IvdService::accept` non la riattiva (se non ci sono sanzioni di sospensione attive).
- **Sanzioni**: `ComplianceService::sanction` applica l'effetto (blocco compensi = `bonus_hold`, sospensione, cessazione via `NetworkService::terminate`); `lift`/`expireDue` ripristinano senza cancellare il blocco imposto da altre sanzioni (`had_hold_before`).
- **Comandi nuovi**: `lamess:estratti`, `lamess:sanzioni`, `lamess:successioni` (vedi l'elenco con orari nel Manuale amministratore).
- **Permessi**: ogni risorsa Filament dichiara `viewPermission()` / `managePermission()` (trait `GatedByPermission`); aggiungere un permesso = voce in `Roles::PERMISSIONS`, eventuale riga in `Roles::MATRIX`, poi `php artisan lamess:ruoli`.
- **Test**: 451 (1 saltato), tutti su SQLite in memoria. Nuove cartelle: `tests/Feature/{Support,Content,Compliance,Succession,Drivers}`; i driver si provano con `Http::fake`, `Mail::fake`, `Storage::fake`.
- **Insidie incontrate**: i parametri delle closure Filament si risolvono per nome (`$state`, `$search`, `$value`: altri nomi rompono la validazione dei `Select`); `bcmath` può mancare sull'hosting, quindi niente `bcadd` (si usa `Num`); l'ordine conserva i dati cliente in `billing` (campo `name`, più `first_name`/`last_name` dagli ordini nuovi); `pint --dirty` richiede git: senza git lancia `vendor/bin/pint app tests config routes`.
- **Consegna**: lo ZIP di produzione si costruisce con `tools/crea-pacchetto-cpanel.php` (esclude tests, `.env`, `CLAUDE.md`, `AGENTS.md`, la cache di bootstrap e il `vendor` di sviluppo). Il `vendor` locale con `composer install` completo non va caricato sul server.
