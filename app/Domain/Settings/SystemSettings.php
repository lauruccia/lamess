<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Impostazioni di sistema: valori che il super_admin può cambiare da pannello senza toccare il .env.
 *
 * Ogni impostazione sovrascrive una chiave di config('lamess.…'): il valore del pannello vince su .env.
 * L'elenco è CHIUSO (DEFINITIONS): una chiave non dichiarata non si può scrivere. Ogni modifica va nel log di audit
 * con valore precedente e nuovo. Le impostazioni di sicurezza (2FA obbligatoria, driver, chiavi) restano nel .env
 * e il pannello le mostra solo in lettura.
 */
final class SystemSettings
{
    private const CACHE_KEY = 'lamess.system_settings.v1';

    /**
     * type: bool | int | string | text | select
     *
     * @var array<string, array{config: string, type: string, label: string, group: string, help?: string, rules: list<string>|string, options?: array<string,string>, nullable?: bool}>
     */
    private const DAYS_OF_MONTH = [
        '1' => '1° del mese successivo', '2' => '2 del mese successivo', '3' => '3 del mese successivo', '4' => '4 del mese successivo', '5' => '5 del mese successivo',
        '6' => '6 del mese successivo', '7' => '7 del mese successivo', '8' => '8 del mese successivo', '9' => '9 del mese successivo', '10' => '10 del mese successivo',
        '11' => '11 del mese successivo', '12' => '12 del mese successivo', '13' => '13 del mese successivo', '14' => '14 del mese successivo', '15' => '15 del mese successivo',
        '16' => '16 del mese successivo', '17' => '17 del mese successivo', '18' => '18 del mese successivo', '19' => '19 del mese successivo', '20' => '20 del mese successivo',
        '21' => '21 del mese successivo', '22' => '22 del mese successivo', '23' => '23 del mese successivo', '24' => '24 del mese successivo', '25' => '25 del mese successivo',
        '26' => '26 del mese successivo', '27' => '27 del mese successivo', '28' => '28 del mese successivo',
    ];

