<?php

declare(strict_types=1);

namespace App\Domain\Career;

use App\Models\Consultant;
use App\Support\Ui;
use Carbon\Carbon;

/**
 * Dati dell'albero grafico di «Il mio ramo»: gli stessi nodi, colori delle pietre e barre di avanzamento dell'albero del back-office,
 * ma solo per il ramo dell'incaricato (lui alla radice) e senza recapiti. Ogni persona porta qualifica, clienti, iscritti, venduto
 * del mese e cosa le manca per la prossima qualifica. Nessun PV/PR: nel piano Incaricati non esistono.
 */
final class NetworkTree
{
    /**
     * @param  array<string, mixed>  $o  risultato di NetworkOverview::for()
     * @param  array<int, array<string, mixed>>  $progress  risultato di CareerProgress::all()
     * @return array{root: int, nodes: list<array<string, mixed>>, ranks: list<array{code: string, name: string, color: string}>, meta: array{period: ?string}}
     */
    public function for(array $o, array $progress): array
    {
        /** @var Consultant $me */
        $me = $o['me'];
        $meId = (int) $me->id;
        $ranks = RankPalette::forPlan();
        $entry = $ranks[0]['code'] ?? null;

        // Ordine di visita: lui, poi i suoi diretti (già per nome) e così via in profondità.
        $order = [];
        $walk = function (int $id, int $level) use (&$walk, &$order, $o): void {
            $order[] = [$id, $level];
            foreach ($o['children'][$id] ?? [] as $child) {
                $walk((int) $child, $level + 1);
            }
        };
        $walk($meId, 0);

        $nodes = [];
        foreach ($order as [$id, $level]) {
            $c = $id === $meId ? $me : $o['nodes'][$id];
            $p = $progress[$id] ?? null;
            $st = $o['stats'][$id] ?? [];
            $cu = $o['custs'][$id] ?? [];
            $next = $p['next']['name'] ?? ($st['next_name'] ?? null);
            $nodes[] = [
                'id' => $id,
                'parent' => $id === $meId ? null : (int) $c->sponsor_id,
                'level' => $level,
                'code' => (string) $c->code,
                'name' => (string) $c->name,
                'status' => (string) $c->status,
                'since' => $c->since !== null ? Ui::date($c->since) : '',
                'rank' => $p['rank']['code'] ?? $entry,
                'active' => (bool) ($p['activity']['active'] ?? false),
                'next' => $next,
                'pct' => $p === null || ($p['next'] ?? null) === null ? 100 : (int) $p['pr']['percent'],
                'missing' => array_values($st['missing'] ?? []),
                'clients' => (int) ($st['clients'] ?? 0),
                'pearl_first' => (int) ($st['pearl_first'] ?? 0),
                'registered' => (int) ($cu['registered'] ?? 0),
                'valid' => (int) ($cu['valid'] ?? 0),
                'waiting' => (int) ($cu['waiting'] ?? 0),
                'last_order' => ! empty($cu['last_order']) ? Ui::date(Carbon::parse($cu['last_order'], 'UTC')) : '',
                'eur' => round((float) ($o['volume'][$id] ?? 0), 2),
                'team' => $id === $meId ? (int) $o['total']['persons'] : max(0, (int) (($o['sub'][$id]['persons'] ?? 1) - 1)),
            ];
        }

        return ['root' => $meId, 'nodes' => $nodes, 'ranks' => $ranks, 'meta' => ['period' => $o['period'] ?? null]];
    }

    /**
     * Recapiti di un incaricato del proprio ramo, secondo le impostazioni dell'admin. Mai dati fiscali, IBAN o documenti.
     * Chi chiama deve aver già verificato che la persona sia nel ramo.
     *
     * @return array{email:?string, phone:?string, place:?string}|null null se nessun dato è abilitato
     */
    public function contact(Consultant $c): ?array
    {
        $showContacts = (bool) config('lamess.network.downline_contacts');
        $showPlace = (bool) config('lamess.network.downline_place');
        if (! $showContacts && ! $showPlace) {
            return null;
        }
        $c->loadMissing('user.profile');
        $profile = $c->user?->profile;
        $out = ['email' => null, 'phone' => null, 'place' => null];
        if ($showContacts) {
            $out['email'] = $c->user?->email ?: null;
            $out['phone'] = $profile?->phone ?: null;
        }
        if ($showPlace) {
            $out['place'] = trim(($profile?->city ?? '').($profile?->province ? ' ('.$profile->province.')' : '')) ?: null;
        }

        return $out;
    }

    /**
     * Requisiti di una persona per la prossima qualifica, caricati al clic su un nodo. Testi neutri (non «ti mancano»):
     * chi guarda è l'upline, non la persona.
     *
     * @param  array<string, mixed>|null  $p  CareerProgress::forConsultant()
     * @return array<string, mixed>
     */
    public function detail(?array $p): array
    {
        if ($p === null) {
            return ['known' => false];
        }
        $k = $p['kosmos'] ?? null;
        $req = static function (array $r): array {
            $detail = match ($r['key'] ?? '') {
                'pearl' => 'Incaricati portati dalla persona con qualifica Pearl o superiore',
                'columns' => 'Una colonna è un diretto con tutta la sua discendenza: servono '.(int) ($r['target'] ?? 0).' clienti in totale in ciascuna (esclusi i personali)',
                default => (string) ($r['detail'] ?? ''),
            };

            return [
                'label' => (string) $r['label'], 'required' => (int) $r['required'], 'counted' => (int) $r['counted'],
                'ok' => (bool) ($r['ok'] || $r['disabled']), 'disabled' => (bool) $r['disabled'], 'detail' => $detail,
            ];
        };
        $m = $k['maintenance'] ?? null;

        return [
            'known' => true,
            'rank' => (string) $p['rank']['name'],
            'next_name' => $k['next_name'] ?? ($p['next']['name'] ?? null),
            'percent' => (int) ($k['percent'] ?? $p['pr']['percent'] ?? 100),
            'requirements' => array_map($req, $k['requirements'] ?? []),
            'current_requirements' => array_map($req, $k['current_requirements'] ?? []),
            'maintenance' => $m === null ? null : [
                'rank' => (string) $m['rank'], 'required' => (int) $m['required'], 'counted' => (int) $m['counted'], 'ok' => (bool) $m['ok'],
                'protected_until' => $m['protected_until'] ? date('d/m/Y', (int) strtotime((string) $m['protected_until'])) : null,
            ],
        ];
    }
}
