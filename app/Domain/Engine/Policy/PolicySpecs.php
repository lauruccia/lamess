<?php

declare(strict_types=1);

namespace App\Domain\Engine\Policy;

/**
 * Registro delle politiche configurabili del motore (decisioni D01…D12).
 *
 * Aggiungere una politica = aggiungere una voce qui: validazione, default, documentazione
 * (php artisan lamess:parametri) e schermata admin la leggono da questo registro.
 *
 * default_status:
 *  - certain         valore documentato senza ambiguità
 *  - client_decided  deciso dal cliente (call del 15/09/2026)
 *  - provisional     default provvisorio: simulabile, ma i periodi che lo usano non vanno
 *                    approvati per pagamenti reali finché la decisione non è confermata
 */
final class PolicySpecs
{
    public const CERTAIN = 'certain';

    public const CLIENT_DECIDED = 'client_decided';

    public const PROVISIONAL = 'provisional';

    /** @return array<string, array{decision:string,label:string,default:string|bool,options:array<string,string>,default_status:string,source:string}> */
    public static function all(): array
    {
        return [
            // D01 Attività
            'activity_points_source' => [
                'decision' => 'D01', 'label' => 'Punti validi per lo stato ATTIVO',
                'default' => 'own_customers_pvf',
                'options' => [
                    'own_customers_pvf_plus_own_pvc' => 'PVF dei propri clienti + PVC dei propri acquisti',
                    'own_customers_pvf' => 'Solo PVF dei propri clienti',
                ],
                'default_status' => self::CLIENT_DECIDED, 'source' => 'PMK 070726 p.24 («100 PVF generati da almeno 1 Cliente»); Laura 02/10/2026. Sostituisce la call del 15/09 (K1=C)',
            ],
            'activity_first_time' => [
                'decision' => 'D01', 'label' => 'Prima soglia raggiunta',
                'default' => 'current_and_next',
                'options' => ['current_and_next' => 'Vale per il mese corrente e il successivo', 'current_only' => 'Solo mese corrente'],
                'default_status' => self::CLIENT_DECIDED, 'source' => 'Call 15/09/2026 (C1); PMK p.24',
            ],
            'activity_month_rule' => [
                'decision' => 'D01', 'label' => 'Mesi successivi: quale mese conta',
                'default' => 'current',
                'options' => [
                    'current' => 'Mese corrente',
                    'previous' => 'Mese precedente (PMK p.14)',
                    'current_or_previous' => 'Corrente oppure precedente',
                ],
                'default_status' => self::PROVISIONAL, 'source' => 'PMK p.14 vs p.24; call 15/09/2026: da chiarire',
            ],
            'activity_first_month_free' => [
                'decision' => 'D01', 'label' => "Primo mese da incaricato attivo d'ufficio (mese solare)",
                'default' => true, 'options' => [],
                'default_status' => self::CERTAIN, 'source' => 'PMK p.14 (N.B.)',
            ],
            // D02/D03 Punti
            'commission_base' => [
                'decision' => 'D03', 'label' => 'Base delle provvigioni',
                'default' => 'pv', 'options' => ['pv' => 'PV', 'cv' => 'CV (promo Double CV di M1)'],
                'default_status' => self::PROVISIONAL, 'source' => 'PMK p.25–28 (esempi su PV); M1 §3 (CV)',
            ],
            'discount_pv_policy' => [
                'decision' => 'D02', 'label' => 'PV su righe scontate',
                'default' => 'catalog',
                'options' => ['proportional' => 'PV ridotti in proporzione allo sconto', 'catalog' => 'PV di catalogo invariati'],
                'default_status' => self::PROVISIONAL, 'source' => 'PMK 070726 p.14 e Webinar s.23–25: il Box vale 100 PV anche a prezzo promo (OPEN-34); catalogo Odoo',
            ],
            'pr_source' => [
                'decision' => 'D03', 'label' => 'Punti che alimentano i PR',
                'default' => 'pv', 'options' => ['pv' => 'PV', 'cv' => 'CV'],
                'default_status' => self::PROVISIONAL, 'source' => 'PMK p.13 (1 PR per ogni PV); M1 §3 (CV carriera) (OPEN-19)',
            ],
            // D04 Direct / Team
            'personal_purchase_direct_beneficiary' => [
                'decision' => 'D04', 'label' => 'Direct Bonus sugli acquisti personali dell’incaricato',
                'default' => 'upline_differential',
                'options' => [
                    'upline_differential' => 'Nessun Direct a chi compra; lo sponsor e la linea sopra maturano la differenza (Team)',
                    'sponsor' => 'Allo sponsor, come vendita diretta',
                    'self' => 'All’incaricato stesso',
                    'none' => 'Nessuno',
                ],
                'default_status' => self::CLIENT_DECIDED, 'source' => 'Laura 02/10/2026 («guadagno sulle vendite, non sugli acquisti propri») (OPEN-35); sostituisce la call del 15/09 (K2=B)',
            ],
            'team_ineligible_upline' => [
                'decision' => 'D05', 'label' => 'Team Bonus con upline non idoneo',
                'default' => 'compress',
                'options' => [
                    'compress' => "Compressione: il differenziale risale all'upline idoneo",
                    'absorb' => "La quota dell'upline non idoneo si perde",
                ],
                'default_status' => self::PROVISIONAL, 'source' => 'PMK p.24 (compressione dinamica) (OPEN-14)',
            ],
            // D05 Compressione e livelli
            'matching_level_counting' => [
                'decision' => 'D05', 'label' => 'Conteggio livelli Matching',
                'default' => 'compressed_active',
                'options' => ['compressed_active' => 'Solo incaricati attivi contano come livello', 'genealogical' => 'Livelli genealogici fissi'],
                'default_status' => self::PROVISIONAL, 'source' => 'PMK p.24, p.29–31 (OPEN-15)',
            ],
            'structure_depth' => [
                'decision' => 'D05', 'label' => 'Qualificati richiesti nelle linee',
                'default' => 'any_depth',
                'options' => ['any_depth' => 'A qualsiasi profondità nella linea', 'direct_only' => 'Solo diretti'],
                'default_status' => self::PROVISIONAL, 'source' => 'PMK p.20 («in livelli sottostanti») (OPEN-09)',
            ],
            'personal_pr_in_fifty_rule' => [
                'decision' => 'D03', 'label' => 'PR personali nella regola del 50%',
                'default' => 'uncapped',
                'options' => ['uncapped' => 'Contati per intero', 'as_line' => 'Limitati come una linea'],
                'default_status' => self::PROVISIONAL, 'source' => 'PMK p.19–20 (OPEN-10)',
            ],
            // D06 KYC / onboarding
            'onboarding_incomplete_indirect' => [
                'decision' => 'D06', 'label' => 'Commissioni indirette con onboarding incompleto',
                'default' => 'not_accrued',
                'options' => ['not_accrued' => 'Non maturano (PMK p.24)', 'accrued_held' => 'Maturano e restano trattenute (M1)'],
                'default_status' => self::PROVISIONAL, 'source' => 'PMK p.24 vs M1 §5 (OPEN-21)',
            ],
            'kyc_held_recovery' => [
                'decision' => 'D06', 'label' => 'Importi trattenuti per KYC alla verifica',
                'default' => 'release_held', 'options' => ['release_held' => 'Si sbloccano', 'forfeit' => 'Si perdono'],
                'default_status' => self::PROVISIONAL, 'source' => 'M1 §5; nessuna regola',
            ],
            // D07 Rank Bonus
            'rank_bonus_skipped_ranks' => [
                'decision' => 'D07', 'label' => 'Qualifiche saltate nello stesso mese',
                'default' => 'pay_all', 'options' => ['pay_all' => 'Premio per ogni qualifica', 'highest_only' => 'Solo la più alta'],
                'default_status' => self::PROVISIONAL, 'source' => 'PMK p.32 (OPEN-05)',
            ],
            'rank_bonus_inactive' => [
                'decision' => 'D07', 'label' => 'Rank Bonus maturato da inattivo',
                'default' => 'hold_n_months',
                'options' => [
                    'hold_n_months' => 'Trattenuto fino a N mesi, poi perso',
                    'hold_until_active' => 'Trattenuto finché torna attivo',
                    'forfeit' => 'Perso',
                ],
                'default_status' => self::PROVISIONAL, 'source' => 'PMK p.32 («per poter riscuotere devi essere attivo») (OPEN-06)',
            ],
            'rank_bonus_installment_start' => [
                'decision' => 'D07', 'label' => 'Decorrenza prima rata',
                'default' => 'achievement_period',
                'options' => ['achievement_period' => 'Mese del raggiungimento', 'next_period' => 'Mese successivo'],
                'default_status' => self::PROVISIONAL, 'source' => 'PMK p.32',
            ],
            'rank_bonus_installment_inactive' => [
                'decision' => 'D07', 'label' => 'Rata in un mese di inattività',
                'default' => 'hold', 'options' => ['hold' => 'Trattenuta', 'pay_anyway' => 'Pagata comunque'],
                'default_status' => self::PROVISIONAL, 'source' => 'PMK p.32',
            ],
            // D08/D09 Fast Start (attivo dal PMK 070726)
            'fsb_rank_basis' => [
                'decision' => 'D08', 'label' => 'Qualifica richiesta dal FSB',
                'default' => 'achieved', 'options' => ['achieved' => 'Raggiunta', 'effective' => 'Efficace'],
                'default_status' => self::PROVISIONAL, 'source' => 'Guida FSB p.3–7; PMK 070726 p.35',
            ],
            'fsb_missed_step' => [
                'decision' => 'D08', 'label' => 'Step mancato',
                'default' => 'independent',
                'options' => ['independent' => 'Gli step successivi restano validi', 'blocks_following' => 'Blocca i successivi'],
                'default_status' => self::PROVISIONAL, 'source' => 'Guida FSB p.4–7 (OPEN-07)',
            ],
            'fsb_step10_deadline' => [
                'decision' => 'D09', 'label' => 'Step 10: termine per i tre incaricati',
                'default' => 'own_window',
                'options' => ['own_window' => 'Step 9 nella loro finestra (210 giorni), nessuna scadenza per lo sponsor (PMK 070726 p.35)'],
                'default_status' => self::PROVISIONAL, 'source' => 'Guida FSB p.7 vs PMK p.35 (OPEN-08)',
            ],
            // K — piano Incaricati (motore kosmos_v1)
            'kosmos_client_count' => [
                'decision' => 'K01', 'label' => 'Come si contano i clienti personali (scaglioni, livelli, qualifiche)',
                'default' => 'period_buyers',
                'options' => [
                    'period_buyers' => 'Clienti distinti con un acquisto confermato nel mese',
                    'cumulative_buyers' => 'Clienti distinti che hanno acquistato almeno una volta fino a fine mese',
                ],
                'default_status' => self::PROVISIONAL, 'source' => 'Presentazione «Seconda parte» s.6–7: non dice se il conteggio è mensile o cumulativo',
            ],
            'kosmos_extended_stop' => [
                'decision' => 'K02', 'label' => 'Indirette estese: dove si fermano',
                'default' => 'same_rank',
                'options' => [
                    'same_rank' => 'Al 5° livello del successivo con la stessa qualifica',
                    'same_or_higher' => 'Al 5° livello del successivo con qualifica uguale o superiore',
                ],
                'default_status' => self::PROVISIONAL, 'source' => 'Presentazione «Seconda parte» s.8 («fino al 5° livello del Ruby seguente»)',
            ],
            'kosmos_own_purchase_as_client' => [
                'decision' => 'K04', 'label' => 'Gli acquisti dell’incaricato valgono come acquisto di un suo cliente',
                'default' => 'on',
                'options' => ['on' => 'Sì: l’incaricato è cliente di sé stesso (conta come cliente per sé stesso e genera compensi); lo sponsor matura come per un cliente di un suo incaricato diretto', 'off' => 'No: gli acquisti personali non generano compensi'],
                'default_status' => self::CLIENT_DECIDED, 'source' => 'Laura 08/10/2026: 5 clienti + acquisto proprio mensile = 6 clienti; deve poterlo disabilitare admin/superadmin',
            ],
            'kosmos_rank_maintenance' => [
                'decision' => 'K05', 'label' => 'Mantenimento della qualifica',
                'default' => 'clients',
                'options' => ['clients' => 'Ogni mese si paga la qualifica più alta per cui i clienti personali sono sufficienti', 'none' => 'Nessun mantenimento: la qualifica raggiunta resta'],
                'default_status' => self::PROVISIONAL, 'source' => 'Laura 08/10/2026: «mantenere i 6 clienti e quindi la qualifica»',
            ],
            // D11 Rettifiche
            'refund_after_close' => [
                'decision' => 'D11', 'label' => 'Reso dopo la chiusura del periodo',
                'default' => 'clawback_next_open',
                'options' => ['clawback_next_open' => 'Storno nel primo periodo aperto', 'no_clawback' => 'Nessun recupero'],
                'default_status' => self::PROVISIONAL, 'source' => 'M1 §1 (refund) (OPEN-26)',
            ],
        ];
    }

    /** @return array<string, string|bool> */
    public static function defaults(): array
    {
        return array_map(static fn (array $s) => $s['default'], self::all());
    }
}
