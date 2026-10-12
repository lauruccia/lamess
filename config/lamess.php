<?php

/** Parametri della rete e dell'anagrafica (modificabili da .env). I parametri del piano compensi sono in config/lamess/*.json. */
return [
    // Reg. 2.10.b: attesa prima di poter tornare incaricato dopo una cessazione
    'reenrollment_days' => (int) env('LAMESS_REENROLLMENT_DAYS', 180),
    // Stati da cui è possibile sponsorizzare nuovi incaricati
    'sponsoring_statuses' => ['active'],

    // Referral (RF-002)
    'referral_cookie_name' => 'lm_ref',
    'referral_cookie_days' => (int) env('LAMESS_REFERRAL_COOKIE_DAYS', 15),
    // OPEN-20: incaricato "casa" per i FAN senza referral (codice attività); null = nessuno
    'default_consultant_code' => env('LAMESS_DEFAULT_CONSULTANT_CODE'),
    // Il cliente può inviare la richiesta di diventare incaricato solo dopo aver pagato il primo ordine (la prima box).
    // Spento di default: il PMK dice che diventare Incaricato non richiede acquisti. Modificabile da Impostazioni di sistema.
    'require_first_box_to_apply' => (bool) env('LAMESS_REQUIRE_FIRST_BOX', false),

    // Dati societari mostrati nel footer (ripresi da lamess.it); modificabili da .env
    'company' => [
        'name' => env('LAMESS_COMPANY_NAME', 'CONSORZIO KADEO GROUP'),
        'brand_owner' => env('LAMESS_COMPANY_BRAND', 'KADEO GROUP'),
        'address' => env('LAMESS_COMPANY_ADDRESS', 'VIA G FERRARIS 63 - AREZZO'),
        'vat' => env('LAMESS_COMPANY_VAT', '02530190517'),
        'pec' => env('LAMESS_COMPANY_PEC', 'kadeogroup@pec.it'),
        'email' => env('LAMESS_COMPANY_EMAIL', 'info@lamess.it'),
    ],

    // Rubrica indirizzi di spedizione per utente (la fatturazione è un solo indirizzo, nel profilo)
    'max_shipping_addresses' => 10,

    // Codice attività incaricato: prefisso + numero progressivo
    'consultant_code_prefix' => env('LAMESS_CODE_PREFIX', 'LM'),
    'consultant_code_width' => 5,

    // Lettera di Incarico IVD
    'ivd_template_version' => env('LAMESS_IVD_TEMPLATE', '2026-1'),
    'ivd_term_months' => 12,
    'adult_age' => 18,

    // Firma elettronica: driver 'fake' (sviluppo) | futuri provider reali dietro SignatureProvider
    'signature_driver' => env('LAMESS_SIGNATURE_DRIVER', 'fake'),

    // KYC / onboarding (RF-014)
    'kyc_driver' => env('LAMESS_KYC_DRIVER', 'manual'),          // manual | stripe_identity
    'kyc_required_documents' => ['photo', 'id_document'],
    // Richiesta documenti dall'admin: giorni per rispondere (0 = nessuna scadenza) e promemoria (giorni dalla richiesta)
    'kyc_request_due_days' => (int) env('LAMESS_KYC_REQUEST_DUE_DAYS', 7),
    'kyc_request_reminder_days' => [3, 6],
    'kyc_document_types' => [
        'photo' => 'Foto formato tessera (abbigliamento professionale)',
        'id_document' => "Documento d'identità",
        'tax_code_document' => 'Codice fiscale / tessera sanitaria',
        'iban_proof' => 'Prova di intestazione IBAN',
    ],

    // Canone annuale (RF-020…023)
    'annual_fee_enabled' => (bool) env('LAMESS_ANNUAL_FEE_ENABLED', true),   // spento: nessun canone, i rinnovi sono gratuiti
    'annual_fee_amount' => env('LAMESS_ANNUAL_FEE', '19.00'),               // IVA compresa
    'annual_fee_first_paid_year' => 2,           // il primo anno è gratuito
    'annual_fee_grace_days' => (int) env('LAMESS_FEE_GRACE_DAYS', 0), // OPEN-24
    'annual_fee_reminder_days' => [30, 7, 1],

    // Invio email: SPENTO finché il lavoro non è concluso. Con false ogni messaggio (notifiche, reset password, ecc.)
    // viene annullato prima della consegna e scritto solo nel log (storage/logs/laravel.log).
    'mail_enabled' => (bool) env('LAMESS_MAIL_ENABLED', false),

    // Chiave per la catena di hash del log di audit (vuoto = derivata da APP_KEY). Cambiarla rende illeggibile il log precedente.
    'audit_key' => env('LAMESS_AUDIT_KEY'),

    // Installazione guidata (cPanel, senza terminale): la pagina /installazione esiste SOLO se qui c'è un token di almeno 24 caratteri,
    // finché non esiste un super_admin e non c'è il file storage/app/installed.lock. Dopo l'uso si toglie il token dal .env.
    'install_token' => env('LAMESS_INSTALL_TOKEN'),

    // Sicurezza back-office: 2FA (app di autenticazione) obbligatorio per tutti gli utenti del pannello.
    'admin_2fa_required' => (bool) env('ADMIN_2FA_REQUIRED', true),

    // Negozio e fatturazione (F2)
    'eur_per_pv' => env('LAMESS_EUR_PER_PV', '1.23'),            // PV suggerito = imponibile / 1,23 (RF-040)
    'default_vat_rate' => '22.00',
    'shop_countries' => ['IT'],                                   // mercati aperti (RF-036)
    'payment_driver' => env('LAMESS_PAYMENT_DRIVER', 'fake'),     // fake | stripe
    'kmoney_driver' => env('LAMESS_KMONEY_DRIVER', 'fake'),   // fake | api | off
    // Connessione a KMoney (kmoney-app): OAuth2 + mandato di pagamento. Le credenziali arrivano da KMoney (client «kshop»).
    'kmoney' => [
        'base_url' => rtrim((string) env('KMONEY_BASE_URL', ''), '/'),
        'client_id' => env('KMONEY_CLIENT_ID'),
        'client_secret' => env('KMONEY_CLIENT_SECRET'),
        'api_token' => env('KMONEY_API_TOKEN'),             // token API del conto venditore (abilità write): serve ai rimborsi
        'seller_account' => env('KMONEY_SELLER_ACCOUNT'),   // numero di conto KY (KYB…) di Lamèss che incassa
        'webhook_secret' => env('KMONEY_WEBHOOK_SECRET'),
        'register_url' => env('KMONEY_REGISTER_URL'),       // pagina di registrazione di KMoney; vuoto = «{base_url}/register»
    ],
    // Vantaggio KMoney mostrato nel checkout: ricaricando 100 € si ricevono 100 + N % di KY (25 → 120 € = 150 KY). 0 = nessun messaggio.
    'kmoney_bonus_percent' => 25,
    // Metodi di pagamento offerti nel checkout (modificabili da Negozio → Pagamenti e spedizioni)
    'payments' => ['card' => true, 'transfer' => true, 'kmoney' => true],
    'bank_transfer_instructions' => env('LAMESS_BANK_INSTRUCTIONS', 'IBAN da configurare — causale: numero ordine'),
    'auto_open_period' => true,                                   // apre il periodo del mese al primo pagamento
    'private_document' => env('LAMESS_PRIVATE_DOCUMENT', 'invoice'), // invoice | receipt per i privati
    'invoice_sink_driver' => env('LAMESS_INVOICE_SINK', 'fake'),  // fake | file | mail
    'invoice_prefix' => 'FT',
    'credit_note_prefix' => 'NC',
    'return_window_days' => 14,                    // giorni dalla consegna per chiedere un reso
    'order_prefix' => 'ORD',
    // Abbonamento e spedizione gratuita sugli ordini ripetitivi (RF-A01, RF-A03). Modificabili da Impostazioni di sistema.
    'free_shipping_repeat' => [
        'enabled' => true,
        'min_months' => 0,         // impegno minimo in mesi: 0 = nessun impegno, ogni abbonamento ha la spedizione gratuita e si disdice quando si vuole
    ],
    'subscription' => [
        'retry_days' => 1,         // giorni tra un tentativo di addebito e il successivo
        'max_retries' => 3,        // dopo l'ultimo tentativo fallito l'abbonamento va in pausa
        'max_skip_ahead_months' => 3, // il cliente può saltare mesi finché il prossimo addebito non supera questo orizzonte
    ],
    // OPEN-41: a chi si può ordinare "per conto di" in un ordine multiplo (clienti assegnati e incaricati diretti)
    'order_for_others' => true,
    // Ricevute provvigionali e trattenute (Reg. 5.1). VALORI PROVVISORI: vanno validati col commercialista prima dell'uso.
    // Spenti di default: finché sono spenti il bonifico è pari al lordo e non si emettono ricevute.
    // Cessione account (Reg. 9.1) ed eredi (Reg. 9.2)
    'transfer_fee' => env('LAMESS_TRANSFER_FEE', '50.00'),
    'inheritance_limbo_days' => (int) env('LAMESS_INHERITANCE_LIMBO_DAYS', 180), // oltre, senza subentro, l'account cessa

    'content' => [
        // se la filigrana non riesce (es. PDF compresso) il file esce comunque, e il registro dei download lo segnala
        'watermark_fail_open' => (bool) env('LAMESS_WATERMARK_FAIL_OPEN', true),
    ],

    'fiscal' => [
        'receipts_enabled' => (bool) env('LAMESS_RECEIPTS_ENABLED', false),
        'withholding_enabled' => (bool) env('LAMESS_WITHHOLDING_ENABLED', false),
        'withholding_pct' => env('LAMESS_WITHHOLDING_PCT', '23'),          // aliquota della ritenuta d'acconto
        'taxable_pct' => env('LAMESS_TAXABLE_PCT', '78'),                  // quota imponibile delle provvigioni IVD (ritenuta e base INPS)
        'inps_enabled' => (bool) env('LAMESS_INPS_ENABLED', false),
        'inps_threshold' => env('LAMESS_INPS_THRESHOLD', '5000'),          // soglia annua di provvigioni oltre la quale scatta la Gestione Separata
        'inps_rate' => env('LAMESS_INPS_RATE', '33.72'),                   // aliquota contributiva complessiva (PROVVISORIA)
        'inps_worker_share' => env('LAMESS_INPS_WORKER_SHARE', '33.33'),   // quota del contributo a carico dell'incaricato (il resto è del Consorzio)
        'bank_fee' => env('LAMESS_BANK_FEE', '2.50'),                      // commissione trattenuta sui prelievi su richiesta
    ],
    // Reg. 5.2: giorni dalla pubblicazione dell'estratto conto per un reclamo circostanziato; poi accettazione tacita
    'dispute_days' => (int) env('LAMESS_DISPUTE_DAYS', 10),
    // Pubblicare una nuova versione del piano compensi richiede l'approvazione di un secondo amministratore (quattro occhi)
    'plan_four_eyes' => (bool) env('LAMESS_PLAN_FOUR_EYES', true),
    // Chiusura mensile automatica: off | calculate (solo calcolo ufficiale, la revisione e la chiusura restano all'admin) | close (calcola e chiude da sola)
    'period_auto_close' => 'calculate',
    // Data e ora del calcolo mensile delle commissioni: «end» = ultimo giorno del mese, oppure il giorno 1-28 del mese successivo
    'commission_calc_day' => '1',
    'commission_calc_hour' => 1,
    // Bonus di Struttura (piano Incaricati): calcolo settimanale automatico, giorno ISO (1 = lunedì … 7 = domenica) e ora
    'structure_bonus_auto' => false,
    'structure_bonus_weekday' => 1,
    'structure_bonus_hour' => 6,
    // Bonifici SEPA (pain.001): conto dell'azienda da cui partono i pagamenti agli incaricati
    'sepa' => [
        'debtor_name' => env('LAMESS_SEPA_NAME', ''),
        'debtor_iban' => env('LAMESS_SEPA_IBAN', ''),
        'debtor_bic' => env('LAMESS_SEPA_BIC', ''),
    ],
    // Avvisi email ai INCARICATI (mai ai clienti), comunque bloccati se mail_enabled è spento
    'notify_statement_ready' => true,
    'notify_payout_paid' => true,
    'seller' => [
        'name' => env('LAMESS_SELLER_NAME', 'Lamèss — ragione sociale da configurare'),
        'vat_number' => env('LAMESS_SELLER_VAT', ''),
        'address' => env('LAMESS_SELLER_ADDRESS', ''),
        'email' => env('LAMESS_SELLER_EMAIL', ''),
        // dati anagrafici richiesti dalla fattura elettronica
        'tax_code' => env('LAMESS_SELLER_TAX_CODE', ''),
        'zip' => env('LAMESS_SELLER_ZIP', ''),
        'city' => env('LAMESS_SELLER_CITY', ''),
        'province' => env('LAMESS_SELLER_PROVINCE', ''),
        'regime' => env('LAMESS_SELLER_REGIME', 'RF01'),      // RF01 ordinario, RF19 forfettario…
    ],
    'sdi' => [
        'mail_to' => env('LAMESS_SDI_MAIL_TO', ''),           // destinatario del driver «mail»
    ],
];
