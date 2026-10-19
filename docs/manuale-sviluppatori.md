# Manuale per sviluppatori

Aggiornato al 14 ottobre 2026 sul codice. Cronologia di ogni passo: `registro-aggiornamenti.md`. Regole del motore: `specifica-motore.md`; valori: `parametri.md`; struttura: `architettura.md`; installazione e aggiornamento del sito: `installazione-cpanel.md` e `messa-online.md`.

## 1. Stack e terminologia

PHP 8.3, **Laravel 13**, **Filament 4** (back-office `/admin`), spatie/laravel-permission, brick/math, dompdf, setasign/fpdi, **PHPUnit 12** (non Pest), Pint. Convenzioni del repository in `CLAUDE.md`.

Nel prodotto si dice **Incaricato/Incaricati**; nel codice restano i nomi tecnici `Consultant`, `consultants`, `consultant_id`, il ruolo `consultant` e il codice di qualifica `CONSULTANT` (nome mostrato «Incaricato»). Non rinominarli. La qualifica è **solo il nome della pietra** (Pearl, Crystal, Sapphire, Ruby, Emerald, Diamond) e vale appena raggiunta. Tabella dei nomi tecnici: «Architettura», prima sezione.

Il portale ha due motori: il **Piano Incaricati** (`kosmos_v1`, in uso) e il **piano precedente (nascondibile)** (`multilevel_v3`, a PR/PV). Vedi `specifica-motore.md`.

## 2. Sviluppo in locale

```bash
composer install
cp .env.example .env && php artisan key:generate     # SQLite di default
php artisan migrate --seed --seeder=PrelaunchPlanSeeder   # tabelle + piano di pre-lancio attivo
php artisan lamess:superadmin laura@esempio.it       # super_admin (uno solo)
php artisan serve                                    # http://localhost:8000/admin
```
`APP_LOCALE=it` per i testi Filament in italiano. `LAMESS_KMONEY_DRIVER=fake`, `LAMESS_PAYMENT_DRIVER=fake`, firma `fake`: nessuna chiamata esterna. `DemoSeeder` crea prodotti di fantasia, un omaggio 5+1, una tariffa e due ordini demo (**mai in produzione**).

Il **Piano Incaricati** non è caricato dal seeder: si crea da `/admin` → Compensi → Piano compensi → «Crea bozza “Piano Incaricati”» (parte da `config/lamess/plan-incaricati-2026-10-08.json`), si rivede, si simula e si pubblica.

### Test e stile
```bash
php artisan test --compact                          # suite completa
php artisan test --compact --filter=KosmosPlanTest  # un test o una classe
php vendor/bin/phpunit tests/Unit/Engine            # solo il motore
vendor/bin/pint --dirty --format agent              # stile (richiede git)
vendor/bin/pint --format agent app tests config routes   # senza git
```
Test con database su SQLite in memoria (`phpunit.xml`); i trait di aiuto sono `Tests\Support\NetworkDb`, `OnboardingDb`, `ShopDb`. SQLite restituisce i DECIMAL come float: i modelli usano cast `decimal:2` per avere stringhe. Per rieseguire i test su MariaDB: copia `docs/phpunit.maria.xml.esempio` in `phpunit.maria.xml`, adatta host/utente/password e lancia `php vendor/bin/phpunit -c phpunit.maria.xml`; l'ultima verifica documentata su MariaDB risale al 5 ottobre, da ripetere per le migrazioni successive. Nei test i driver esterni si provano con `Http::fake`, `Mail::fake`, `Storage::fake`. Un test lanciato dalla riga di comando può lasciare `storage/framework/cache` e `storage/framework/views`: non vanno committati (sono ignorati da git).

## 3. Sviluppo e sito live: cosa si fa dove

Il flusso: si sviluppa sul clone di `lauruccia/lamess`, branch `main`; commit e push; il **deploy** parte da cPanel → *Git Version Control* → **Update from Remote**, poi **Deploy HEAD Commit** (lo esegue `.cpanel.yml`). Il sito live **non ha terminale**: solo cPanel, phpMyAdmin e File Manager. Un comando `php artisan …` sul live non si può lanciare.

