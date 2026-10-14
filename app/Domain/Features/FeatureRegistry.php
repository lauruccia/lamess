<?php

declare(strict_types=1);

namespace App\Domain\Features;

/**
 * Elenco CHIUSO delle funzionalità che l'admin può abilitare o disabilitare (tab, viste, link, pulsanti, sezioni).
 * Si dichiara qui, nel codice: il pannello «Funzionalità e viste» le legge da questo elenco e salva solo le scelte.
 * Una chiave non dichiarata non si può scrivere, e per il codice vale sempre «abilitata».
 *
 * Campi:
 *  - type     module | view | tab | section | link | button | widget
 *  - group    gruppo mostrato nel pannello
 *  - parent   chiave di una funzionalità genitore: se il genitore è spento, lo sono anche i figli
 *  - default  stato quando l'admin non ha deciso nulla
 *  - lock     'system' = non si può spegnere da nessuno (resta visibile nel pannello con il lucchetto)
 *  - super    true = riservata al super_admin (l'admin non la vede se è spenta e non può accenderla)
 *  - bypass   true (default) = il super_admin la vede sempre, anche se spenta per gli altri, così non si chiude fuori
 *  - menu     solo per le voci del menu e i blocchi del cruscotto: gruppo in cui compaiono nella scelta «Voci di menu visibili»
 *  - legacy   true = appartiene al vecchio piano di marketing: sparisce per tutti quando l'admin lo nasconde (LegacyPlan)
 *  - menu_label  etichetta breve della voce nella stessa scelta
 */
final class FeatureRegistry
{
    public const GROUP_CARD = 'Scheda incaricato';

    public const GROUP_BACKOFFICE = 'Back-office (menu e liste)';

