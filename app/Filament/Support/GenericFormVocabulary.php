<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\CalculationRun;
use App\Models\ComplianceCase;
use App\Models\Consultant;
use App\Models\CorporateEvent;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\CompPlanVersion;
use App\Models\Discount;
use App\Models\Download;
use App\Models\InheritanceCase;
use App\Models\Invoice;
use App\Models\IvdContract;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Period;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PvMovement;
use App\Models\RankAward;
use App\Models\Settlement;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Support\StatusLabels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Vocabolario del modulo di modifica automatico (GenericForm): etichette italiane dei campi, scelte per
 * stati e tipi, e collegamenti ai record di altre tabelle (utente, incaricato, ordine, prodotto…).
 * I codici nel database restano in inglese: qui si traducono solo i testi mostrati.
 */
final class GenericFormVocabulary
{
    /** Etichette complete, valide per qualsiasi tabella. */
    private const LABELS = [
        'number' => 'Numero', 'code' => 'Codice', 'name' => 'Nome', 'title' => 'Titolo', 'description' => 'Descrizione', 'notes' => 'Note', 'note' => 'Note',
        'status' => 'Stato', 'type' => 'Tipo', 'kind' => 'Tipologia', 'category' => 'Categoria', 'audience' => 'Destinatari', 'source' => 'Origine', 'severity' => 'Gravità',
        'outcome' => 'Esito', 'reason' => 'Motivo', 'message' => 'Messaggio', 'body' => 'Testo', 'subject' => 'Oggetto', 'slug' => 'Indirizzo breve (slug)',
        'amount' => 'Importo', 'net' => 'Netto', 'vat' => 'IVA', 'total' => 'Totale', 'gross' => 'Lordo', 'fee' => 'Commissione', 'fee_amount' => 'Commissione',
        'fee_method' => 'Metodo di pagamento della commissione', 'fee_paid_at' => 'Commissione pagata il', 'fee_marked_by' => 'Commissione registrata da',
        'method' => 'Metodo di pagamento', 'payment_method' => 'Metodo di pagamento', 'country_code' => 'Paese', 'billing' => 'Dati di fatturazione',
        'lines' => 'Prodotti', 'for_others' => 'Ordine per altri destinatari', 'commitment_months' => 'Impegno (mesi)', 'free_shipping' => 'Spedizione gratuita',
        'early_cancel' => 'Disdetta anticipata', 'cycles_done' => 'Cicli completati', 'retry_count' => 'Tentativi di addebito', 'last_error' => 'Ultimo errore',
        'skip_months' => 'Mesi da saltare', 'next_run_at' => 'Prossimo addebito', 'started_at' => 'Iniziato il', 'paused_at' => 'In pausa dal', 'cancelled_at' => 'Annullato il',
        'since' => 'Dal', 'terminated_at' => 'Cessato il', 'termination_reason' => 'Motivo della cessazione',
        'referral_code' => 'Codice referral', 'person_key' => 'Chiave anagrafica', 'original_sponsor_id' => 'Sponsor originale', 'sponsor_id' => 'Sponsor',
        'onboarding_complete' => 'Onboarding completato', 'onboarding_completed_at' => 'Onboarding completato il', 'bonus_hold' => 'Compensi bloccati',
        'rank_cap_code' => 'Qualifica massima consentita', 'previous_rank_code' => 'Qualifica precedente', 'rank_code' => 'Qualifica', 'min_rank_code' => 'Qualifica minima',
        'rank_hold_until' => 'Qualifica garantita fino al', 'rank_hold_reason' => 'Motivo della qualifica garantita', 'kyc_status' => 'Verifica identità',
        'iban' => 'IBAN', 'iban_holder' => 'Intestatario IBAN', 'badge_number' => 'Numero tesserino', 'badge_valid_until' => 'Tesserino valido fino al', 'badge_path' => 'File del tesserino',
        'network_since' => 'Nella rete dal', 'fiscal_regime' => 'Regime fiscale', 'regime' => 'Regime fiscale', 'year' => 'Anno', 'year_index' => 'Anno di rinnovo',
        'issued_at' => 'Emesso il', 'due_at' => 'Scadenza', 'paid_at' => 'Pagato il', 'starts_at' => 'Inizio', 'ends_at' => 'Fine', 'closed_at' => 'Chiuso il',
        'registered_at' => 'Registrato il', 'requested_at' => 'Richiesto il', 'decided_at' => 'Deciso il', 'decided_by' => 'Deciso da', 'decision_note' => 'Nota della decisione',
        'opened_at' => 'Aperto il', 'opened_by' => 'Aperto da', 'event_at' => 'Data dell\'evento', 'occurred_at' => 'Avvenuto il', 'achieved_at' => 'Raggiunto il',
        'effective_from' => 'Valido dal', 'effective_to' => 'Valido fino al', 'active_from' => 'Attivo dal', 'active_to' => 'Attivo fino al', 'active' => 'Attivo',
        'death_date' => 'Data del decesso', 'birth_date' => 'Data di nascita', 'birth_place' => 'Luogo di nascita',
        'balance_at_decision' => 'Saldo al momento della decisione', 'balance_follows_account' => 'Il saldo segue l\'account', 'balance_after' => 'Saldo dopo il movimento',
        'assignor_user_id' => 'Utente cedente', 'assignee_user_id' => 'Utente cessionario', 'new_contract_id' => 'Nuovo contratto',
        'buyer_user_id' => 'Acquirente', 'buyer_type' => 'Tipo di acquirente', 'beneficiary_consultant_id' => 'Incaricato beneficiario', 'beneficiary_id' => 'Beneficiario',
        'subtotal_net' => 'Imponibile prodotti', 'shipping_net' => 'Spedizione (netto)', 'shipping_vat_rate' => 'Aliquota IVA spedizione', 'vat_total' => 'IVA totale',
        'vat_rate' => 'Aliquota IVA', 'pv_total' => 'PV totali', 'cv_total' => 'CV totali', 'shipped_at' => 'Spedito il', 'delivered_at' => 'Consegnato il', 'processing_at' => 'In preparazione dal',
        'tracking_code' => 'Codice di tracciamento', 'carrier' => 'Corriere', 'shipping_waived' => 'Spedizione offerta', 'discount_net' => 'Sconto (netto)',
        'shipping_discount_net' => 'Sconto sulla spedizione (netto)', 'discounts' => 'Sconti applicati', 'kmoney_amount' => 'Importo pagato con KMoney',
        'kmoney_rest_method' => 'Metodo per il resto (KMoney)', 'shipping_refunded_net' => 'Spedizione rimborsata (netto)', 'subscription_id' => 'Abbonamento',
        'qty' => 'Quantità', 'qty_refunded' => 'Quantità rimborsata', 'unit_net' => 'Prezzo unitario (netto)', 'sku' => 'Codice prodotto (SKU)',
        'pv' => 'PV', 'cv' => 'CV', 'pvf' => 'PVF', 'pvc' => 'PVC', 'pct' => 'Percentuale', 'level' => 'Livello', 'depth' => 'Profondità',
        'pv_kind' => 'Tipo di PV', 'vap_mode' => 'Modalità VAP', 'vap_value' => 'Valore VAP', 'vap_amount' => 'Importo VAP', 'validity_months' => 'Validità (mesi)', 'client_count' => 'Numero clienti',
        'sink_status' => 'Stato invio', 'sink_ref' => 'Riferimento invio', 'sink_error' => 'Errore di invio', 'buyer' => 'Dati dell\'acquirente',
        'provider' => 'Fornitore', 'provider_ref' => 'Riferimento del fornitore', 'external_ref' => 'Riferimento esterno', 'reference' => 'Riferimento',
        'withholding' => 'Ritenuta', 'withholding_amount' => 'Ritenuta d\'acconto', 'inps_amount' => 'Contributo INPS', 'gross_amount' => 'Importo lordo', 'taxable_base' => 'Base imponibile',
        'inps_base' => 'Base INPS', 'inps_total' => 'INPS totale', 'inps_worker' => 'INPS a carico dell\'incaricato', 'inps' => 'INPS', 'params' => 'Parametri di calcolo',
        'dispute_deadline_at' => 'Termine per contestare', 'disputed_at' => 'Contestato il', 'accepted_at' => 'Accettato il', 'resolved_at' => 'Risolto il', 'outcome_note' => 'Nota sull\'esito',
        'fiscal_year' => 'Anno fiscale', 'published_at' => 'Pubblicato il', 'pinned' => 'In evidenza', 'featured' => 'In evidenza', 'author_label' => 'Autore',
        'excerpt' => 'Anteprima', 'location' => 'Luogo', 'online_url' => 'Indirizzo online', 'capacity' => 'Posti disponibili', 'registration_deadline' => 'Iscrizioni entro il',
        'file_path' => 'File', 'path' => 'File', 'original_name' => 'Nome originale del file', 'mime' => 'Tipo di file', 'size' => 'Dimensione (byte)', 'watermark' => 'Filigrana',
        'watermarked' => 'Con filigrana', 'ip' => 'Indirizzo IP', 'user_agent' => 'Browser', 'reviewed_by' => 'Verificato da', 'reviewed_at' => 'Verificato il',
        'uploaded_by' => 'Caricato da', 'applied_by' => 'Applicato da', 'lifted_at' => 'Revocato il', 'lifted_by' => 'Revocato da', 'lift_reason' => 'Motivo della revoca',
        'had_hold_before' => 'Aveva già un blocco', 'estate_amount' => 'Importo dell\'eredità', 'liquidated_at' => 'Liquidato il', 'reported_by' => 'Segnalato da',
        'reported_at' => 'Segnalato il', 'verified_by' => 'Verificato da', 'verified_at' => 'Verificato il', 'full_name' => 'Nome e cognome', 'tax_code' => 'Codice fiscale',
        'share_percent' => 'Quota (%)', 'paid_by' => 'Pagato da', 'prepared_by' => 'Preparato da', 'cancel_reason' => 'Motivo dell\'annullamento', 'exported_at' => 'Esportato il',
        'claims' => 'Affermazioni dichiarate', 'url' => 'Indirizzo web', 'shared' => 'Condiviso', 'valid_until' => 'Valido fino al',
        'submitted_by_consultant_id' => 'Proposto da (incaricato)', 'trigger_product_id' => 'Prodotto che attiva la promozione',
        'trigger_qty' => 'Quantità che attiva la promozione', 'gift_product_id' => 'Prodotto in omaggio', 'gift_qty' => 'Quantità in omaggio', 'repeatable' => 'Ripetibile',
        'comp_plan_version_id' => 'Versione del piano compensi', 'official_run_id' => 'Calcolo ufficiale', 'closed_by' => 'Chiuso da', 'payouts_prepared_at' => 'Pagamenti preparati il',
        'accrued' => 'Maturato', 'held' => 'Trattenuto', 'lines_count' => 'Numero di righe', 'wallet_credited_at' => 'Accreditato sul portafoglio il',
        'bonus_key' => 'Tipo di bonus', 'latest_amount' => 'Ultimo importo calcolato', 'engine_status' => 'Esito del calcolo', 'explanation' => 'Spiegazione',
        'calculated_at' => 'Calcolato il', 'confirmed_at' => 'Confermato il', 'confirmed_by' => 'Confermato da', 'payment_reference' => 'Riferimento del pagamento',
        'last_message_at' => 'Ultimo messaggio il', 'assigned_to' => 'Assegnato a', 'related_type' => 'Tipo di elemento collegato', 'related_id' => 'ID dell\'elemento collegato',
        'amount_net' => 'Importo (netto)', 'min_weight_g' => 'Peso minimo (g)', 'max_weight_g' => 'Peso massimo (g)', 'weight_g' => 'Peso (g)',
        'gross_pr' => 'PR lordi', 'due_by' => 'Da pagare entro il', 'approved_at' => 'Approvato il', 'rejection_reason' => 'Motivo del rifiuto', 'requested_by' => 'Richiesto da',
        'types' => 'Documenti richiesti', 'last_reminder_at' => 'Ultimo promemoria il', 'reminders_sent' => 'Promemoria inviati', 'fulfilled_at' => 'Completato il',
        'canceled_at' => 'Annullato il', 'reverses_movement_id' => 'Movimento stornato', 'excluded_from_bonus' => 'Escluso dai bonus', 
        'template_version' => 'Versione del modello', 'signature_provider' => 'Servizio di firma', 'signature_ref' => 'Riferimento della firma', 'signed_at' => 'Firmato il',
        'evidence' => 'Prove della firma', 'pdf_path' => 'File PDF', 'expires_at' => 'Scade il', 'term_index' => 'Numero del rinnovo', 'accepted_by' => 'Accettato da',
        'rejected_at' => 'Rifiutato il', 'rejection_reasons' => 'Motivi del rifiuto', 'odoo' => 'Dati da Odoo', 'portal' => 'Dati dal portale', 'subject_ref' => 'Riferimento',
        'subject_name' => 'Nome', 'resolution_note' => 'Nota di risoluzione', 'resolved_by' => 'Risolto da', 'sort_order' => 'Ordine di visualizzazione', 'parent_id' => 'Elemento superiore',
        'product_ids' => 'Prodotti', 'category_ids' => 'Categorie', 'min_subtotal' => 'Importo minimo', 'min_qty' => 'Quantità minima', 'usage_limit' => 'Utilizzi massimi',
        'usage_limit_per_user' => 'Utilizzi massimi per utente', 'combinable' => 'Cumulabile', 'applies_to' => 'Si applica a', 'tiers' => 'Scaglioni', 'value' => 'Valore',
        'new_contract' => 'Nuovo contratto', 'data' => 'Dati', 'content_hash' => 'Impronta del contenuto', 'approved_by' => 'Approvato da', 'activated_at' => 'Attivato il',
        'request_reason' => 'Motivo della richiesta', 'requested_effective_from' => 'Data di decorrenza richiesta', 'requested_by_label' => 'Richiesto da',
        'registered_by' => 'Registrato da', 'created_by' => 'Creato da', 'updated_by' => 'Modificato da', 'actor_id' => 'Utente', 'actor_label' => 'Utente',
        'totals' => 'Totali', 'warnings' => 'Avvisi', 'decisions' => 'Decisioni', 'error' => 'Errore', 'finished_at' => 'Terminato il', 'input_hash' => 'Impronta dei dati',
    ];