| Operazione | In sviluppo (terminale) | Sul sito live (cPanel / phpMyAdmin / File Manager) |
|---|---|---|
| Codice | `git commit` + `git push origin main` | Git Version Control: *Update from Remote* + *Deploy HEAD Commit*. `.cpanel.yml` copia l'app in `$HOME/lamess_app` (senza `.git`, `.env`, `storage`, `vendor`, `tests`, `docs`), installa `composer install --no-dev --no-scripts`, copia `public/` in `public_html` e corregge i percorsi di `index.php`; poi, se `vendor` e `.env` esistono, lancia `package:discover` e `optimize:clear` |
| Librerie | `composer install` / `composer update` | le fa il deploy; se `vendor` manca il registro del deploy dice «ATTENZIONE vendor mancante» |
| Migrazioni | `php artisan migrate` (SQL anteprima: `php artisan migrate --pretend`) | **il deploy non le lancia** e non esiste una pagina che lo faccia (OPEN-50). Procedura in uso (vedi `installazione-cpanel.md`, A3): backup, poi in phpMyAdmin l'SQL delle migrazioni nuove e la riga corrispondente nella tabella `migrations` |
| Ruoli e permessi nuovi | `php artisan lamess:ruoli` | non si può lanciare. Il super_admin passa comunque da `Gate::before`; per un admin un permesso nuovo risulta negato finché la riga manca in `permissions`: da chiarire con Laura (OPEN-50) |
| Cache e viste compilate | `php artisan optimize:clear` | il deploy prova `optimize:clear`; se dopo un deploy si vedono ancora vecchie schermate o configurazioni, cancellare da File Manager i file in `bootstrap/cache` (tranne `.gitignore`) e in `storage/framework/views` |
| Configurazione (`.env`) | file `.env` locale | file `.env` in `$HOME/lamess_app`, modificato da File Manager; **non è nel repository** e non va mai incollato in chat o nei log |
| Impostazioni di sistema | `Impostazioni di sistema` nel pannello | stesso pannello; **il valore del pannello vince su `.env`** (`SystemSettings::applyToConfig`) |
| Cron | `php artisan schedule:work` | cPanel → *Operazioni pianificate (Cron)*: `* * * * * <php> <percorso>/artisan schedule:run >> /dev/null 2>&1` |
| Log | `php artisan pail` / `storage/logs` | File Manager → `lamess_app/storage/logs` (canale `daily`) |
| Backup | — | pannello Sistema → Backup (anche automatico alle 02:15); ripristino da phpMyAdmin → Importa |
| Test | `php artisan test` | mai: si provano sul sito di prova o in locale |
| Pacchetto ZIP | `php tools/crea-pacchetto-cpanel.php` | alternativa al Git (vedi `installazione-cpanel.md`, sezione B) |