    /** @return array<string, array{type: string, label: string, group: string, menu?: string, menu_label?: string, help?: string, parent?: string, default?: bool, lock?: string, super?: bool, bypass?: bool}> */
    public static function all(): array
    {
        $card = self::GROUP_CARD;
        $bo = self::GROUP_BACKOFFICE;

        $defs = [
            // ---- Scheda incaricato: tab
            'consultant360.tab.overview' => ['type' => 'tab', 'group' => $card, 'label' => 'Tab «Riepilogo e carriera»', 'help' => 'Avanzamento di carriera, cosa manca per il livello successivo, attività del mese.'],
            'consultant360.tab.profile' => ['type' => 'tab', 'group' => $card, 'label' => 'Tab «Anagrafica e contratto»', 'help' => 'Dati personali, sponsor, contratto IVD, canone annuale, onboarding.'],
            'consultant360.tab.network' => ['type' => 'tab', 'group' => $card, 'label' => 'Tab «Rete»', 'help' => 'Sponsor, storico sponsor, diretti, clienti assegnati.'],
            'consultant360.tab.volumes' => ['legacy' => true, 'type' => 'tab', 'group' => $card, 'label' => 'Tab «Volumi e rank»', 'help' => 'PVF, PVC e PR mese per mese; qualifiche raggiunte e premi.'],
            'consultant360.tab.commissions' => ['type' => 'tab', 'group' => $card, 'label' => 'Tab «Commissioni»', 'help' => 'Righe di commissione per periodo, con la spiegazione.'],
            'consultant360.tab.wallet' => ['type' => 'tab', 'group' => $card, 'label' => 'Tab «Liquidazioni e pagamenti»', 'help' => 'Liquidazioni mensili, pagamenti, IBAN mascherato.'],
            'consultant360.tab.sales' => ['type' => 'tab', 'group' => $card, 'label' => 'Tab «Ordini e movimenti PV»', 'help' => 'Ordini a nome dell’incaricato e ultimi movimenti PV.'],
            'consultant360.tab.documents' => ['type' => 'tab', 'group' => $card, 'label' => 'Tab «Documenti e KYC»', 'help' => 'Documenti caricati, stato di verifica, chi ha verificato.'],
            'consultant360.tab.audit' => ['type' => 'tab', 'group' => $card, 'label' => 'Tab «Audit»', 'help' => 'Registro delle operazioni sull’incaricato. Solo super_admin.', 'lock' => 'system', 'super' => true],
            // ---- Scheda incaricato: parti fisse
            'consultant360.section.timeline' => ['type' => 'section', 'group' => $card, 'label' => 'Colonna «Storico»', 'help' => 'Tutti gli eventi dell’incaricato in ordine di data, con le note interne.'],
            'consultant360.section.notes' => ['type' => 'section', 'group' => $card, 'label' => 'Note interne', 'help' => 'Pulsante «Aggiungi nota» e note fissate in alto.', 'parent' => 'consultant360.section.timeline'],
            'consultant360.section.counters' => ['type' => 'section', 'group' => $card, 'label' => 'Pulsanti-contatore', 'help' => 'La riga di pulsanti con i numeri (ordini, movimenti, liquidazioni…) che aprono le liste filtrate.'],
            // ---- Scheda incaricato: contatori
            'consultant360.counter.directs' => ['type' => 'link', 'group' => $card, 'label' => 'Contatore «Diretti»', 'parent' => 'consultant360.section.counters'],
            'consultant360.counter.customers' => ['type' => 'link', 'group' => $card, 'label' => 'Contatore «Clienti»', 'parent' => 'consultant360.section.counters'],
            'consultant360.counter.orders' => ['type' => 'link', 'group' => $card, 'label' => 'Contatore «Ordini»', 'parent' => 'consultant360.section.counters'],
            'consultant360.counter.movements' => ['legacy' => true, 'type' => 'link', 'group' => $card, 'label' => 'Contatore «Movimenti PV»', 'parent' => 'consultant360.section.counters'],
            'consultant360.counter.settlements' => ['type' => 'link', 'group' => $card, 'label' => 'Contatore «Liquidazioni»', 'parent' => 'consultant360.section.counters'],
            'consultant360.counter.payouts' => ['type' => 'link', 'group' => $card, 'label' => 'Contatore «Pagamenti»', 'parent' => 'consultant360.section.counters'],
            'consultant360.counter.contracts' => ['type' => 'link', 'group' => $card, 'label' => 'Contatore «Contratti IVD»', 'parent' => 'consultant360.section.counters'],
            'consultant360.counter.kyc' => ['type' => 'link', 'group' => $card, 'label' => 'Contatore «Documenti KYC»', 'parent' => 'consultant360.section.counters'],
            'consultant360.counter.fees' => ['type' => 'link', 'group' => $card, 'label' => 'Contatore «Canoni»', 'parent' => 'consultant360.section.counters'],
            // ---- Scheda incaricato: azioni
            'consultant360.action.move_sponsor' => ['type' => 'button', 'group' => $card, 'label' => 'Pulsante «Sposta / cambia sponsor»', 'help' => 'Eccezione al Reg. 2.10.a: solo il super_admin e solo in casi estremi (motivo obbligatorio, finisce nel log). L\'admin non lo vede mai.', 'super' => true],
            'consultant360.action.reassign_clients' => ['type' => 'button', 'group' => $card, 'label' => 'Pulsante «Riassegna i clienti»'],
            'consultant360.action.force_rank' => ['type' => 'button', 'group' => $card, 'label' => 'Pulsante «Forza qualifica»'],
            'consultant360.action.hold_rank' => ['type' => 'button', 'group' => $card, 'label' => 'Pulsante «Mantieni qualifica»', 'help' => 'Piano Incaricati: mantiene la qualifica attuale di un incaricato fino a una data scelta, senza ricalcolo al ribasso.'],
            'consultant360.action.suspend' => ['type' => 'button', 'group' => $card, 'label' => 'Pulsanti «Sospendi» e «Riattiva»'],
            'consultant360.action.terminate' => ['type' => 'button', 'group' => $card, 'label' => 'Pulsante «Cessa»'],
            'consultant360.action.reveal_iban' => ['type' => 'button', 'group' => $card, 'label' => 'Mostra l\'IBAN per intero', 'help' => 'Spento: l\'IBAN resta sempre mascherato. Acceso: chi ha il permesso può mostrarlo, e ogni visualizzazione finisce nel log.', 'default' => false],
        ];

        // ---- Back-office: ogni voce del menu si può spegnere (si nasconde il link e si blocca anche l'indirizzo diretto).
        // slug => [etichetta, gruppo del menu]. Lo slug è quello della risorsa Filament, con «_» al posto di «-».
        $lists = [
            'consultants' => ['Incaricati', 'Rete'], 'customers' => ['Clienti', 'Rete'], 'ivd_contracts' => ['Contratti IVD', 'Rete'],
            'kyc_documents' => ['Documenti KYC', 'Rete'], 'annual_fees' => ['Canoni annuali', 'Rete'], 'account_transfers' => ['Cessioni del codice', 'Rete'],
            'inheritance_cases' => ['Successioni', 'Rete'], 'referral_invites' => ['Inviti referral', 'Rete'],
            'periods' => ['Periodi', 'Compensi'], 'pv_movements' => ['Movimenti PV', 'Compensi'], 'comp_plan_versions' => ['Piano compensi', 'Compensi'],
            'settlements' => ['Liquidazioni', 'Compensi'], 'payouts' => ['Pagamenti', 'Compensi'], 'commission_receipts' => ['Ricevute provvigionali', 'Compensi'],
            'bonus_struttura' => ['Bonus di Struttura', 'Compensi'], 'wallet_transactions' => ['Movimenti wallet', 'Compensi'], 'withdrawal_requests' => ['Prelievi su richiesta', 'Compensi'],
            'orders' => ['Ordini', 'Vendite'], 'invoices' => ['Fatture', 'Vendite'], 'credit_notes' => ['Note di credito', 'Vendite'],
            'products' => ['Prodotti', 'Vendite'], 'product_categories' => ['Categorie', 'Vendite'], 'discounts' => ['Sconti', 'Vendite'],
            'bundle_rules' => ['Bundle e omaggi', 'Vendite'], 'shipping_rates' => ['Tariffe di spedizione', 'Vendite'], 'subscriptions' => ['Abbonamenti', 'Vendite'],
            'tickets' => ['Ticket e reclami', 'Assistenza'], 'compliance_cases' => ['Casi di conformità', 'Assistenza'], 'sanctions' => ['Sanzioni', 'Assistenza'],
            'promo_materials' => ['Materiali da approvare', 'Assistenza'], 'corporate_events' => ['Eventi e corsi', 'Assistenza'], 'downloads' => ['Materiali scaricabili', 'Assistenza'],
            'download_logs' => ['Registro download', 'Assistenza'], 'news_posts' => ['Novità', 'Assistenza'],
            'import_reconciliations' => ['Riconciliazioni import', 'Sistema'],
        ];
        foreach ($lists as $slug => [$label, $menu]) {
            $defs["backoffice.resource.{$slug}"] = ['type' => 'view', 'group' => $bo, 'menu' => $menu, 'label' => "Elenco «{$label}»", 'menu_label' => $label, 'help' => $slug === 'consultants' ? 'Spegnerlo nasconde anche la scheda incaricato.' : null];
        }
        $defs['backoffice.resource.consultants']['bypass'] = true;
        $defs['backoffice.resource.pv_movements']['legacy'] = true;

        // ---- Back-office: pagine del menu che non sono elenchi
        $pages = [
            'albero_rete' => ['Albero della rete', 'Rete'], 'elenco_livelli' => ['Elenco per livelli', 'Rete'],
            'confronto_qualifiche' => ['Confronto qualifiche', 'Compensi'],
            'simulazione_incaricati' => ['Simulazione incaricati', 'Compensi'],
            'cruscotto_vendite' => ['Cruscotto vendite', 'Vendite'], 'negozio_pagamenti' => ['Pagamenti e spedizioni', 'Vendite'],
        ];
        foreach ($pages as $slug => [$label, $menu]) {
            $defs["backoffice.page.{$slug}"] = ['type' => 'view', 'group' => $bo, 'menu' => $menu, 'label' => "Pagina «{$label}»", 'menu_label' => $label];
        }

        $defs['backoffice.page.confronto_qualifiche']['legacy'] = true;

        // ---- Back-office: blocchi della pagina iniziale (Cruscotto)
        $widgets = [
            'kpi' => 'Indicatori del periodo', 'rank_distribution' => 'Incaricati per qualifica',
            'commissions_by_type' => 'Compensi per tipo di bonus', 'scheduler' => 'Operazioni automatiche',
        ];
        foreach ($widgets as $slug => $label) {
            $defs["backoffice.widget.{$slug}"] = ['type' => 'widget', 'group' => $bo, 'menu' => 'Cruscotto', 'label' => "Cruscotto: «{$label}»", 'menu_label' => $label];
        }
        $defs['backoffice.consultants_list.tabs'] = ['type' => 'section', 'group' => $bo, 'label' => 'Elenco incaricati: schede per stato', 'help' => 'Le schede «Attivi», «Sospesi», «Da completare»… con i conteggi sopra la tabella.', 'parent' => 'backoffice.resource.consultants'];
        $defs['backoffice.consultants_list.volumes'] = ['legacy' => true, 'type' => 'section', 'group' => $bo, 'label' => 'Elenco incaricati: colonne PR', 'help' => 'PR personali e di gruppo dell\'ultimo calcolo.', 'parent' => 'backoffice.resource.consultants'];
        $defs['backoffice.consultants_list.balance'] = ['type' => 'section', 'group' => $bo, 'label' => 'Elenco incaricati: «Da pagare» e filtro saldo', 'help' => 'Saldo delle liquidazioni aperte non ancora in un pagamento.', 'parent' => 'backoffice.resource.consultants'];

        // valori di default e pulizia
        foreach ($defs as $key => &$d) {
            $d['default'] ??= true;
            $d['super'] ??= false;
            $d['bypass'] ??= true;
            if (($d['help'] ?? null) === null) {
                unset($d['help']);
            }
        }
        unset($d);

        return $defs;
    }

