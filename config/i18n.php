<?php

/**
 * Lingue del sito. L'elenco delle lingue e i testi tradotti stanno nel database (pagine Lingue e Traduzioni del
 * back-office, solo super_admin); qui restano solo le regole tecniche.
 */
return [
    // lingua sorgente: i testi nelle viste sono scritti in questa lingua e non hanno prefisso nell'indirizzo
    'default' => 'it',

    // cookie che ricorda la lingua scelta (non cifrato: contiene solo il codice della lingua)
    'cookie' => 'lm_lang',
    'cookie_days' => 365,

    // primi segmenti di indirizzo delle pagine pubbliche che accettano il prefisso di lingua (/en/negozio).
    // L'area riservata e il back-office non hanno prefisso: usano la lingua scelta (cookie o profilo).
    'public_paths' => [
        'il-prodotto', 'perche-lamess', 'la-scienza', 'lavora-con-noi', 'chi-siamo', 'domande', 'contatti',
        'negozio', 'carrello', 'privacy', 'termini', 'politiche-di-reso', 'piano-compensi', 'regolamento',
        'lettera-di-incarico', 'liberatoria-foto', 'candidati', 'registrazione', 'accedi', 'password-dimenticata', 'come-funziona',
    ],

    // Dentro /area si traducono solo le pagine del negozio per il cliente (carrello, checkout, ordini, abbonamenti):
    // il resto dell'area riservata (incaricati, compensi, rete...) e le pagine personali /i/... restano in italiano (fase 2).
    'translated_area' => ['carrello', 'checkout', 'ordini', 'abbonamenti'],

    // traduzione automatica: fake | deepl | google | anthropic. Le chiavi API stanno solo nel .env.
    'driver' => env('I18N_DRIVER', 'fake'),
    'deepl' => ['key' => env('DEEPL_API_KEY'), 'url' => env('DEEPL_API_URL', 'https://api-free.deepl.com/v2/translate')],
    'google' => ['key' => env('GOOGLE_TRANSLATE_API_KEY')],
    'anthropic' => ['key' => env('ANTHROPIC_API_KEY'), 'model' => env('I18N_ANTHROPIC_MODEL', 'claude-sonnet-5-5')],

    // termini del brand che non si traducono mai
    'glossary' => ['Lamèss', 'LAMÈSS', 'Tasca', 'KMoney', 'KY', 'Kadeo Group', 'KADEO GROUP', 'Stop Time', 'Drink Beauty', 'Pearl', 'Crystal'],

    // mostra anche le traduzioni non ancora approvate dal traduttore (stato «automatica»)
    'show_unreviewed' => true,
];