    public const DEFINITIONS = [
        'site_open' => ['config' => 'site.open', 'type' => 'bool', 'group' => 'Sito', 'label' => 'Sito aperto al pubblico',
            'help' => 'Spento: i visitatori vedono la pagina «Lamèss apre presto» e nessuno può registrarsi o ordinare. Il back-office (/admin) resta raggiungibile e chi è già collegato come amministratore vede il sito normalmente, per provarlo. Accendere solo quando si decide di aprire.', 'rules' => 'boolean'],
        'legacy_plan_hidden' => ['config' => 'lamess.legacy_plan_hidden', 'type' => 'bool', 'group' => 'Piano di marketing', 'label' => 'Nascondi il vecchio piano di marketing',
            'help' => 'Acceso: in tutto il portale non compare più nessun riferimento al vecchio piano (punti PV/PVF/PVC/PR, Team, Matching, Rank Bonus, Fast Start). Non si cancella e non si ricalcola nulla: i dati restano e tornano visibili spegnendo l\'interruttore.', 'rules' => 'boolean'],
        'mail_enabled' => ['config' => 'lamess.mail_enabled', 'type' => 'bool', 'group' => 'Email', 'label' => 'Invio email attivo',
            'help' => 'Spento: nessuna email parte (notifiche, recupero password) e tutto viene scritto solo nel registro. Accendere solo quando la posta è configurata e si decide di inviare davvero.', 'rules' => 'boolean'],
        'network_downline_contacts' => ['config' => 'lamess.network.downline_contacts', 'type' => 'bool', 'group' => 'Rete', 'label' => 'Mostra email e telefono degli incaricati sotto di lui',
            'help' => 'Acceso: nella scheda di ogni incaricato del proprio ramo (qualsiasi livello) si vedono email e telefono. Non riguarda i clienti indiretti, di cui non si vedono mai i recapiti.', 'rules' => 'boolean'],
        'network_downline_place' => ['config' => 'lamess.network.downline_place', 'type' => 'bool', 'group' => 'Rete', 'label' => 'Mostra città e provincia degli incaricati sotto di lui',
            'help' => 'Acceso: nella scheda degli incaricati del proprio ramo compaiono città e provincia. Codice fiscale, IBAN e documenti non si mostrano mai.', 'rules' => 'boolean'],
        'reenrollment_days' => ['config' => 'lamess.reenrollment_days', 'type' => 'int', 'group' => 'Rete', 'label' => 'Giorni di attesa per ri-iscriversi',
            'help' => 'Dopo una cessazione, la stessa persona può tornare incaricato solo dopo questi giorni (Reg. 2.10.b: 180).', 'rules' => 'integer|min:0|max:3650'],
        'referral_cookie_days' => ['config' => 'lamess.referral_cookie_days', 'type' => 'int', 'group' => 'Rete', 'label' => 'Durata del cookie referral (giorni)',
            'help' => 'Per quanti giorni il link di un incaricato resta valido nel browser del visitatore.', 'rules' => 'integer|min:1|max:365'],
        'referral_invites_per_day' => ['config' => 'lamess.referral_invites_per_day', 'type' => 'int', 'group' => 'Rete', 'label' => 'Inviti referral al giorno per incaricato',
            'help' => 'Quanti inviti personali può creare un incaricato in 24 ore (serve a evitare abusi). 0: nessun limite.', 'rules' => 'integer|min:0|max:1000'],
        'personal_pages_enabled' => ['config' => 'lamess.personal_pages.enabled', 'type' => 'bool', 'group' => 'Rete', 'label' => 'Pagine personali degli incaricati',
            'help' => 'Acceso: ogni incaricato attivo può creare la propria pagina pubblica (indirizzo /i/nome) con foto, presentazione e contatti. Spento: le pagine non si aprono più e la voce sparisce dall\'area riservata; i contenuti restano salvati.', 'rules' => 'boolean'],
        'personal_pages_approval' => ['config' => 'lamess.personal_pages.approval', 'type' => 'bool', 'group' => 'Rete', 'label' => 'Le pagine personali vanno approvate dal Consorzio',
            'help' => 'Acceso: ogni modifica dell\'incaricato resta «in revisione» finché un admin non la approva; fino ad allora si vede la versione precedente. Spento: le modifiche vanno online subito (il Consorzio può sempre nascondere una pagina).', 'rules' => 'boolean'],
        'default_consultant_code' => ['config' => 'lamess.default_consultant_code', 'type' => 'string', 'group' => 'Rete', 'label' => 'Codice dell’incaricato «casa»',
            'help' => 'A chi assegnare i clienti che si registrano senza referral. Vuoto: nessuno (OPEN-20).', 'rules' => 'string|max:40', 'nullable' => true],
        'require_first_box_to_apply' => ['config' => 'lamess.require_first_box_to_apply', 'type' => 'bool', 'group' => 'Rete', 'label' => 'Prima box obbligatoria per diventare incaricato',
            'help' => 'Acceso: il cliente può inviare la richiesta di diventare incaricato solo dopo aver pagato almeno un ordine. Spento (come da PMK): nessun acquisto richiesto.', 'rules' => 'boolean'],
        'annual_fee_enabled' => ['config' => 'lamess.annual_fee_enabled', 'type' => 'bool', 'group' => 'Canone annuale', 'label' => 'Canone annuale attivo',
            'help' => 'Spento: non si chiede nessun canone e i rinnovi della lettera sono gratuiti.', 'rules' => 'boolean'],
        'annual_fee_amount' => ['config' => 'lamess.annual_fee_amount', 'type' => 'string', 'group' => 'Canone annuale', 'label' => 'Importo del canone (€, IVA compresa)',
            'help' => 'Dal secondo anno, IVA compresa. Formato 19.00', 'rules' => 'regex:/^\d{1,5}(\.\d{1,2})?$/'],
        'annual_fee_grace_days' => ['config' => 'lamess.annual_fee_grace_days', 'type' => 'int', 'group' => 'Canone annuale', 'label' => 'Giorni di tolleranza sul canone',
            'help' => 'Dopo la scadenza, i giorni prima che la lettera si risolva (OPEN-24: 0).', 'rules' => 'integer|min:0|max:365'],
        'payment_card_enabled' => ['config' => 'lamess.payments.card', 'type' => 'bool', 'group' => 'Metodi di pagamento', 'label' => 'Carta di credito o debito',
            'help' => 'Acceso: il cliente può pagare con carta (serve il collegamento con Stripe nel file .env).', 'rules' => 'boolean'],
        'payment_transfer_enabled' => ['config' => 'lamess.payments.transfer', 'type' => 'bool', 'group' => 'Metodi di pagamento', 'label' => 'Bonifico bancario',
            'help' => 'Acceso: il cliente può pagare con bonifico; l\'ordine resta in attesa finché non confermi l\'incasso. Ricorda di scrivere l\'IBAN qui sotto.', 'rules' => 'boolean'],
        'payment_kmoney_enabled' => ['config' => 'lamess.payments.kmoney', 'type' => 'bool', 'group' => 'Metodi di pagamento', 'label' => 'KMoney',
            'help' => 'Acceso: compare il pagamento con il saldo KMoney (se il collegamento con KMoney nel file .env è attivo).', 'rules' => 'boolean'],
        'kmoney_bonus_percent' => ['config' => 'lamess.kmoney_bonus_percent', 'type' => 'int', 'group' => 'Metodi di pagamento', 'label' => 'Vantaggio KMoney (% di KY in più)',
            'help' => 'Per ogni ricarica KMoney si ricevono tanti KY in più: con 25, 120 € di ricarica danno 150 KY. Nel checkout compare «Ricarichi solo € … e risparmi € …, fino al 25%». 0 = nessun messaggio.', 'rules' => 'integer|min:0|max:100'],
        'bank_transfer_instructions' => ['config' => 'lamess.bank_transfer_instructions', 'type' => 'text', 'group' => 'Ordini e fatture', 'label' => 'Istruzioni per il bonifico',
            'help' => 'Testo mostrato al cliente che sceglie il bonifico (IBAN e causale).', 'rules' => 'string|max:1000'],
        'private_document' => ['config' => 'lamess.private_document', 'type' => 'select', 'group' => 'Ordini e fatture', 'label' => 'Documento per i clienti privati',
            'options' => ['invoice' => 'Fattura', 'receipt' => 'Ricevuta'], 'rules' => 'in:invoice,receipt'],
        'sdi_send_mode' => ['config' => 'lamess.sdi.send_mode', 'type' => 'select', 'group' => 'Ordini e fatture', 'label' => 'Invio degli XML delle fatture',
            'help' => 'Subito: ogni fattura parte all\'emissione. In blocco: le fatture restano «Da inviare» e partono tutte insieme (una email con XML per documento) col pulsante in Fatture oppure da sole il 1° di ogni mese alle 08:30. Serve il driver fatture «mail», l\'indirizzo LAMESS_SDI_MAIL_TO e «Invio email attivo».',
            'options' => ['immediate' => 'Subito, all\'emissione', 'batch' => 'In blocco: col pulsante o il 1° del mese'], 'rules' => 'in:immediate,batch'],
        'period_auto_close' => ['config' => 'lamess.period_auto_close', 'type' => 'select', 'group' => 'Chiusura mensile', 'label' => 'Chiusura automatica del mese',
            'options' => ['off' => 'Spenta: tutto a mano', 'calculate' => 'Solo calcolo ufficiale: la revisione e la chiusura le fa l\'admin', 'close' => 'Calcola e chiude da sola'],
            'help' => 'Il primo giorno del mese successivo (alle 01:00) il portale lavora sul mese appena finito. Se ci sono movimenti in attesa di pagamento la chiusura si ferma e riprova ogni notte. I pagamenti partono poi dal giorno indicato nel piano (di solito il 15).', 'rules' => 'in:off,calculate,close'],
        'commission_calc_day' => ['config' => 'lamess.commission_calc_day', 'type' => 'select', 'group' => 'Chiusura mensile', 'label' => 'Giorno del calcolo mensile delle commissioni',
            'options' => ['end' => 'Ultimo giorno del mese'] + self::DAYS_OF_MONTH,
            'help' => 'Diretti, indirette ed estese si calcolano una volta al mese, a questa data e all\'ora indicata sotto, sullo stato della rete in quel momento. Con «ultimo giorno» il calcolo parte prima della mezzanotte: gli acquisti registrati dopo non entrano nel mese. Il Bonus di Struttura è separato e segue il calcolo settimanale.', 'rules' => 'in:end,'.'1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28'],
        'commission_calc_hour' => ['config' => 'lamess.commission_calc_hour', 'type' => 'int', 'group' => 'Chiusura mensile', 'label' => 'Ora del calcolo mensile (0-23)',
            'help' => 'Ora italiana. Il controllo avviene ogni ora.', 'rules' => 'integer|min:0|max:23'],
        'structure_bonus_auto' => ['config' => 'lamess.structure_bonus_auto', 'type' => 'bool', 'group' => 'Bonus di Struttura (settimanale)', 'label' => 'Calcolo settimanale automatico',
            'help' => 'Acceso: ogni settimana, nel giorno e all\'ora indicati, i Bonus di Struttura maturati vengono calcolati e scritti nel registro. Si possono sempre calcolare a mano dalla pagina «Bonus di Struttura». La conferma e il pagamento restano all\'admin.', 'rules' => 'boolean'],
        'structure_bonus_weekday' => ['config' => 'lamess.structure_bonus_weekday', 'type' => 'select', 'group' => 'Bonus di Struttura (settimanale)', 'label' => 'Giorno della settimana',
            'options' => ['1' => 'Lunedì', '2' => 'Martedì', '3' => 'Mercoledì', '4' => 'Giovedì', '5' => 'Venerdì', '6' => 'Sabato', '7' => 'Domenica'], 'rules' => 'in:1,2,3,4,5,6,7'],
        'structure_bonus_cutoff_weekday' => ['config' => 'lamess.structure_bonus_cutoff_weekday', 'type' => 'select', 'group' => 'Bonus di Struttura (settimanale)', 'label' => 'Dati considerati fino a (fine giornata)',
            'help' => 'Il calcolo usa solo i dati fino alla fine dell\'ultimo giorno scelto già trascorso: con «Domenica», un calcolo di mercoledì considera i dati fino a domenica sera. «Nessun taglio» usa tutti i dati fino al momento del calcolo. Alla chiusura del mese il registro si aggiorna con tutti i dati del mese.',
            'options' => ['7' => 'Domenica', '1' => 'Lunedì', '2' => 'Martedì', '3' => 'Mercoledì', '4' => 'Giovedì', '5' => 'Venerdì', '6' => 'Sabato', '0' => 'Nessun taglio'], 'rules' => 'in:0,1,2,3,4,5,6,7'],
        'structure_bonus_hour' => ['config' => 'lamess.structure_bonus_hour', 'type' => 'int', 'group' => 'Bonus di Struttura (settimanale)', 'label' => 'Ora del calcolo (0-23)', 'help' => 'Ora italiana.', 'rules' => 'integer|min:0|max:23'],
        'plan_four_eyes' => ['config' => 'lamess.plan_four_eyes', 'type' => 'bool', 'group' => 'Piano compensi', 'label' => 'Approvazione a quattro occhi del piano',
            'help' => 'Acceso: una nuova versione del piano diventa efficace solo dopo l\'approvazione di un secondo amministratore diverso da chi l\'ha richiesta. Spegnerlo è consentito (es. un solo amministratore) e finisce nel log.', 'rules' => 'boolean'],
        'dispute_days' => ['config' => 'lamess.dispute_days', 'type' => 'int', 'group' => 'Compensi', 'label' => 'Giorni per i reclami su estratti e ricevute',
            'help' => 'Dalla pubblicazione, i giorni in cui l’incaricato può contestare (Reg. 5.2: 10). Poi l\'estratto è accettato.', 'rules' => 'integer|min:1|max:90'],
        'fiscal_receipts_enabled' => ['config' => 'lamess.fiscal.receipts_enabled', 'type' => 'bool', 'group' => 'Ricevute e trattenute (da validare col commercialista)', 'label' => 'Emetti le ricevute provvigionali',
            'help' => 'Acceso: a ogni pagamento eseguito si emette la ricevuta per conto dell’incaricato (Reg. 5.1), con 10 giorni per segnalare anomalie. I valori fiscali qui sotto sono PROVVISORI: farli validare prima di accendere.', 'rules' => 'boolean'],
        'fiscal_withholding_enabled' => ['config' => 'lamess.fiscal.withholding_enabled', 'type' => 'bool', 'group' => 'Ricevute e trattenute (da validare col commercialista)', 'label' => 'Trattieni la ritenuta d\'acconto',
            'help' => 'Acceso: il bonifico è pari al lordo meno la ritenuta. Spento: il bonifico è pari al lordo.', 'rules' => 'boolean'],
        'fiscal_withholding_pct' => ['config' => 'lamess.fiscal.withholding_pct', 'type' => 'string', 'group' => 'Ricevute e trattenute (da validare col commercialista)', 'label' => 'Aliquota ritenuta (%)', 'rules' => 'regex:/^\d{1,6}(\.\d{1,4})?$/'],
        'fiscal_taxable_pct' => ['config' => 'lamess.fiscal.taxable_pct', 'type' => 'string', 'group' => 'Ricevute e trattenute (da validare col commercialista)', 'label' => 'Quota imponibile delle provvigioni (%)',
            'help' => 'Per gli incaricati alle vendite a domicilio la ritenuta e la base INPS si calcolano su questa quota (78%).', 'rules' => 'regex:/^\d{1,6}(\.\d{1,4})?$/'],
        'fiscal_inps_enabled' => ['config' => 'lamess.fiscal.inps_enabled', 'type' => 'bool', 'group' => 'Ricevute e trattenute (da validare col commercialista)', 'label' => 'Trattieni la quota INPS dell’incaricato',
            'help' => 'Solo sulla parte di provvigioni dell\'anno oltre la soglia.', 'rules' => 'boolean'],
        'fiscal_inps_threshold' => ['config' => 'lamess.fiscal.inps_threshold', 'type' => 'string', 'group' => 'Ricevute e trattenute (da validare col commercialista)', 'label' => 'Soglia annua INPS (€)', 'rules' => 'regex:/^\d{1,6}(\.\d{1,4})?$/'],
        'fiscal_inps_rate' => ['config' => 'lamess.fiscal.inps_rate', 'type' => 'string', 'group' => 'Ricevute e trattenute (da validare col commercialista)', 'label' => 'Aliquota contributiva complessiva (%)',
            'help' => 'Valore provvisorio: confermare l\'aliquota dell\'anno con il commercialista.', 'rules' => 'regex:/^\d{1,6}(\.\d{1,4})?$/'],
        'fiscal_inps_worker_share' => ['config' => 'lamess.fiscal.inps_worker_share', 'type' => 'string', 'group' => 'Ricevute e trattenute (da validare col commercialista)', 'label' => 'Quota INPS a carico dell’incaricato (%)', 'rules' => 'regex:/^\d{1,6}(\.\d{1,4})?$/'],
        'wallet_bank_fee' => ['config' => 'lamess.fiscal.bank_fee', 'type' => 'string', 'group' => 'Wallet e prelievi', 'label' => 'Commissione bonifico sui prelievi (€)',
            'help' => 'Trattenuta su ogni prelievo su richiesta (predefinito 0: nessuna commissione, decisione del 15/10/2026). Non si applica ai pagamenti mensili automatici.', 'rules' => 'regex:/^\d{1,6}(\.\d{1,4})?$/'],
        'subscription_monthly_enabled' => ['config' => 'lamess.subscription.monthly_enabled', 'type' => 'bool', 'group' => 'Abbonamenti e spedizione', 'label' => 'Abbonamento mensile attivo',
            'help' => 'Se spento, i clienti non vedono più l\'abbonamento mensile nel checkout. Gli abbonamenti già attivi continuano a funzionare.', 'rules' => 'boolean'],
        'subscription_bimonthly_enabled' => ['config' => 'lamess.subscription.bimonthly_enabled', 'type' => 'bool', 'group' => 'Abbonamenti e spedizione', 'label' => 'Abbonamento bimestrale attivo',
            'help' => 'Se spento, i clienti non vedono più l\'abbonamento bimestrale nel checkout. Gli abbonamenti già attivi continuano a funzionare.', 'rules' => 'boolean'],
        'subscription_retry_days' => ['config' => 'lamess.subscription.retry_days', 'type' => 'int', 'group' => 'Abbonamenti e spedizione', 'label' => 'Giorni tra i tentativi di addebito',
            'help' => 'Se l\'addebito mensile fallisce, si riprova dopo questi giorni.', 'rules' => 'integer|min:1|max:30'],
        'subscription_reminder_days' => ['config' => 'lamess.subscription.reminder_days', 'type' => 'int', 'group' => 'Abbonamenti e spedizione', 'label' => 'Giorni di preavviso prima dell\'addebito',
            'help' => 'Il cliente riceve un\'email di promemoria questi giorni prima dell\'addebito dell\'abbonamento. 0 = nessun promemoria.', 'rules' => 'integer|min:0|max:30'],
        'subscription_max_retries' => ['config' => 'lamess.subscription.max_retries', 'type' => 'int', 'group' => 'Abbonamenti e spedizione', 'label' => 'Tentativi di addebito prima della pausa',
            'help' => 'Dopo l\'ultimo tentativo fallito l\'abbonamento va in pausa e il cliente può riprenderlo.', 'rules' => 'integer|min:1|max:10'],
        'sepa_debtor_name' => ['config' => 'lamess.sepa.debtor_name', 'type' => 'string', 'group' => 'Bonifici SEPA', 'label' => 'Intestatario del conto da cui si paga',
            'help' => 'Nome come risulta in banca (max 70 caratteri). Serve per il file SEPA dei bonifici.', 'rules' => 'string|max:70', 'nullable' => true],
        'sepa_debtor_iban' => ['config' => 'lamess.sepa.debtor_iban', 'type' => 'string', 'group' => 'Bonifici SEPA', 'label' => 'IBAN del conto da cui si paga',
            'rules' => 'string|max:34', 'nullable' => true],
        'sepa_debtor_bic' => ['config' => 'lamess.sepa.debtor_bic', 'type' => 'string', 'group' => 'Bonifici SEPA', 'label' => 'BIC della banca (facoltativo)',
            'rules' => 'string|max:11', 'nullable' => true],
        'notify_statement_ready' => ['config' => 'lamess.notify_statement_ready', 'type' => 'bool', 'group' => 'Email', 'label' => 'Avviso all’incaricato: statino pronto',
            'help' => 'Alla chiusura del mese, ogni incaricato con compensi riceve un avviso. Solo incaricati, mai clienti. Non parte nulla finché «Invio email attivo» è spento.', 'rules' => 'boolean'],
        'notify_payout_paid' => ['config' => 'lamess.notify_payout_paid', 'type' => 'bool', 'group' => 'Email', 'label' => 'Avviso all’incaricato: bonifico effettuato',
            'help' => 'Quando segni un pagamento come pagato, l’incaricato riceve un avviso. Solo incaricati, mai clienti.', 'rules' => 'boolean'],
        'seller_name' => ['config' => 'lamess.seller.name', 'type' => 'string', 'group' => 'Venditore (intestazione fatture)', 'label' => 'Denominazione (ragione sociale oppure nome e cognome)', 'help' => 'Chi emette fatture e statini: per una società la ragione sociale, per una persona fisica nome e cognome. Compare nell\'intestazione dei documenti.', 'rules' => 'string|max:190'],
        'seller_vat_number' => ['config' => 'lamess.seller.vat_number', 'type' => 'string', 'group' => 'Venditore (intestazione fatture)', 'label' => 'Partita IVA / codice fiscale', 'rules' => 'string|max:32', 'nullable' => true],
        'seller_address' => ['config' => 'lamess.seller.address', 'type' => 'string', 'group' => 'Venditore (intestazione fatture)', 'label' => 'Indirizzo', 'rules' => 'string|max:255', 'nullable' => true],
        'seller_email' => ['config' => 'lamess.seller.email', 'type' => 'string', 'group' => 'Venditore (intestazione fatture)', 'label' => 'Email', 'rules' => 'email|max:190', 'nullable' => true],
        'seller_tax_code' => ['config' => 'lamess.seller.tax_code', 'type' => 'string', 'group' => 'Venditore (intestazione fatture)', 'label' => 'Codice fiscale (se diverso dalla partita IVA)', 'rules' => 'string|max:16', 'nullable' => true],
        'seller_zip' => ['config' => 'lamess.seller.zip', 'type' => 'string', 'group' => 'Venditore (intestazione fatture)', 'label' => 'CAP', 'rules' => 'string|max:5', 'nullable' => true],
        'seller_city' => ['config' => 'lamess.seller.city', 'type' => 'string', 'group' => 'Venditore (intestazione fatture)', 'label' => 'Comune', 'rules' => 'string|max:60', 'nullable' => true],
        'seller_province' => ['config' => 'lamess.seller.province', 'type' => 'string', 'group' => 'Venditore (intestazione fatture)', 'label' => 'Sigla provincia (es. RC)', 'rules' => 'string|size:2', 'nullable' => true],
        'seller_regime' => ['config' => 'lamess.seller.regime', 'type' => 'string', 'group' => 'Venditore (intestazione fatture)', 'label' => 'Regime fiscale (codice SdI)', 'help' => 'RF01 = ordinario, RF19 = forfettario. Chiedi al commercialista.', 'rules' => 'string|max:4', 'nullable' => true],
    ];