    /** Parole singole per comporre l'etichetta dei campi non elencati sopra. */
    private const WORDS = [
        'id' => '', 'user' => 'utente', 'consultant' => 'incaricato', 'sponsor' => 'sponsor', 'original' => 'originale', 'order' => 'ordine', 'item' => 'riga',
        'period' => 'periodo', 'product' => 'prodotto', 'invoice' => 'fattura', 'credit' => 'nota di credito', 'note' => 'nota', 'payout' => 'pagamento', 'ticket' => 'ticket',
        'settlement' => 'liquidazione', 'subscription' => 'abbonamento', 'payment' => 'pagamento', 'withdrawal' => 'prelievo', 'request' => 'richiesta', 'compliance' => 'caso',
        'case' => 'caso', 'inheritance' => 'successione', 'plan' => 'piano', 'version' => 'versione', 'download' => 'download', 'event' => 'evento', 'corporate' => 'aziendale',
        'discount' => 'sconto', 'contract' => 'contratto', 'ivd' => 'IVD', 'run' => 'calcolo', 'movement' => 'movimento', 'source' => 'origine', 'from' => 'da', 'to' => 'a',
        'beneficiary' => 'beneficiario', 'assigned' => 'assegnato', 'assignor' => 'cedente', 'assignee' => 'cessionario', 'buyer' => 'acquirente', 'recipient' => 'destinatario',
        'category' => 'categoria', 'customer' => 'cliente', 'reverses' => 'storna', 'award' => 'premio', 'rank' => 'qualifica', 'by' => 'da', 'trigger' => 'attivazione',
        'gift' => 'omaggio', 'kit' => 'kit', 'component' => 'componente', 'new' => 'nuovo', 'official' => 'ufficiale', 'parent' => 'superiore',
        'amount' => 'importo', 'date' => 'data', 'count' => 'numero', 'months' => 'mesi', 'rate' => 'aliquota', 'method' => 'metodo', 'holder' => 'intestatario',
        'name' => 'nome', 'code' => 'codice', 'path' => 'file', 'hash' => 'impronta', 'label' => 'nome', 'status' => 'stato', 'type' => 'tipo', 'created' => 'creato',
        'updated' => 'aggiornato', 'last' => 'ultimo', 'next' => 'prossimo', 'first' => 'primo', 'total' => 'totale', 'net' => 'netto', 'gross' => 'lordo', 'fee' => 'commissione',
        'tax' => 'imposta', 'iban' => 'IBAN', 'phone' => 'telefono', 'address' => 'indirizzo', 'city' => 'città', 'zip' => 'CAP', 'province' => 'provincia', 'company' => 'azienda',
        'pec' => 'PEC', 'sdi' => 'SDI', 'is' => '', 'has' => '', 'qty' => 'quantità', 'unit' => 'unitario', 'frozen' => 'bloccato',
        'list' => 'listino', 'default' => 'predefinito', 'recovery' => 'recupero', 'link' => 'collegamento', 'at' => '', 'in' => 'in', 'of' => 'di', 'the' => '',
    ];

