<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Catalogo dei permessi del back-office: aree, spiegazione di cosa sblocca ciascun permesso, permessi sensibili e
 * dipendenze («gestire» richiede «vedere»). L'elenco dei permessi resta `Roles::PERMISSIONS` (una sola fonte per le sigle e
 * le etichette): qui c'è solo ciò che serve a mostrarli e a validarli. Un test verifica che i due elenchi coincidano.
 */
final class PermissionCatalog
{
    /** Aree, nell'ordine in cui compaiono nei moduli e nella matrice. */
    public const AREAS = [
        'cruscotto' => ['label' => 'Cruscotto e statistiche', 'help' => 'Indicatori della pagina iniziale e statistiche del sito.'],
        'rete' => ['label' => 'Rete e anagrafiche', 'help' => 'Incaricati, clienti, documenti KYC e canoni.'],
        'compensi' => ['label' => 'Compensi e piano', 'help' => 'Periodi, calcoli, piano compensi, liquidazioni e pagamenti.'],
        'vendite' => ['label' => 'Vendite e catalogo', 'help' => 'Ordini, fatture, prodotti, sconti e spedizioni.'],
        'assistenza' => ['label' => 'Assistenza, conformità e contenuti', 'help' => 'Ticket, casi di conformità, novità, materiali ed eventi.'],
        'sistema' => ['label' => 'Funzioni di sistema', 'help' => 'Visibilità di funzioni e viste.'],
    ];

    /**
     * Per ogni permesso: area, cosa sblocca, se è sensibile, quali permessi richiede.
     *
     * @var array<string, array{area: string, detail: string, sensitive?: bool, requires?: list<string>}>
     */
    public const PERMISSIONS = [
        'kpi.view' => ['area' => 'cruscotto', 'detail' => 'Cruscotto, grafici, «Statistiche sito» e «Cruscotto vendite».'],

        'network.view' => ['area' => 'rete', 'detail' => 'Elenchi Incaricati e Clienti, scheda incaricato, albero e livelli della rete, inviti, contratti, cessioni e successioni.'],
        'network.manage' => ['area' => 'rete', 'detail' => 'Crea e modifica incaricati e clienti, sospende e banna, cessa, reimposta le password, invia documenti, gestisce lettera di incarico e regolamento.', 'sensitive' => true, 'requires' => ['network.view']],
        'network.restructure' => ['area' => 'rete', 'detail' => 'Sposta rami, cambia sponsor, riassegna clienti, forza o mantiene qualifiche, segna i founder.', 'sensitive' => true, 'requires' => ['network.view']],
        'kyc.view' => ['area' => 'rete', 'detail' => 'Elenco dei documenti KYC con il loro stato.', 'requires' => ['network.view']],
        'kyc.manage' => ['area' => 'rete', 'detail' => 'Approva o rifiuta i documenti di identità e chiede i documenti mancanti.', 'sensitive' => true, 'requires' => ['kyc.view']],
        'fees.view' => ['area' => 'rete', 'detail' => 'Elenco dei canoni annuali e del loro stato.', 'requires' => ['network.view']],
        'fees.manage' => ['area' => 'rete', 'detail' => 'Registra pagamenti, rinnovi ed esenzioni del canone annuale.', 'requires' => ['fees.view']],

        'periods.view' => ['area' => 'compensi', 'detail' => 'Periodi, calcoli, movimenti, punti assegnati e stato delle operazioni automatiche.'],
        'periods.manage' => ['area' => 'compensi', 'detail' => 'Apre, calcola e chiude i periodi; registra movimenti e assegna punti.', 'sensitive' => true, 'requires' => ['periods.view']],
        'plan.view' => ['area' => 'compensi', 'detail' => 'Versioni del piano compensi in sola lettura.'],
        'plan.manage' => ['area' => 'compensi', 'detail' => 'Crea le versioni del piano e chiede di pubblicarle.', 'sensitive' => true, 'requires' => ['plan.view']],
        'plan.compare' => ['area' => 'compensi', 'detail' => 'Confronto fra qualifica precedente e nuova, e simulazione degli incaricati.', 'requires' => ['plan.view']],
        'plan.approve' => ['area' => 'compensi', 'detail' => 'Approva o rifiuta la pubblicazione di una versione del piano (secondo amministratore).', 'sensitive' => true, 'requires' => ['plan.view']],
        'payouts.view' => ['area' => 'compensi', 'detail' => 'Liquidazioni, pagamenti, statini, ricevute, Bonus di Struttura, Compensi del mese e prelievi.'],
        'payouts.manage' => ['area' => 'compensi', 'detail' => 'Prepara i pagamenti, li segna come pagati o li annulla.', 'sensitive' => true, 'requires' => ['payouts.view']],

        'orders.view' => ['area' => 'vendite', 'detail' => 'Ordini, fatture, note di credito, abbonamenti e riconciliazioni.'],
        'orders.manage' => ['area' => 'vendite', 'detail' => 'Conferma gli incassi, spedisce, annulla, gestisce i resi e rimborsa; reinvia le fatture.', 'sensitive' => true, 'requires' => ['orders.view']],
        'catalog.view' => ['area' => 'vendite', 'detail' => 'Prodotti, categorie, sconti, bundle e tariffe di spedizione in sola lettura.'],
        'catalog.manage' => ['area' => 'vendite', 'detail' => 'Crea e modifica prodotti, prezzi, sconti, bundle, kit e tariffe; pagina «Pagamenti e spedizioni».', 'sensitive' => true, 'requires' => ['catalog.view']],

        'support.view' => ['area' => 'assistenza', 'detail' => 'Ticket e reclami in sola lettura.'],
        'support.manage' => ['area' => 'assistenza', 'detail' => 'Risponde e chiude i ticket, decide i reclami sugli estratti conto.', 'requires' => ['support.view']],
        'compliance.view' => ['area' => 'assistenza', 'detail' => 'Casi di conformità, sanzioni e materiali promozionali da approvare.'],
        'compliance.manage' => ['area' => 'assistenza', 'detail' => 'Apre casi, applica e revoca sanzioni (sospensione, blocco dei compensi), approva i materiali.', 'sensitive' => true, 'requires' => ['compliance.view']],
        'content.view' => ['area' => 'assistenza', 'detail' => 'Novità, materiali scaricabili, eventi e registro dei download in sola lettura.'],
        'content.manage' => ['area' => 'assistenza', 'detail' => 'Pubblica e modifica novità, materiali ed eventi.', 'requires' => ['content.view']],

        'features.manage' => ['area' => 'sistema', 'detail' => 'Accende e spegne le funzionalità e le viste della pagina «Funzionalità e viste».', 'sensitive' => true],

        // Riservato al super_admin: compare nella matrice solo come riga bloccata.
        'users.manage' => ['area' => 'sistema', 'detail' => 'Utenti, ruoli, impostazioni di sistema, log di audit, backup. Mai concedibile.', 'sensitive' => true],
    ];