    /** Sovrascrive config() con i valori salvati. Chiamato a ogni avvio; non fallisce se la tabella non esiste ancora. */
    public static function applyToConfig(): void
    {
        foreach (self::stored() as $key => $value) {
            if (isset(self::DEFINITIONS[$key])) {
                config([self::DEFINITIONS[$key]['config'] => $value]);
            }
        }
    }

    /** @return array<string, mixed> valore salvato per chiave (solo quelle impostate dal pannello) */
    public static function stored(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn (): array => DB::table('system_settings')->pluck('value', 'key')
                ->map(fn ($v) => $v === null ? null : json_decode((string) $v, true))->all());
        } catch (\Throwable) {
            return [];
        }
    }

    /** Valore in vigore (pannello, altrimenti config/.env). */
    public static function value(string $key): mixed
    {
        self::assertKnown($key);
        $stored = self::stored();

        return array_key_exists($key, $stored) ? $stored[$key] : config(self::DEFINITIONS[$key]['config']);
    }

    /** True se il valore in vigore viene dal pannello e non dal .env. */
    public static function overridden(string $key): bool
    {
        self::assertKnown($key);

        return array_key_exists($key, self::stored());
    }

    /** @return array<string, mixed> tutti i valori in vigore, per riempire il modulo */
    public static function all(): array
    {
        return collect(array_keys(self::DEFINITIONS))->mapWithKeys(fn (string $k) => [$k => self::value($k)])->all();
    }

    /**
     * Salva le modifiche (solo le chiavi cambiate), scrive il log con prima/dopo e applica subito la configurazione.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{prima: mixed, dopo: mixed}> cosa è cambiato
     *
     * @throws ValidationException
     */
    public static function update(array $values, ?string $reason = null): array
    {
        $rules = [];
        foreach ($values as $key => $_) {
            self::assertKnown((string) $key);
            $def = self::DEFINITIONS[$key];
            $r = is_array($def['rules']) ? $def['rules'] : explode('|', $def['rules']);
            array_unshift($r, ($def['nullable'] ?? false) ? 'nullable' : 'required');
            $rules[$key] = $r;
        }
        $clean = Validator::make($values, $rules, [], collect(self::DEFINITIONS)->map(fn ($d) => $d['label'])->all())->validate();

        $changes = [];
        $userId = Auth::id();
        DB::transaction(function () use ($clean, &$changes, $userId): void {
            foreach ($clean as $key => $new) {
                $new = self::cast($key, $new);
                $old = self::value($key);
                if ($old === $new) {
                    continue;
                }
                DB::table('system_settings')->updateOrInsert(['key' => $key], [
                    'value' => json_encode($new, JSON_UNESCAPED_UNICODE), 'updated_by' => $userId, 'updated_at' => now(), 'created_at' => now(),
                ]);
                $changes[$key] = ['prima' => $old, 'dopo' => $new];
            }
        });
        if ($changes !== []) {
            Cache::forget(self::CACHE_KEY);
            self::applyToConfig();
            AuditLog::record('settings.updated', 'Impostazioni di sistema modificate: '.implode(', ', array_map(fn ($k) => self::DEFINITIONS[$k]['label'], array_keys($changes))), ['SystemSettings', 'sistema'], $changes, $reason);
        }

        return $changes;
    }

    /** Riporta un'impostazione al valore del .env / config (cancella l'override). */
    public static function reset(string $key, ?string $reason = null): void
    {
        self::assertKnown($key);
        if (! self::overridden($key)) {
            return;
        }
        $old = self::value($key);
        DB::table('system_settings')->where('key', $key)->delete();
        Cache::forget(self::CACHE_KEY);
        // il valore del .env si ricarica solo al prossimo avvio: l'audit registra comunque l'operazione
        AuditLog::record('settings.reset', 'Impostazione ripristinata al valore del file di configurazione: '.self::DEFINITIONS[$key]['label'], ['SystemSettings', $key], ['prima' => $old], $reason);
    }

    private static function cast(string $key, mixed $v): mixed
    {
        return match (self::DEFINITIONS[$key]['type']) {
            'bool' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $v,
            default => ($v === null || $v === '') ? (self::DEFINITIONS[$key]['nullable'] ?? false ? null : '') : trim((string) $v),
        };
    }

    private static function assertKnown(string $key): void
    {
        if (! isset(self::DEFINITIONS[$key])) {
            throw new \InvalidArgumentException("Impostazione sconosciuta: {$key}");
        }
    }
}
