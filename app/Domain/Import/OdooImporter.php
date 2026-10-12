<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Network\NetworkService;
use App\Domain\Periods\PeriodService;
use App\Models\CompPlanVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Importa nel portale i dati puliti prodotti da database/import/odoo_etl.py (incaricati, clienti, movimenti PV).
 * Idempotente: una seconda esecuzione sugli stessi file non duplica nulla. Non invia email.
 * Non crea ordini né fatture storiche (gli export non contengono le righe prodotto) e non assegna qualifiche:
 * le qualifiche si ricalcolano dal motore sui movimenti importati.
 */
final class OdooImporter
{
    private const TZ = 'Europe/Rome';

    /** @var array<string,int> */
    private array $roleIds = [];

    /** @var array<string,true> */
    private array $emails = [];

    private string $passwordHash = '';

    /** @var array<string,int> */
    private array $report = [];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(private readonly NetworkService $network, private readonly PeriodService $periods) {}

    /** @return array{counts: array<string,int>, notes: list<string>} */
    public function import(string $dir, bool $dryRun = false): array
    {
        $consultants = $this->rows($dir, 'consulenti.csv');
        // Chi non ha una qualifica è solo cliente: consulenti_integrazione.csv (firma senza qualifica) NON si importa più come incaricati.
        // Per rimettere tra i clienti chi era già stato importato: `php artisan lamess:declassa-senza-rank`.
        $customers = $this->rows($dir, 'clienti.csv');
        $movements = $this->rows($dir, 'movimenti.csv');
        $counters = is_file($dir.DIRECTORY_SEPARATOR.'contatori.csv') ? $this->rows($dir, 'contatori.csv') : [];

        $this->roleIds = DB::table('roles')->where('guard_name', 'web')->pluck('id', 'name')->all();
        foreach (['consultant', 'customer'] as $role) {
            if (! isset($this->roleIds[$role])) {
                throw new InvalidArgumentException("Ruolo «{$role}» mancante: lancia prima `php artisan lamess:ruoli`.");
            }
        }
        $this->emails = array_fill_keys(DB::table('users')->pluck('email')->map(fn ($e) => strtolower((string) $e))->all(), true);
        $this->passwordHash = Hash::make(Str::random(48)); // password inutilizzabile: l'accesso si attiva con il recupero password
        $this->report = array_fill_keys([
            'consulenti_creati', 'consulenti_gia_presenti', 'consulenti_senza_sponsor', 'email_segnaposto', 'email_duplicate',
            'clienti_diventati_incaricati', 'clienti_creati', 'clienti_gia_presenti', 'clienti_senza_consulente', 'periodi_aperti', 'movimenti_creati', 'movimenti_gia_presenti', 'ingressi_anticipati',
        ], 0);
        $this->notes = [];

        DB::beginTransaction();
        try {
            $refToId = $this->importConsultants($consultants);
            $this->importCustomers($customers, $refToId);
            $this->importMovements($movements, $consultants, $refToId);
            $this->alignNetworkSince();
            foreach ($counters as $c) {
                $current = (int) (DB::table('document_counters')->where('key', $c['chiave'])->value('value') ?? 0);
                DB::table('document_counters')->updateOrInsert(['key' => $c['chiave']], ['value' => max($current, (int) $c['valore'])]);
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return ['counts' => $this->report, 'notes' => $this->notes];
    }

    /**
     * @param  list<array<string,string>>  $rows
     * @return array<string,int> referral_code → consultants.id
     */
    private function importConsultants(array $rows): array
    {
        $prefix = (string) config('lamess.consultant_code_prefix');
        $width = (int) config('lamess.consultant_code_width');
        $next = 1 + (int) DB::table('consultants')->where('code', 'like', $prefix.'%')->get(['code'])
            ->map(fn ($r) => (int) substr($r->code, strlen($prefix)))->max();

        $refToId = DB::table('consultants')->whereNotNull('referral_code')->pluck('id', 'referral_code')->all();
        $created = []; // id => sponsor_ref
        $now = now();

        foreach ($rows as $r) {
            $ref = $r['ref'];
            $slug = $ref !== '' ? Str::slug($ref) : 'consulente-'.substr(md5(strtolower($r['nome']).'|'.$r['dal']), 0, 10);
            if ($ref !== '' && isset($refToId[$ref])) {
                $this->report['consulenti_gia_presenti']++;

                continue;
            }
            if ($ref === '' && DB::table('consultants')->whereNull('referral_code')->where('name', $r['nome'])->where('since', $this->utc($r['dal']))->exists()) {
                $this->report['consulenti_gia_presenti']++; // senza codice referral: si riconosce per nome e data di inizio

                continue;
            }
            $userId = $this->reuseCustomerUser($r['email']) ?? $this->makeUser($r, $slug, 'consultant');
            $since = $this->utc($r['dal']);
            $id = DB::table('consultants')->insertGetId([
                'code' => $prefix.str_pad((string) $next++, $width, '0', STR_PAD_LEFT),
                'referral_code' => $ref !== '' ? $ref : null,
                'name' => $r['nome'], 'status' => $r['stato'], 'since' => $since,
                'onboarding_complete' => $r['incarico'] !== '', 'onboarding_completed_at' => $r['incarico'] !== '' ? $this->utc($r['incarico']) : null,
                'person_key' => $r['codice_fiscale'] !== '' ? $r['codice_fiscale'] : null,
                'user_id' => $userId, 'kyc_status' => $r['kyc'] !== '' ? $r['kyc'] : 'none',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('consultant_status_changes')->insert([
                'consultant_id' => $id, 'from_status' => null, 'to_status' => $r['stato'], 'reason' => 'importazione Odoo',
                'changed_by' => 'import', 'changed_at' => $since, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($ref !== '') {
                $refToId[$ref] = $id;
            }
            $created[$id] = $r['sponsor_ref'];
            $this->report['consulenti_creati']++;
            if ($r['nota'] !== '') {
                $this->notes[] = "{$r['nome']} ({$ref}): {$r['nota']}";
            }
        }

        foreach ($created as $id => $sponsorRef) {
            if ($sponsorRef === '' || ! isset($refToId[$sponsorRef])) {
                $this->report['consulenti_senza_sponsor']++;

                continue;
            }
            DB::table('consultants')->where('id', $id)->update(['sponsor_id' => $refToId[$sponsorRef], 'original_sponsor_id' => $refToId[$sponsorRef]]);
        }
        $this->assertNoCycles();
        $this->network->rebuildClosure();

        return $refToId;
    }

    private function assertNoCycles(): void
    {
        $sponsor = DB::table('consultants')->pluck('sponsor_id', 'id')->all();
        foreach ($sponsor as $id => $_) {
            $seen = [$id => true];
            $p = $sponsor[$id];
            while ($p !== null) {
                if (isset($seen[$p])) {
                    throw new InvalidArgumentException("Ciclo negli sponsor che coinvolge l'incaricato #{$id}.");
                }
                $seen[$p] = true;
                $p = $sponsor[$p] ?? null;
            }
        }
    }

    /**
     * @param  list<array<string,string>>  $rows
     * @param  array<string,int>  $refToId
     */
    private function importCustomers(array $rows, array $refToId): void
    {
        $now = now();
        foreach ($rows as $r) {
            $slug = $r['ref'] !== '' ? Str::slug($r['ref']) : 'cliente-'.substr(md5(strtolower($r['nome']).'|'.$r['registrato']), 0, 10);
            $placeholder = $slug.'@import.lamess.invalid';
            $email = strtolower(trim($r['email']));
            if (($email !== '' && isset($this->emails[$email])) || ($email === '' && isset($this->emails[$placeholder]))) {
                $this->report['clienti_gia_presenti']++;

                continue;
            }
            $userId = $this->makeUser($r, $slug, 'customer');
            $consultantId = $refToId[$r['consulente_ref']] ?? null;
            if ($consultantId === null) {
                $this->report['clienti_senza_consulente']++;
            }
            DB::table('customers')->insert([
                'user_id' => $userId, 'assigned_consultant_id' => $consultantId, 'source' => $consultantId ? 'referral' : 'manual',
                'registered_at' => $r['registrato'] !== '' ? $this->utc($r['registrato']) : $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->report['clienti_creati']++;
        }
    }

    /**
     * @param  list<array<string,string>>  $movements
     * @param  list<array<string,string>>  $consultants
     * @param  array<string,int>  $refToId
     */
    private function importMovements(array $movements, array $consultants, array $refToId): void
    {
        // incaricati senza codice referral: si riconoscono solo per nome (se il nome è unico)
        $nameToId = [];
        foreach (DB::table('consultants')->whereNull('referral_code')->get(['id', 'name']) as $c) {
            $nameToId[strtolower($c->name)] = isset($nameToId[strtolower($c->name)]) ? 0 : $c->id;
        }

        // periodi
        $codes = collect($movements)->pluck('periodo')->unique()->sort()->values();
        if ($codes->isNotEmpty()) {
            foreach ($codes as $code) {
                $plan = (CompPlanVersion::inForceForPeriod((string) $code) ?? CompPlanVersion::inForce())
                    ?? throw new InvalidArgumentException('Nessun piano compensi in vigore: lancia `php artisan db:seed --class=PrelaunchPlanSeeder`.');
                if (! DB::table('periods')->where('code', $code)->exists()) {
                    $this->periods->open((string) $code, $plan);
                    $this->report['periodi_aperti']++;
                }
            }
        }
        $periodIds = DB::table('periods')->pluck('id', 'code')->all();
        $existing = array_fill_keys(DB::table('pv_movements')->whereNotNull('external_ref')->pluck('external_ref')->all(), true);

        $now = now();
        $batch = [];
        $manualSeen = [];
        foreach ($movements as $m) {
            $ref = $m['rif_esterno'];
            if ($ref === '') {
                $key = implode('|', [$m['periodo'], $m['data'], $m['beneficiario_ref'] ?: $m['beneficiario_nome'], $m['tipo'], $m['pv'], $m['fattura'], $m['acquirente']]);
                $manualSeen[$key] = ($manualSeen[$key] ?? 0) + 1;
                $ref = 'odoo-manuale:'.md5($key).'#'.$manualSeen[$key];
            }
            $eventAt = $this->eventAt($m);
            if (isset($existing[$ref])) {
                DB::table('pv_movements')->where('external_ref', $ref)->where('event_at', '!=', $eventAt)->update(['event_at' => $eventAt]); // rettifiche datate fuori dal loro mese
                $this->report['movimenti_gia_presenti']++;

                continue;
            }
            $consultantId = $refToId[$m['beneficiario_ref']] ?? $nameToId[strtolower($m['beneficiario_nome'])] ?? null;
            if ($consultantId === null || $consultantId === 0) {
                throw new InvalidArgumentException("Beneficiario non trovato per il movimento «{$m['rif_esterno']}» ({$m['beneficiario_nome']}).");
            }
            $note = trim(($m['manuale'] === '1' ? 'Rettifica manuale · ' : '').(substr($m['data'], 0, 7) !== $m['periodo'] ? 'data originale '.substr($m['data'], 0, 10).' · ' : '').'Odoo '.($m['ordine'] ?: $m['fattura']).' · '.$m['prodotto'].' · acquirente '.$m['acquirente']);
            $batch[] = [
                'period_id' => $periodIds[$m['periodo']], 'consultant_id' => $consultantId, 'kind' => $m['tipo'], 'type' => 'migration',
                'status' => 'confirmed', 'event_at' => $eventAt,
                'pv' => $m['pv'], 'cv' => $m['cv'],
                // Le rettifiche manuali sono punti di carriera, non vendite: importo 0, così non vengono convertiti in euro (1,5 €/PV).
                'amount' => $m['manuale'] === '1' ? '0.00' : null,
                'excluded_from_bonus' => $m['escluso_bonus'] === '1', 'external_ref' => $ref,
                'note' => Str::limit($note, 250, ''), 'created_at' => $now, 'updated_at' => $now,
            ];
            $existing[$ref] = true;
            $this->report['movimenti_creati']++;
            if (count($batch) >= 100) {
                DB::table('pv_movements')->insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('pv_movements')->insert($batch);
        }
    }

    /**
     * Ingresso effettivo in rete per il motore (consultants.network_since): chi operava in Odoo prima della firma della lettera
     * entra dal primo movimento, e uno sponsor entra mai dopo i suoi diretti. La data della lettera (since) non cambia.
     */
    private function alignNetworkSince(): void
    {
        $rows = DB::table('consultants')->get(['id', 'sponsor_id', 'since', 'network_since']);
        $first = DB::table('pv_movements')->groupBy('consultant_id')->selectRaw('consultant_id, min(event_at) as first_at')->pluck('first_at', 'consultant_id');
        $join = [];
        $parent = [];
        foreach ($rows as $c) {
            $join[$c->id] = min((string) $c->since, (string) ($first[$c->id] ?? $c->since));
            $parent[$c->id] = $c->sponsor_id;
        }
        // dal basso: nessuno sponsor entra dopo un suo diretto (si risale la catena finché serve)
        foreach ($join as $id => $when) {
            for ($p = $parent[$id] ?? null, $guard = 0; $p !== null && isset($join[$p]) && $join[$p] > $when && $guard < 1000; $p = $parent[$p] ?? null, $guard++) {
                $join[$p] = $when;
            }
        }
        $changed = 0;
        foreach ($rows as $c) {
            $target = $join[$c->id] < (string) $c->since ? $join[$c->id] : null;
            if ($target !== ($c->network_since === null ? null : (string) $c->network_since)) {
                DB::table('consultants')->where('id', $c->id)->update(['network_since' => $target]);
                $changed++;
            }
        }
        $this->report['ingressi_anticipati'] = $changed;
    }

    /** @param array<string,string> $r */
    /**
     * Chi era già nel portale come cliente (stessa email) e ora risulta incaricato riusa il suo utente: si aggiunge solo il ruolo.
     * Se l'utente è già legato a un incaricato non si tocca (resta il caso dell'email duplicata).
     */
    private function reuseCustomerUser(string $email): ?int
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }
        $user = DB::table('users')->whereRaw('lower(email) = ?', [$email])->first(['id']);
        if ($user === null || DB::table('consultants')->where('user_id', $user->id)->exists()) {
            return null;
        }
        $has = DB::table('model_has_roles')->where(['role_id' => $this->roleIds['consultant'], 'model_type' => 'App\\Models\\User', 'model_id' => $user->id])->exists();
        if (! $has) {
            DB::table('model_has_roles')->insert(['role_id' => $this->roleIds['consultant'], 'model_type' => 'App\\Models\\User', 'model_id' => $user->id]);
        }
        $this->report['clienti_diventati_incaricati']++;

        return (int) $user->id;
    }

    private function makeUser(array $r, string $slug, string $role): int
    {
        $email = strtolower(trim($r['email']));
        if ($email === '' || isset($this->emails[$email])) {
            $this->report[$email === '' ? 'email_segnaposto' : 'email_duplicate']++;
            $email = $slug.'@import.lamess.invalid'; // dominio non instradabile: nessuna email può uscire
        }
        $this->emails[$email] = true;
        $now = now();
        $userId = DB::table('users')->insertGetId([
            'name' => $r['nome'], 'email' => $email, 'password' => $this->passwordHash, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('model_has_roles')->insert(['role_id' => $this->roleIds[$role], 'model_type' => 'App\\Models\\User', 'model_id' => $userId]);

        $company = $r['tipo'] === 'company';
        [$first, $last] = $company ? [null, null] : $this->splitName($r['nome']);
        DB::table('profiles')->insert([
            'user_id' => $userId, 'type' => $company ? 'company' : 'private', 'first_name' => $first, 'last_name' => $last,
            'company_name' => $company ? $r['nome'] : null, 'tax_code' => $r['codice_fiscale'] ?: null, 'phone' => $r['telefono'] ?: null,
            'address' => $r['indirizzo'] ?: null, 'city' => $r['citta'] ?: null, 'zip' => $r['cap'] ?: null,
            'province' => $r['provincia'] ?: null, 'country_code' => $r['paese'] ?: 'IT', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $userId;
    }

    /** @return array{0:?string,1:?string} */
    private function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [];
        if (count($parts) <= 1) {
            return [$full !== '' ? $full : null, null];
        }

        return [array_shift($parts), implode(' ', $parts)];
    }

    /** Un movimento sta nel mese del suo periodo: una rettifica datata prima (es. gennaio, registrata a maggio) conta nel periodo indicato. */
    private function eventAt(array $m): string
    {
        $day = substr($m['data'], 0, 10);
        if (substr($day, 0, 7) !== $m['periodo']) {
            $day = $m['periodo'].'-01';
        }

        return CarbonImmutable::parse($day.' 12:00:00', self::TZ)->utc()->format('Y-m-d H:i:s');
    }

    private function utc(string $local): string
    {
        return CarbonImmutable::parse($local, self::TZ)->utc()->format('Y-m-d H:i:s');
    }

    /** @return list<array<string,string>> */
    private function rows(string $dir, string $file): array
    {
        $path = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$file;
        if (! is_file($path)) {
            throw new InvalidArgumentException("File mancante: {$path}");
        }
        $h = fopen($path, 'rb');
        $head = fgetcsv($h, 0, ';', '"', '');
        if ($head === false) {
            throw new InvalidArgumentException("File vuoto: {$path}");
        }
        $head[0] = ltrim((string) $head[0], "\xEF\xBB\xBF");
        $rows = [];
        while (($line = fgetcsv($h, 0, ';', '"', '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_combine($head, array_pad(array_slice($line, 0, count($head)), count($head), ''));
        }
        fclose($h);

        return $rows;
    }
}
