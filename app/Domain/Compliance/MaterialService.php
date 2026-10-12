<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Domain\Access\Actor;
use App\Domain\Audit\AuditLog;
use App\Models\Consultant;
use App\Models\PromoMaterial;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/** Materiali promozionali: l’incaricato li propone, il Consorzio li approva (o no) e assegna un codice da citare. */
final class MaterialService
{
    public function submit(Consultant $c, string $title, ?string $description, ?string $claims, ?string $url = null): PromoMaterial
    {
        if ($c->status !== 'active') {
            throw new ComplianceException('Solo un incaricato attivo può proporre materiali.');
        }
        if (trim($title) === '' || (trim((string) $claims) === '' && trim((string) $url) === '' && trim((string) $description) === '')) {
            throw new ComplianceException('Indica un titolo e il testo (o il link) da approvare.');
        }
        if (PromoMaterial::where('submitted_by_consultant_id', $c->id)->where('status', 'pending')->count() >= 10) {
            throw new ComplianceException('Hai già 10 richieste in attesa: attendi l\'esito prima di proporne altre.');
        }
        $m = PromoMaterial::create(['submitted_by_consultant_id' => $c->id, 'title' => trim($title), 'description' => $description, 'claims' => $claims, 'url' => $url]);
        AuditLog::record('material.submitted', "Materiale «{$m->title}» proposto da {$c->code}", $m);

        return $m;
    }

    public function approve(PromoMaterial $m, string $note = '', bool $shared = false, ?DateTimeInterface $validUntil = null): PromoMaterial
    {
        if ($m->status !== 'pending') {
            throw new ComplianceException('La richiesta è già stata decisa.');
        }

        return DB::transaction(function () use ($m, $note, $shared, $validUntil): PromoMaterial {
            $m->update(['status' => 'approved', 'shared' => $shared, 'valid_until' => $validUntil, 'decided_by' => Actor::label(), 'decided_at' => now(), 'decision_note' => $note ?: null, 'code' => $this->nextCode($m)]);
            AuditLog::record('material.approved', "Materiale {$m->code} approvato", $m, ['condiviso' => $shared], $note ?: null);

            return $m;
        });
    }

    public function reject(PromoMaterial $m, string $note): void
    {
        if ($m->status !== 'pending') {
            throw new ComplianceException('La richiesta è già stata decisa.');
        }
        if (mb_strlen(trim($note)) < 5) {
            throw new ComplianceException('Spiega all’incaricato perché non è approvato.');
        }
        $m->update(['status' => 'rejected', 'decided_by' => Actor::label(), 'decided_at' => now(), 'decision_note' => trim($note)]);
        AuditLog::record('material.rejected', "Materiale «{$m->title}» non approvato", $m, [], $note);
    }

    /** L’incaricato ritira una richiesta ancora in attesa, o un suo materiale approvato. */
    public function withdraw(PromoMaterial $m, Consultant $c): void
    {
        if ($m->submitted_by_consultant_id !== $c->id || ! in_array($m->status, ['pending', 'approved'], true)) {
            throw new ComplianceException('Non puoi ritirare questo materiale.');
        }
        $m->update(['status' => 'withdrawn', 'shared' => false]);
        AuditLog::record('material.withdrawn', 'Materiale '.($m->code ?: "«{$m->title}»").' ritirato da '.$c->code, $m);
    }

    /** Materiale dell'azienda: nasce già approvato e condiviso. */
    public function publishOfficial(string $title, ?string $description, ?string $claims, ?string $url = null, ?DateTimeInterface $validUntil = null): PromoMaterial
    {
        $m = PromoMaterial::create(['title' => trim($title), 'description' => $description, 'claims' => $claims, 'url' => $url]);

        return $this->approve($m, 'Materiale ufficiale del Consorzio', true, $validUntil);
    }

    private function nextCode(PromoMaterial $m): string
    {
        $year = now()->setTimezone(config('app.timezone'))->format('Y');
        $n = PromoMaterial::whereNotNull('code')->where('code', 'like', "MA-{$year}-%")->count() + 1;

        return sprintf('MA-%s-%04d', $year, $n);
    }
}