    /** @return array<string, array{label: string, help: string}> */
    public static function areas(): array
    {
        return self::AREAS;
    }

    /** @return list<string> permessi concedibili di un'area, nell'ordine del catalogo */
    public static function grantableIn(string $area): array
    {
        $grantable = Roles::grantable();

        return array_values(array_filter(
            array_keys(self::PERMISSIONS),
            static fn (string $p): bool => self::PERMISSIONS[$p]['area'] === $area && in_array($p, $grantable, true),
        ));
    }

    public static function areaOf(string $permission): ?string
    {
        return self::PERMISSIONS[$permission]['area'] ?? null;
    }

    public static function isSensitive(string $permission): bool
    {
        return (bool) (self::PERMISSIONS[$permission]['sensitive'] ?? false);
    }

    /** @return list<string> permessi richiesti, in modo diretto */
    public static function requiresOf(string $permission): array
    {
        return self::PERMISSIONS[$permission]['requires'] ?? [];
    }

    /**
     * Aggiunge i permessi richiesti (anche a catena) e restituisce l'elenco in ordine di catalogo, senza doppioni.
     * I permessi sconosciuti non si toccano: li rifiuta chi valida.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public static function close(array $permissions): array
    {
        $set = array_fill_keys($permissions, true);
        $queue = $permissions;
        while ($queue !== []) {
            $current = array_pop($queue);
            foreach (self::requiresOf($current) as $needed) {
                if (! isset($set[$needed])) {
                    $set[$needed] = true;
                    $queue[] = $needed;
                }
            }
        }

        return self::ordered(array_keys($set));
    }

    /**
     * Permessi che non si possono togliere finché ne resta acceso uno che li richiede.
     *
     * @param  list<string>  $permissions
     * @return array<string, list<string>> permesso richiesto => permessi accesi che lo richiedono
     */
    public static function neededBy(array $permissions): array
    {
        $out = [];
        foreach ($permissions as $p) {
            foreach (self::requiresOf($p) as $needed) {
                $out[$needed][] = $p;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string> in ordine di catalogo; i permessi fuori catalogo vanno in coda
     */
    public static function ordered(array $permissions): array
    {
        $order = array_flip(array_keys(self::PERMISSIONS));

        usort($permissions, static fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX) ?: strcmp($a, $b));

        return array_values(array_unique($permissions));
    }

    /**
     * Dall'elenco piatto allo stato del modulo (area => permessi).
     *
     * @param  list<string>  $permissions
     * @return array<string, list<string>>
     */
    public static function splitByArea(array $permissions): array
    {
        $out = array_fill_keys(array_keys(self::AREAS), []);
        foreach (self::ordered($permissions) as $p) {
            $area = self::areaOf($p);
            if ($area !== null) {
                $out[$area][] = $p;
            }
        }

        return $out;
    }

    /**
     * Dallo stato del modulo (area => permessi) all'elenco piatto.
     *
     * @param  array<string, mixed>|null  $state
     * @return list<string>
     */
    public static function flatten(?array $state): array
    {
        $flat = [];
        foreach ($state ?? [] as $values) {
            foreach ((array) $values as $p) {
                $flat[] = (string) $p;
            }
        }

        return self::ordered($flat);
    }

    /**
     * Descrizione per le caselle del modulo: cosa sblocca, cosa richiede, se è sensibile.
     */
    public static function describe(string $permission): string
    {
        $def = self::PERMISSIONS[$permission] ?? null;
        if ($def === null) {
            return '';
        }
        $text = $def['detail'];
        if (! empty($def['sensitive'])) {
            $text = 'Sensibile. '.$text;
        }
        if (! empty($def['requires'])) {
            $text .= ' Include: '.implode(', ', array_map(static fn (string $r): string => $r, $def['requires'])).'.';
        }

        return $text;
    }
}
