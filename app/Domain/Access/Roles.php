<?php

declare(strict_types=1);

namespace App\Domain\Access;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ruoli e permessi del back-office. È dato di configurazione, non logica sparsa.
 *
 * Tre livelli di accesso:
 *  - `super_admin`: solo la titolare del portale. Vede e modifica tutto, compresi utenti, impostazioni di sistema e log
 *    di audit. Gli altri ruoli non lo vedono (vedi HidesSuperAdmins). Si crea SOLO da riga di comando
 *    (`lamess:superadmin`) o dall'installazione guidata iniziale, mai dal pannello.
 *  - `admin`: l'amministratore del cliente. Il ruolo non ha permessi propri: ogni capacità è un permesso concesso
 *    utente per utente dal super_admin (pagina Utenti), a partire da un profilo preimpostato. Non vede né tocca
 *    il super_admin e non ha mai accesso a utenti, impostazioni di sistema e log (SYSTEM_ONLY).
 *  - `consultant` / `customer`: portale pubblico, nessun accesso al back-office.
 *
 * I ruoli di dettaglio di MATRIX (admin_network, admin_finance, …) restano validi per compatibilità e come
 * profili preimpostati dell'admin. Per cambiarli si modifica qui e si lancia `php artisan lamess:ruoli`.
 */
final class Roles
{
    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN = 'admin';

    /**
     * Capacità riservate al super_admin: non si concedono a nessun altro, nemmeno come permesso singolo.
     * Utenti, impostazioni di sistema e log di audit si autorizzano controllando il RUOLO, non un permesso.
     */
    public const SYSTEM_ONLY = ['users.manage'];

    /** Permessi: area.azione */
    public const PERMISSIONS = [
        'kpi.view' => 'Vedere la dashboard KPI',
        'network.view' => 'Vedere incaricati, clienti e rete',
        'network.manage' => 'Creare/cessare incaricati, approvare contratti IVD',
        'network.restructure' => 'Spostare rami, cambiare sponsor, riassegnare clienti, forzare qualifiche',
        'kyc.manage' => 'Verificare i documenti KYC',
        'fees.manage' => 'Gestire i canoni annuali',
        'periods.view' => 'Vedere periodi, calcoli e movimenti PV',
        'periods.manage' => 'Aprire/calcolare/chiudere periodi, registrare movimenti PV',
        'plan.view' => 'Vedere le versioni del piano compensi',
        'plan.manage' => 'Creare versioni del piano compensi e chiederne la pubblicazione',
        'plan.compare' => 'Vedere il confronto tra qualifica precedente e qualifica del nuovo piano',
        'plan.approve' => 'Approvare o rifiutare la pubblicazione di una versione del piano (secondo amministratore)',
        'orders.view' => 'Vedere ordini, fatture e note di credito',
        'orders.manage' => 'Confermare pagamenti, spedire, annullare e rendere ordini; reinviare fatture',
        'catalog.manage' => 'Gestire prodotti, categorie, sconti, bundle, kit e tariffe di spedizione',
        'payouts.view' => 'Vedere liquidazioni, pagamenti e statini; esportare per la contabilità',
        'payouts.manage' => 'Preparare, segnare come pagati e annullare i pagamenti',
        'support.view' => 'Vedere ticket e reclami',
        'support.manage' => 'Rispondere e chiudere ticket, decidere i reclami sugli estratti conto',
        'compliance.view' => 'Vedere casi di conformità, sanzioni e materiali da approvare',
        'compliance.manage' => 'Aprire casi, applicare e revocare sanzioni (sospensione e blocco compensi), approvare materiali',
        'content.manage' => 'Gestire novità, materiali scaricabili ed eventi',
        'features.manage' => 'Accendere e spegnere funzionalità e viste (pagina «Funzionalità e viste»)',
        'users.manage' => 'Gestire utenti e ruoli del back-office',
    ];

    /** @var array<string, list<string>> */
    public const MATRIX = [
        'admin_network' => ['kpi.view', 'network.view', 'network.manage', 'kyc.manage', 'periods.view', 'orders.view', 'compliance.view', 'compliance.manage'],
        'admin_finance' => ['kpi.view', 'network.view', 'fees.manage', 'periods.view', 'periods.manage', 'orders.view', 'orders.manage', 'catalog.manage', 'payouts.view', 'payouts.manage', 'support.view', 'support.manage', 'content.manage'],
        'admin_compplan' => ['kpi.view', 'network.view', 'periods.view', 'plan.view', 'plan.manage', 'plan.compare', 'plan.approve'],
        'support' => ['network.view', 'kyc.manage', 'orders.view', 'support.view', 'support.manage', 'content.manage', 'compliance.view'],
        'board' => ['kpi.view', 'network.view', 'periods.view', 'plan.view', 'orders.view', 'payouts.view', 'support.view', 'compliance.view'],
    ];

    /** Ruoli del portale pubblico (nessun accesso al back-office). */
    public const PORTAL_ROLES = ['consultant', 'customer'];

    /** Etichette dei profili preimpostati dell'admin (la chiave è il profilo; 'completo' = tutto il concedibile). */
    public const PRESET_LABELS = [
        'completo' => 'Amministratore completo (tutto tranne il sistema)',
        'admin_network' => 'Rete e anagrafiche',
        'admin_finance' => 'Finanza e ordini',
        'admin_compplan' => 'Piano compensi',
        'support' => 'Assistenza',
        'board' => 'Sola lettura (direzione)',
    ];

    /** @return list<string> ruoli che possono entrare nel back-office */
    public static function backoffice(): array
    {
        return [self::SUPER_ADMIN, self::ADMIN, ...array_keys(self::MATRIX)];
    }

    /** @return list<string> permessi che il super_admin può concedere a un admin */
    public static function grantable(): array
    {
        return array_values(array_diff(array_keys(self::PERMISSIONS), self::SYSTEM_ONLY));
    }

    /** @return list<string> permessi di un profilo preimpostato ('completo' o un ruolo di MATRIX) */
    public static function preset(string $name): array
    {
        if ($name === 'completo') {
            return self::grantable();
        }

        return array_values(array_intersect(self::MATRIX[$name] ?? [], self::grantable()));
    }

    /** Crea/aggiorna ruoli e permessi in modo idempotente. */
    public static function sync(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (array_keys(self::PERMISSIONS) as $p) {
            Permission::findOrCreate($p, 'web');
        }
        Role::findOrCreate(self::SUPER_ADMIN, 'web');
        // l'admin non ha permessi di ruolo: li concede il super_admin utente per utente (non si toccano qui)
        Role::findOrCreate(self::ADMIN, 'web');
        foreach (self::MATRIX as $role => $perms) {
            Role::findOrCreate($role, 'web')->syncPermissions($perms);
        }
        foreach (self::PORTAL_ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