    /** Stato "al participio" usato per le date che finiscono in _at ("paid_at" → "Pagato il"). */
    private const PARTICIPLES = [
        'paid' => 'Pagato il', 'approved' => 'Approvato il', 'requested' => 'Richiesto il', 'decided' => 'Deciso il', 'resolved' => 'Risolto il', 'closed' => 'Chiuso il',
        'cancelled' => 'Annullato il', 'canceled' => 'Annullato il', 'issued' => 'Emesso il', 'published' => 'Pubblicato il', 'reviewed' => 'Verificato il', 'due' => 'Scadenza',
        'starts' => 'Inizio', 'ends' => 'Fine', 'started' => 'Iniziato il', 'finished' => 'Terminato il', 'occurred' => 'Avvenuto il', 'registered' => 'Registrato il',
        'achieved' => 'Raggiunto il', 'calculated' => 'Calcolato il', 'confirmed' => 'Confermato il', 'signed' => 'Firmato il', 'expires' => 'Scade il', 'accepted' => 'Accettato il',
        'disputed' => 'Contestato il', 'exported' => 'Esportato il', 'completed' => 'Completato il', 'terminated' => 'Cessato il', 'activated' => 'Attivato il', 'rejected' => 'Rifiutato il',
        'lifted' => 'Revocato il', 'liquidated' => 'Liquidato il', 'verified' => 'Verificato il', 'reported' => 'Segnalato il', 'paused' => 'In pausa dal', 'uploaded' => 'Caricato il',
        'created' => 'Creato il', 'updated' => 'Aggiornato il', 'sent' => 'Inviato il', 'opened' => 'Aperto il', 'shipped' => 'Spedito il', 'delivered' => 'Consegnato il',
    ];