    /** Gruppi nell'ordine in cui compaiono nella scelta delle voci di menu. */
    public const MENU_GROUPS = ['Cruscotto', 'Rete', 'Compensi', 'Vendite', 'Assistenza', 'Sistema'];

    /**
     * Le voci di menu (e i blocchi del cruscotto) che il super_admin può mostrare o nascondere a un utente.
     *
     * @return array<string, array<string, string>> gruppo => [chiave => etichetta]
     */
    public static function menuEntries(): array
    {
        $out = [];
        foreach (self::all() as $key => $d) {
            if (isset($d['menu'])) {
                $out[$d['menu']][$key] = $d['menu_label'] ?? $d['label'];
            }
        }
        uksort($out, static fn (string $a, string $b): int => array_search($a, self::MENU_GROUPS, true) <=> array_search($b, self::MENU_GROUPS, true));

        return $out;
    }

    /** @return list<string> chiavi di tutte le voci di menu e blocchi del cruscotto */
    public static function menuKeys(): array
    {
        return array_values(array_merge(...array_map('array_keys', array_values(self::menuEntries()))));
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** @return array<string, array<string, mixed>>|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<string> chiavi dei discendenti diretti e indiretti */
    public static function descendants(string $key): array
    {
        $out = [];
        foreach (self::all() as $k => $d) {
            if (($d['parent'] ?? null) === $key) {
                $out[] = $k;
                array_push($out, ...self::descendants($k));
            }
        }

        return $out;
    }

    /** Nome del tipo in italiano per il pannello. */
    public static function typeLabel(string $type): string
    {
        return ['module' => 'Area', 'view' => 'Vista', 'tab' => 'Tab', 'section' => 'Sezione', 'link' => 'Link', 'button' => 'Pulsante', 'widget' => 'Grafico'][$type] ?? $type;
    }
}