Variabili del `.env` di produzione per i servizi esterni (**non sono nel modello `.env.cpanel.example`**; l'elenco completo è in `installazione-cpanel.md`, A2):
- Stripe: `LAMESS_PAYMENT_DRIVER=stripe`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`.
- KMoney: `LAMESS_KMONEY_DRIVER=api`, `KMONEY_BASE_URL`, `KMONEY_CLIENT_ID`, `KMONEY_CLIENT_SECRET`, `KMONEY_SELLER_ACCOUNT`, `KMONEY_API_TOKEN`, `KMONEY_WEBHOOK_SECRET` (facoltativa `KMONEY_REGISTER_URL`; vuota = `{KMONEY_BASE_URL}/register`). Webhook: `POST /webhooks/kmoney`. L'URI di ritorno OAuth è `{APP_URL}/area/kmoney/ritorno`: va registrato su KMoney **sia con `www` sia senza**.
- Fiscale: `LAMESS_RECEIPTS_ENABLED` e `LAMESS_WITHHOLDING_ENABLED` sono **false** finché il commercialista non convalida; per accenderle basta metterle a `true` nel `.env` (o dal pannello).
- Dopo un deploy che cambia il `.env`: svuotare la cache come sopra.

## 4. Usare il motore

```php
$plan  = PlanLoader::incaricati();                      // Piano Incaricati da config/lamess/*.json (kosmos_v1)
$old   = PlanLoader::prelaunch();                       // piano precedente (multilevel_v3)
$input = new PeriodInput(PeriodKey::parse('2026-10'), $plan, $nodes, $movements, $prior, $customerFirstPurchase, $customerCoverage);
$res   = PeriodCalculator::calculate($input);            // PeriodResult
$res->lines;        // righe con bonus, beneficiario, importo, base, aliquota, spiegazione
$res->inputHash;    // hash canonico dello snapshot
ImpactReport::compare($base, $alt);                      // simulazione "cosa cambia se…"
IncaricatiSimulator::run($period, $plan, $changes);        // «cosa succede se» sulla rete (pagina Simulazione incaricati), sola lettura
IncaricatiSimulator::currentTree($period, $plan);          // albero reale del periodo con requisiti mancanti (stesse righe del risultato, nessuna modifica)
```
Il motore non tocca il database: `SnapshotBuilder::build($period)` costruisce `Node`, `Movement` e `PriorState` dal database (per il Piano Incaricati anche i clienti e i mesi di validità). Un `Node` porta `rankHoldUntil` (data di «Mantieni qualifica») e il piano porta `kosmos.activation_date`.

### Scrivere scenari di test
`tests/Support/Scenario.php` (di default carica il piano precedente; per il Piano Incaricati passa `PlanLoader::incaricati()` come terzo argomento, come in `KosmosPlanTest`):
```php
$s = (new Scenario)->consultant('A', null, 'DIAMOND')->consultant('P', 'A', 'PEARL');
$s->sale('P', 1000);
$r = $s->run();
```
`consultant()` accetta anche `rankHoldUntil`. Piano Incaricati: `tests/Unit/Engine/KosmosPlanTest.php`; piano precedente: `GoldenTest.php` (scenari G), `Pmk070726Test.php`, `BonusesTest.php`, `CareerTest.php`; invarianti su reti casuali in `PropertyTest.php`. Snapshot da database: `tests/Feature/Periods/KosmosSnapshotTest.php`.

### Aggiungere o modificare un parametro
- **Valori del piano.** Il JSON in `config/lamess/` è solo il **modello di partenza** di una nuova bozza; i valori che governano il calcolo sono quelli della versione pubblicata (`comp_plan_versions.data`). Per cambiarli: Piano compensi → nuova bozza → simulazione → pubblicazione (quattro occhi, data di efficacia). Una versione già salvata non cambia se si modifica il file: serve una nuova versione.
- **Impostazione numerica** (soglie, rate…): chiave in `PlanConfig::SETTINGS` + sezione `settings` del JSON.
- **Nuova politica**: voce in `PolicySpecs::all()` (decisione, default, opzioni, stato, fonte), poi `$plan->policies->is('nome','valore')` nel calcolo e un test.
- **Nuovo campo della sezione `kosmos`**: `KosmosConfig` (costruttore, `validate()`, `fromArray()`), `PlanFormMapper::toForm()/toData()`, il modulo in `CompPlanVersionResource`, il modello JSON e un test. Se non deve cambiare gli hash dei calcoli esistenti, aggiungerlo a `Canonical::OPTIONAL_WHEN_EMPTY`.
- **Nuovo dato dell'incaricato che il motore deve conoscere**: colonna + `Node` + `SnapshotBuilder` (come `rank_hold_until`).
- Documentazione dei parametri: `php artisan lamess:parametri --md` stampa le tabelle del **piano precedente** (impostazioni e politiche D/K). Attenzione: `--write=docs/parametri.md` sovrascrive l'intero file, comprese le sezioni scritte a mano sul Piano Incaricati; il comando non legge il Piano Incaricati.

## 5. Piano Incaricati nel codice

- **Calcolo**: `KosmosCalculator` (clienti, diretti, indiretti, estesi, Bonus di Struttura), configurazione in `KosmosConfig`. Un cliente è chi fa un ordine valido (`client_rule`: `any_order` oppure `min_amount` con `client_min_amount`, IVA inclusa; di default 150 €). Con `min_amount` un ordine in promozione sotto l'importo non rende cliente.
- **Importi, non punti**: `pv_movements.amount` (migrazione `2026_10_25_100000_pv_movements_amount`) è l'importo IVA inclusa della riga, scritto da `OrderService::createMovements` con `grossUnit()`; `vap_amount`, `validity_months`, `client_count` vengono dal prodotto (migrazioni `2026_11_01_100000_product_vap_and_customer_validity` e `2026_11_02_100000_product_customer_count`). Movimenti storici senza importo: punti × `commission_base_per_pv`.
- **Nessuno stato ATTIVO**: `PeriodCalculator` non lo valuta con `kosmos`; le schermate lo nascondono con `ActiveStatus::applies()`.
- **Qualifica che vale subito**: `CareerState::effectiveFrom()` con `immediate = true`. Le qualifiche calcolate si riscrivono ogni notte (`lamess:ricalcolo-notturno` → `NightlyRecalculation` → `PeriodService::calculate($period, 'live', syncRanks: true)`), togliendo quelle non più valide dopo un reso; non toccano le qualifiche forzate.
- **Mantenimento delle qualifiche** (politica K05 `kosmos_rank_maintenance` = `requirements`: retrocessione su tutti i requisiti, classe `RankMaintenance`; `clients` = solo clienti; `none`):
  - protezione del piano: `kosmos.rank_grace_days` (vuoto nel piano attivo) e/o `kosmos.rank_grace_until` (31/12/2026); vale la più lontana; ogni mese che finisce entro la fine è protetto (`KosmosConfig::rankGraceEnd()`, `ranksProtectedIn()`);
  - protezione per incaricato: `consultants.rank_hold_until` e `rank_hold_reason` (migrazione `2026_11_04_100000_consultants_rank_hold_until`), `Node::rankHeldIn()`; si imposta con la classe condivisa `App\Filament\Support\RankHoldAction` (riga, azione di massa, «tutti i registrati», scheda incaricato), permesso `network.restructure`, funzionalità `consultant360.action.hold_rank`; scrive nel log di audit `rank.hold` e `rank.hold_removed`;
  - vale solo con il Piano Incaricati e K05 = `clients` o `requirements`; protegge dal ribasso, non dalla promozione.
- **Bonus di Struttura**: fuori dal calcolo mensile; `StructureBonusService` lo scrive in `structure_bonuses` (stati calcolato → confermato → pagato), `lamess:bonus-struttura` (ogni ora, agisce solo se attivo e nel giorno/ora scelti; `--force` per calcolare subito).
- **Prodotto**: V.A.P. (`vap_mode` percent|fixed + `vap_value`), `customer_validity_months`, `customer_count`, `kmoney_percent`; congelati sulle righe d'ordine.
- **Elenco per livelli** (Rete → Elenco per livelli): `App\Domain\Network\LevelList` + `App\Filament\Pages\ConsultantLevelsPage`; colonna «Hanno acquistato» = clienti assegnati con almeno un ordine pagato (`Order::PAID_STATES`) nel periodo scelto (mese in corso, scorso, ultimi 3 mesi, anno, intervallo libero); limite 3000 righe. Test: `tests/Feature/Network/LevelListTest.php`.
- **Avanzamento di carriera** («cosa manca»): `App\Domain\Career\CareerProgress`; test `tests/Feature/Career/IncaricatiCareerProgressTest.php`.

### Nascondere il piano precedente
`App\Domain\Plan\LegacyPlan` (interruttore `legacy_plan_hidden`, in `SystemSettings` e `config('lamess.legacy_plan_hidden')`):
- **solo il super_admin** può commutarlo (`LegacyPlan::canToggle()`, pagina «Visibilità piano precedente» sotto Compensi); `hide()`/`show()` rifiutano gli altri con 403;
- non cancella né ricalcola nulla; il log di audit non viene toccato;
- `FeatureRegistry`: le funzionalità con `'legacy' => true` sono **spente per tutti, super_admin compreso**, quando il piano è nascosto (`Feature::enabled()` chiama `LegacyPlan::hides()` prima di ogni altra regola); un figlio di una funzionalità legacy lo è a sua volta;
- `LegacyPlan::scrub($text)` ripulisce i testi memorizzati (frasi note → equivalenti neutri; sigle PV/PVF/PVC/PR → «valori»); `bonusLabel()` e `collapseBonusTypes()` raccolgono Team/Matching/Rank Bonus/Fast Start sotto «Compensi del periodo precedente». Nei punti nuovi che stampano etichette o messaggi del vecchio piano, passarli da `scrub()`.
- Test: `tests/Feature/Features/LegacyPlanHiddenTest.php`.

## 6. Persistenza e periodi

```bash
php artisan migrate --seed --seeder=PrelaunchPlanSeeder   # tabelle + piano di pre-lancio attivo
php artisan lamess:periodo apri 2026-10                   # apre il periodo con il piano attivo
php artisan lamess:periodo live 2026-10                   # stima (calculation_run "live")
php artisan lamess:periodo runtime 2026-10                # runtime ufficiale → stato "review"
php artisan lamess:periodo chiudi 2026-10                 # checklist + lock righe
php artisan lamess:periodo stato 2026-10
```
Nel codice: `PvLedger::record(...)` per i movimenti; `app(PeriodService::class)->calculate($period, 'official')`. Automatismi: `PeriodAutomation::run($now)` (modalità `lamess.period_auto_close` = `off|calculate|close`; solo il primo mese non chiuso, entro 45 giorni; giorno e ora da `commission_calc_day`/`commission_calc_hour`; blocchi scritti una volta nel registro, cache `lamess.period_auto.blocked.*`; ultimo esito in `lamess.period_auto.last`), `NightlyRecalculation` (cache `lamess.nightly_recalc.last`). Battito del cron: `Cache::forever('lamess.scheduler.heartbeat')` ogni minuto, widget `SchedulerStatus`. Le versioni del piano programmate entrano in vigore con `PlanVersionService::promoteDue()` (00:10).

## 7. Rete, ruoli, funzionalità, back-office

```bash
php artisan lamess:superadmin laura@esempio.it     # super_admin (uno solo; mai dal pannello)
php artisan lamess:admin-user cliente@esempio.it --preset=admin_finance   # admin con permessi di partenza
php artisan lamess:ruoli                           # sincronizza ruoli e permessi (dopo un rilascio che ne aggiunge)
php artisan lamess:audit-verifica                  # controlla la catena del log
```
- `App\Domain\Access\Roles`: matrice ruoli → permessi (`area.azione`), `Roles::sync()` idempotente. Il super_admin passa da `Gate::before`. L'admin non ha permessi di ruolo: li concede il super_admin utente per utente (profilo «completo» o un ruolo di dettaglio). `network.restructure` (sposta rami, cambia sponsor, riassegna clienti, forza qualifiche, **mantieni qualifica**) non è in nessun ruolo di dettaglio.
- Le risorse Filament usano il trait `GatedByPermission` (`viewPermission()` / `managePermission()`); le azioni usano `->authorize('permesso')`. Nuova risorsa = dichiarare i due permessi; nuovo permesso = voce in `Roles::PERMISSIONS`, eventuale riga in `Roles::MATRIX`, poi `lamess:ruoli` (sul live vedi sezione 3).
- `User::canAccessPanel()` richiede un ruolo di `Roles::backoffice()`. 2FA: `AppAuthentication` di Filament con codici di recupero; obbligatorio con `ADMIN_2FA_REQUIRED` (nei test è `false` in `phpunit.xml`).
- **Funzionalità e viste**: elenco chiuso in `FeatureRegistry` (tab, pulsanti, voci di menu, widget), regole per tutti/ruolo/utente in `ui_feature_settings`, lette da `Feature::enabled($chiave)`. Una funzionalità nuova si dichiara in `FeatureRegistry::all()` e si controlla **anche lato server** con `Feature::enabled()`, non solo nascondendo l'elemento.
- **Form di modifica generici del super_admin**: `App\Filament\Pages\Base\SuperadminEditRecord` usa `App\Filament\Support\GenericForm` (modulo generato dalla struttura della tabella) e `GenericFormVocabulary` (etichette italiane, menu per stati e tipi, collegamenti ai record: utente, incaricato, ordine, prodotto…). Per aggiungere una tabella o un nuovo stato noto: voce in `GenericFormVocabulary` (`LABELS`, `OPTIONS`, `RELATIONS`). I campi JSON con forma nota (`GenericForm::SHAPES`: `subscriptions.lines`, `subscriptions.for_others`) diventano righe prodotto/quantità e **non sono obbligatori**: un abbonamento si salva anche senza prodotti. Ogni salvataggio scrive `superadmin.updated` nel log con i valori prima/dopo. Test: `tests/Feature/Admin/GenericFormTest.php`.
- **Reset password dall'admin**: `App\Domain\Access\PasswordResetLinks` (invio singolo/massivo con `Password::broker()->sendResetLink`, salta back-office/email non valide/link recenti, tempo massimo `max_execution_time`-10 s con conteggio dei rimasti, audit `user.reset_link_sent` / `user.reset_link_batch` solo con conteggi) e `App\Filament\Support\PasswordResetAction` (`forRow()`, `bulk()`, `all()`; permesso `network.manage`), usata in Incaricati, Clienti e Utenti. Controlla `lamess.mail_enabled` prima di creare i link. L'email è `App\Notifications\ResetPasswordIt`. Test: `tests/Feature/Access/AdminPasswordResetLinksTest.php`.
- KPI: `KpiService::snapshot($periodo)` (sola lettura) alimenta i widget `KpiOverview`, `RankDistributionChart`, `CommissionsByTypeChart` e la pagina `Dashboard`.
- `App\Support\StatusLabels` traduce i codici di stato nelle tabelle e nelle schede Filament; un `formatStateUsing` proprio sulla colonna ha la precedenza.
- Grafica: CSS statici in `public/css` (`lamess-tokens.css`, `lamess-admin.css`, `lamess-site.css`, `lamess-area.css`, `lamess-shop.css`, `lamess-home.css`), nessun build. Menu admin: `topNavigation()` + `navigationGroup` su ogni risorsa/pagina; gruppi in `AdminPanelProvider`. Pagine di errore: `resources/views/errors/*`.
- Test: `tests/Feature/Network` (closure, stati, re-iscrizione, operazioni sull'albero), `tests/Feature/Admin` (pannello via Livewire), `tests/Feature/Features`, `tests/Feature/Plan`.

## 8. Anagrafica, onboarding, ordini e pagamenti

```
App\Domain\Customers     ReferralResolver (link → cookie → codice a mano → incaricato «casa» OPEN-20), CustomerService,
                          ShippingAddressBook (fino a 10 indirizzi per utente: lamess.max_shipping_addresses)
App\Domain\Onboarding    IvdService (apply → requestSignature → sign → accept|reject), SignatureProvider (+ FakeSignatureProvider),
                          KycService, OnboardingService (missing()), BadgeService, FeeService (process / markPaid), Iban
App\Domain\Orders        OrderService (place → payByCard|payByKMoney|markPaid → ship|cancel), CartPricer, PendingCart, ReturnService,
                          ReturnRequestService, RefundService, PeriodResolver, PaymentProvider (+ Fake, Stripe), KMoneyProvider (+ Fake, Api, Disabled)
App\Domain\Subscriptions SubscriptionService
App\Domain\Billing       InvoiceService, FatturaPaBuilder, InvoiceSink (+ Fake, File, Mail), DocumentNumbers
```
- Cookie `lm_ref` 15 giorni (`lamess.referral_cookie_days`), escluso dalla cifratura in `bootstrap/app.php`.
- Firma: interfaccia `SignatureProvider`; driver `fake` (OTP in cache e mostrato in pagina, max 5 tentativi). Per un provider reale implementare l'interfaccia e aggiungere una riga al `match` di `AppServiceProvider` (`lamess.signature_driver`). Il testo della Lettera IVD è un **segnaposto** in `resources/views/pdf/ivd-letter.blade.php`: sostituirlo con il testo approvato dal Consorzio. Il certificato di completamento della firma usa `ivd_contract_events`.
- **KYC**: documenti richiesti in `config/lamess.php`; richiesta documenti dall'admin (`KycRequestAction`, tabella `kyc_requests`, promemoria `lamess:kyc-promemoria`); invio documenti dall'admin (`SendDocumentsAction`, log `documents.sent`).
- **PV solo da eventi di pagamento**: `markPaid` crea (o conferma) un movimento per riga con PV > 0, nel periodo del mese di pagamento (OPEN-25), `external_ref` = `order:{numero}:{riga}` (idempotenza). Bonifico: movimento `pending_payment` creato all'ordine se il periodo del mese è già aperto; la chiusura periodo è bloccata finché esiste.
- **Beneficiario**: acquirente incaricato attivo → movimento `pvc` a sé (con K04 acceso vale come cliente di sé stesso); cliente → `pvf` all'incaricato assegnato (se non cessato); senza incaricato = vendita «casa», nessun compenso.
- **Resi**: `ReturnService::refund` → nota di credito + movimento negativo con `reverses_movement_id`; periodo originale chiuso → storno nel mese corrente. Il cliente può chiedere il reso dalla pagina ordine entro `return_window_days` (14) dalla consegna (`ReturnRequestService`).
- **Prezzi**: l'admin digita il prezzo **IVA esclusa** (`net_price`) e l'aliquota (`vat_rate`); il prezzo IVA inclusa è calcolato e mostrato accanto (campi `gross_price`, `compare_at_gross`, `shipping_gross` e `price_gross` delle varianti, di sola lettura e non salvati). Il netto si salva con la precisione digitata. Cliente e admin vedono importi IVA inclusa con la sola aliquota («IVA 22% inclusa»). Il netto si arrotonda **una sola volta sul totale per aliquota** (`InvoiceService::totals`): un netto di 131,07 a 22% si mostra a 159,91; le righe documento possono differire di 1 centesimo dal totale (come nel formato FatturaPA). Un prodotto a prezzo zero non è acquistabile direttamente (serve come omaggio).
- **Carrello**: in sessione (`cart`, `cart_others`, `cart_codes`); `PendingCart::hold()` ricorda l'ordine non pagato (`cart_pending_order`), `pending()` allinea la sessione, `clear()` lo svuota solo a ordine pagato. Chi si registra con il carrello pieno torna al checkout.
- **Spedizione**: tariffe per paese e fascia di peso (`shipping_rates`), costo proprio per prodotto, tariffa Italia predefinita 9,00 € IVA inclusa creata dall'installazione guidata. Indirizzi di spedizione: rubrica fino a 10 per utente.
- **Abbonamenti**: `SubscriptionService::runDue()` dal comando `lamess:abbonamenti`; ogni ciclo è un ordine normale pagato con carta o KMoney. Impegno di 12 mesi **non vincolante** passato da `ShopController` (la disdetta è sempre libera e solo registrata) e spedizione sempre gratuita; il vecchio impegno minimo configurabile non esiste più. `Order::subscription()` lega l'ordine all'abbonamento. Ordine per altri: `OrderService::place(..., $forOthers, $subscription)`, destinatari in `recipientsFor`.
- **Pagamento che reindirizza (Stripe)**: `OrderService::usesRedirectCheckout()` e `startCardCheckout()`; la conferma arriva solo da `StripeWebhook::handle` → `markPaid` (idempotente; importo o valuta diversi non confermano). Firma: `StripeWebhook::verify` (HMAC-SHA256 su `t.payload`, tolleranza 5 minuti). Nessuna libreria Stripe: solo `Http` di Laravel.
- **KMoney**: `KMoneyProvider`, driver `fake|api|off` (`LAMESS_KMONEY_DRIVER`); `KMoneyApiProvider` fa OAuth2 con PKCE, mandato di pagamento (pagina di KMoney) e addebiti col mandato; `KMoneyLink` conserva i token cifrati e `mandate_uuid`; `KMoneyController` gestisce collegamento, ritorno, scollegamento e webhook firmato (`hash_hmac` sha256 con `KMONEY_WEBHOOK_SECRET`). Il pannello «Conto KMoney» sta in `/area/profilo#kmoney`; il checkout rimanda lì se il conto non è collegato. La % KMoney è per prodotto (`kmoney_percent`); il vantaggio mostrato nel checkout è `kmoney_bonus_percent`. Con un carrello 100% KMoney anche la spedizione si paga in KMoney; il resto si paga con carta o bonifico. Gli importi KMoney sono in KY. Test: `KMoneyMandateFlowTest`, `KMoneyProfileLinkTest`, `SubscriptionKMoneySyncTest`, `SubscriptionPaymentMethodsTest`.
- **Fattura elettronica**: `FatturaPaBuilder::build($doc)` restituisce `['filename','xml']` (FPR12, TD01/TD04); errori di dati → `BillingException`, salvati in `sink_error`. Le ricevute (`type = receipt`) non si trasmettono. Lo schema ufficiale è in `tests/Fixtures/fatturapa/` e i test validano ogni XML. Driver: `lamess.invoice_sink_driver` (`fake|file|mail`); per GestionaleK implementare `InvoiceSink::send()` e aggiungere il driver al `match` di `AppServiceProvider`.
- Il tempo va letto con `CarbonImmutable::now()` (non `new DateTimeImmutable`) per poter usare `travelTo()` nei test.
- Blade: con Livewire installato **non usare `@continue`/`@break` dentro `@foreach`**; usa `@if`.
- Test: `tests/Feature/Shop`, `tests/Feature/Onboarding`, `tests/Feature/Drivers`.

## 9. Liquidazioni, wallet, contenuti, conformità

- `PayoutService`: `generateSettlements`, `preparePayouts($upTo)`, `prepareDue($now)` (automatico), `markPaid`, `cancel`, `markHistorical`, `openBalance`, `situation`. Importi sempre `BigDecimal` da stringa. Con `payout_mode = monthly_auto` `lamess:pagamenti` (05:00) prepara i pagamenti dal giorno `payout_day`.
- File SEPA (`SepaExport::build()`, pain.001.001.03, DOM, importi con BigDecimal, niente bcmath) e avvisi (`notify_statement_ready`, `notify_payout_paid`; best-effort, controllano `lamess.mail_enabled`).
- Estratti conto e reclami (`StatementLifecycle`), ricevute provvigionali e trattenute (`ReceiptService`, `FiscalCalculator`): **spente di default** (`lamess.fiscal.receipts_enabled`, `withholding_enabled`, `inps_enabled`); valori fiscali provvisori, da far convalidare.
- Wallet (`WalletService`, `WithdrawalService`), contenuti (`DownloadService` + `Watermarker`, `EventService`, `ReferralQr`, `RankLookup`, `Audience`), conformità (`ComplianceService`: `sanction` applica l'effetto, `lift`/`expireDue` ripristinano senza cancellare il blocco imposto da altre sanzioni; `MaterialService` codice `MA-AAAA-nnnn`), cessione e successione (`AccountHolderService::swap` cambia l'intestatario senza toccare codice, sponsor, qualifica e referral; `TransferService`, `InheritanceService`), piano a quattro occhi (`PlanVersionService`: `requestApproval` / `approve` / `reject` / `withdraw`, impostazione `plan_four_eyes`), ticket (`TicketService`).
- Import da Odoo: `lamess:importa-odoo`, `lamess:importa-anagrafica`, `lamess:importa-storico`, `lamess:importa-prelievi`, `lamess:importa-iban`, `lamess:importa-prodotti`, `lamess:verifica-odoo`; riconciliazione in `import_reconciliations`.
- Nuovi permessi `payouts.*`, `plan.approve`, `features.manage`… vanno sincronizzati con `lamess:ruoli` (sezione 3 per il live).

## 10. Regole di codice e insidie

- Mai `float` per denaro: `Num::of()`, `Num::money()`; SQLite restituisce float, quindi `(string)` prima di `BigDecimal`.
- Nessun numero magico nel motore: tutto viene dal piano (`PlanConfig`/`KosmosConfig`).
- Ogni riga di risultato deve avere spiegazione e decisioni usate.
- I parametri delle closure Filament si risolvono **per nome** (`$state`, `$search`, `$value`, `$record`, `$data`): altri nomi rompono la validazione dei `Select`.
- `bcmath` può mancare sull'hosting: niente `bcadd` (si usa `Num`).
- L'ordine conserva i dati cliente in `billing` (campo `name`, più `first_name`/`last_name` dagli ordini nuovi).
- Immagini prodotto nell'admin: usano la route `/media/...` dello shop (l'URL firmato era rifiutato in produzione).
- **Sicurezza** (16/10/2026): `WithdrawalService::request`/`approve` lavorano in transazione con `lockForUpdate` sul consulente (niente doppio impegno del saldo); `SiteController::login` blocca per account con `RateLimiter` (`LOGIN_MAX_PER_PAIR` = 5 errori per email+IP, `LOGIN_MAX_PER_EMAIL` = 15 per sola email, `LOGIN_LOCK_SECONDS` = 900; un accesso riuscito azzera i contatori); cookie di sessione `secure` di default in produzione (`SESSION_SECURE_COOKIE`) e HTTPS forzato da `public/.htaccess` (escluso `/.well-known/`); log di audit con chiave precedente per le righe importate (`lamess.audit_key_previous`, `…_until`).
- **Prestazioni e stabilità**: saldo wallet e importi impegnati con `SUM` in database; `ShopController::media` risponde con cache di 30 giorni, `ETag`/`Last-Modified` e 304; i calcoli di periodo (`PeriodService`) allungano il tempo a 300 s, ignorano la chiusura della pagina e rilasciano il blocco anche dopo un errore fatale; `ConsultantList` è registrato `scoped` in `AppServiceProvider` (una sola istanza per richiesta, niente query per riga); `InvoiceService::retryPending` consegna al massimo 200 documenti per giro orario; `/area/clienti` è paginato a 50 (`pagina`, filtri mantenuti).
- **Pagine legali**: `LegalController` serve `privacy`, `termini`, `politiche-di-reso` e `regolamento`; quest'ultima rende `resources/legal/regolamento-v2.0.md` (Markdown, senza il titolo) nella vista `site.legal.regolamento`. Per cambiare il testo si modifica quel file.
- **Invio fatture in blocco**: `InvoiceService::batchMode()` (da `lamess.sdi.send_mode`), `deliverOnIssue` (all'emissione non consegna in blocco), `sendBlocker()` (motivo per cui l'invio non può partire), `pendingCount()`, `sendPending(?int $limit)` e `retryPending(bool $force)` (giro orario con tetto 200, spento in blocco). Pulsante in `ListInvoices` (50 per volta) e comando `lamess:fatture --tutte`, pianificato il 1° del mese alle 08:30 solo in blocco. Test in `FatturaPaTest`.
- **Email in coda**: le notifiche ai clienti implementano `ShouldQueueAfterCommit` (3 tentativi); con `QUEUE_CONNECTION=database` il cron lancia `lamess:coda` ogni minuto (`queue:work --stop-when-empty`), con `sync` nulla cambia. Il codice di firma (OTP) e il reset password restano immediati.
- **Firma con codice**: driver `email` (`Onboarding\EmailSignatureProvider`, notifica `SignatureOtpCode`): 6 cifre, 15 minuti, 5 tentativi, reinvio dopo 60 s, impronta HMAC in cache; senza invio email attivo lancia un errore e non invia nulla; il driver `fake` resta un BLOCCO nel controllo di messa online.
- Aggiornare `docs/registro-aggiornamenti.md` a ogni passo e rigenerare il dossier (`php artisan lamess:manuale`, scrive `docs/dossier.html`) quando cambiano i manuali.
- Dopo ogni modifica PHP: `vendor/bin/pint --dirty --format agent`.

## 11. Documentazione

I manuali sono in `docs/` (elenco nel `README.md`). `php artisan lamess:parametri` produce solo le tabelle del piano precedente (vedi sezione 4 per la sovrascrittura); `php artisan lamess:manuale` ricostruisce il dossier HTML unico dai manuali.