    /** Stati e tipi conosciuti: "tabella.colonna" => [codice => etichetta]. Solo dove l'elenco è completo. */
    private const OPTIONS = [
        'subscriptions.status' => ['pending' => 'In attesa 1° pagamento', 'active' => 'Attivo', 'paused' => 'In pausa', 'cancelled' => 'Annullato'],
        'subscriptions.payment_method' => ['card' => 'Carta', 'kmoney' => 'KMoney'],
        'orders.status' => [
            'pending_payment' => 'In attesa di pagamento', 'paid' => 'Pagato', 'processing' => 'In preparazione', 'shipped' => 'Spedito', 'delivered' => 'Consegnato',
            'cancelled' => 'Annullato', 'partially_refunded' => 'Reso parziale', 'refunded' => 'Reso',
        ],
        'orders.payment_method' => ['card' => 'Carta', 'transfer' => 'Bonifico', 'kmoney' => 'KMoney'],
        'orders.kmoney_rest_method' => ['card' => 'Carta', 'transfer' => 'Bonifico'],
        'orders.buyer_type' => ['customer' => 'Cliente', 'consultant' => 'Incaricato'],
        'payouts.status' => ['to_pay' => 'Da pagare', 'paid' => 'Pagato', 'cancelled' => 'Annullato'],
        'tickets.status' => ['open' => 'Da rispondere', 'answered' => 'Risposto', 'closed' => 'Chiuso'],
        'tickets.category' => Ticket::CATEGORIES,
        'consultants.status' => ['pending' => 'In attesa', 'active' => 'Attivo', 'suspended' => 'Sospeso', 'terminated' => 'Cessato'],
        'consultants.kyc_status' => ['none' => 'Non richiesta', 'submitted' => 'Inviata', 'verified' => 'Verificata', 'rejected' => 'Rifiutata'],
        'consultants.fiscal_regime' => ['occasional' => 'Con ritenuta', 'vat_holder' => 'Partita IVA'],
        'commission_receipts.regime' => ['occasional' => 'Con ritenuta', 'vat_holder' => 'Partita IVA'],
        'periods.status' => ['open' => 'Aperto', 'calculating' => 'In calcolo', 'review' => 'In revisione', 'closed' => 'Chiuso'],
        'compliance_cases.status' => ['open' => 'Aperto', 'investigating' => 'In istruttoria', 'sanctioned' => 'Chiuso con sanzione', 'dismissed' => 'Archiviato'],
        'compliance_cases.category' => ComplianceCase::CATEGORIES,
        'account_transfers.status' => ['awaiting_payment' => 'In attesa della fee', 'paid' => 'Da decidere', 'approved' => 'Approvata', 'rejected' => 'Respinta', 'cancelled' => 'Annullata'],
        'sanctions.status' => ['active' => 'In corso', 'lifted' => 'Revocata', 'expired' => 'Scaduta'],
        'sanctions.type' => ['warning' => 'Richiamo scritto', 'commission_hold' => 'Blocco dei compensi', 'suspension' => 'Sospensione', 'termination' => 'Cessazione'],
        'withdrawal_requests.status' => ['requested' => 'Richiesto', 'approved' => 'Approvato', 'paid' => 'Pagato', 'rejected' => 'Rifiutato', 'cancelled' => 'Annullato'],
        'structure_bonuses.status' => ['calculated' => 'Calcolato', 'confirmed' => 'Confermato', 'paid' => 'Pagato', 'cancelled' => 'Annullato'],
        'invoices.sink_status' => ['pending' => 'Da inviare', 'sent' => 'Inviata', 'error' => 'Errore'],
        'credit_notes.sink_status' => ['pending' => 'Da inviare', 'sent' => 'Inviata', 'error' => 'Errore'],
        'invoices.type' => ['invoice' => 'Fattura', 'receipt' => 'Ricevuta'],
        'promo_materials.status' => ['pending' => 'Da approvare', 'approved' => 'Approvato', 'rejected' => 'Non approvato', 'withdrawn' => 'Ritirato'],
        'corporate_events.status' => ['draft' => 'Bozza', 'published' => 'Pubblicato', 'cancelled' => 'Annullato'],
        'corporate_events.kind' => CorporateEvent::KINDS,
        'wallet_transactions.type' => WalletTransaction::TYPES,
        'import_reconciliations.severity' => ['error' => 'Bloccante', 'warning' => 'Da verificare', 'info' => 'Informativa'],
        'inheritance_cases.outcome' => ['succession' => 'Subentro di un erede', 'termination' => 'Cessato'],
        'pv_movements.status' => ['confirmed' => 'Confermato (pagamento incassato)', 'pending_payment' => 'In attesa di pagamento', 'cancelled' => 'Annullato'],
    ];

    /** Collegamenti ai record di altre tabelle: nome base => [modello, colonne mostrate/cercate, separatore]. */
    private const RELATIONS = [
        'user' => [User::class, ['name', 'email'], ' · '],
        'consultant' => [Consultant::class, ['code', 'name'], ' — '],
        'product' => [Product::class, ['sku', 'name'], ' — '],
        'order' => [Order::class, ['number'], ''],
        'order_item' => [OrderItem::class, ['name'], ''],
        'period' => [Period::class, ['code'], ''],
        'invoice' => [Invoice::class, ['number'], ''],
        'credit_note' => [CreditNote::class, ['number'], ''],
        'ticket' => [Ticket::class, ['number', 'subject'], ' — '],
        'compliance_case' => [ComplianceCase::class, ['number'], ''],
        'inheritance_case' => [InheritanceCase::class, ['number'], ''],
        'comp_plan_version' => [CompPlanVersion::class, ['name'], ''],
        'category' => [ProductCategory::class, ['name'], ''],
        'corporate_event' => [CorporateEvent::class, ['title'], ''],
        'download' => [Download::class, ['title'], ''],
        'discount' => [Discount::class, ['name', 'code'], ' — '],
        'payout' => [Payout::class, [], ''],
        'settlement' => [Settlement::class, [], ''],
        'subscription' => [Subscription::class, [], ''],
        'payment' => [Payment::class, [], ''],
        'withdrawal_request' => [WithdrawalRequest::class, [], ''],
        'calculation_run' => [CalculationRun::class, [], ''],
        'pv_movement' => [PvMovement::class, [], ''],
        'ivd_contract' => [IvdContract::class, [], ''],
        'rank_award' => [RankAward::class, [], ''],
        'customer' => [Customer::class, [], ''],
    ];

    /** Nomi di colonna (senza _id) che puntano a un collegamento con un nome diverso. */
    private const ALIASES = [
        'assignor_user' => 'user', 'assignee_user' => 'user', 'buyer_user' => 'user', 'recipient_user' => 'user', 'requested_by' => 'user', 'actor' => 'user',
        'assigned_consultant' => 'consultant', 'sponsor' => 'consultant', 'original_sponsor' => 'consultant', 'from_sponsor' => 'consultant', 'to_sponsor' => 'consultant',
        'beneficiary' => 'consultant', 'beneficiary_consultant' => 'consultant', 'source_consultant' => 'consultant', 'submitted_by_consultant' => 'consultant',
        'from_consultant' => 'consultant', 'to_consultant' => 'consultant',
        'trigger_product' => 'product', 'gift_product' => 'product', 'kit_product' => 'product', 'component_product' => 'product',
        'run' => 'calculation_run', 'official_run' => 'calculation_run', 'new_contract' => 'ivd_contract', 'reverses_movement' => 'pv_movement', 'source_movement' => 'pv_movement',
    ];

    /** Colonne di testo in cui i valori sono pochi e ripetuti: si suggeriscono quelli già in uso. */
    private const SUGGESTED = ['status', 'type', 'kind', 'method', 'source', 'category', 'audience', 'outcome', 'provider', 'regime', 'carrier', 'severity'];

    public static function label(string $column): string
    {
        if (isset(self::LABELS[$column])) {
            return self::LABELS[$column];
        }
        if (str_ends_with($column, '_id')) {
            $base = substr($column, 0, -3);
            if (isset(self::LABELS[$base])) {
                return self::LABELS[$base];
            }

            return self::words($base);
        }
        if (str_ends_with($column, '_at')) {
            $stem = substr($column, 0, -3);
            if (isset(self::PARTICIPLES[$stem])) {
                return self::PARTICIPLES[$stem];
            }

            return self::words($stem).' (data)';
        }

        return self::words($column);
    }

    /** @return array<string, string>|null scelte [codice => etichetta], o null se la colonna è libera */
    public static function options(string $table, string $column): ?array
    {
        return self::OPTIONS[$table.'.'.$column] ?? null;
    }

    public static function optionLabel(string $code, array $options): string
    {
        return (string) ($options[$code] ?? StatusLabels::label($code));
    }

    public static function isSuggestible(string $column): bool
    {
        return in_array($column, self::SUGGESTED, true) || str_ends_with($column, '_status') || str_ends_with($column, '_method');
    }

    /** @return array{0: class-string<Model>, 1: list<string>, 2: string}|null */
    public static function relation(string $column, Model $owner, bool $isInteger): ?array
    {
        if (! $isInteger) {
            return null;
        }
        if (in_array($column, ['created_by', 'updated_by', 'resolved_by', 'confirmed_by', 'paid_by'], true)) {
            return self::RELATIONS['user'];
        }
        if (! str_ends_with($column, '_id')) {
            return null;
        }
        $base = substr($column, 0, -3);
        if ($base === 'parent') {
            foreach (self::RELATIONS as $spec) {
                if ($spec[0] === $owner::class) {
                    return $spec;
                }
            }

            return null;
        }
        $key = self::ALIASES[$base] ?? $base;
        $spec = self::RELATIONS[$key] ?? null;

        return $spec !== null && class_exists($spec[0]) ? $spec : null;
    }

    /** Importo in euro? Percentuale? Serve a mostrare il simbolo accanto al numero. */
    public static function numberSuffix(string $column): ?string
    {
        if (preg_match('/^(pv|cv|pr)_|_(pv|cv|pr)(_|$)/', $column) === 1) {
            return null;
        }
        if (preg_match('/(pct|percent)$/', $column) === 1 || str_ends_with($column, '_rate')) {
            return '%';
        }
        if (preg_match('/(^|_)(amount|net|vat|total|gross|fee|withholding|inps|balance|accrued|held|price|estate|taxable|payable)(_|$)/', $column) === 1
            || str_ends_with($column, '_net') || str_ends_with($column, '_amount')) {
            return '€';
        }

        return null;
    }

    private static function words(string $text): string
    {
        $parts = [];
        foreach (explode('_', $text) as $word) {
            $translated = self::WORDS[$word] ?? $word;
            if ($translated !== '') {
                $parts[] = $translated;
            }
        }

        return Str::ucfirst(implode(' ', $parts));
    }
}
