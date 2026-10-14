<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralInvites\Pages;

use App\Domain\Audit\AuditLog;
use App\Domain\Customers\ReferralInviteService;
use App\Filament\Pages\Base\SuperadminListRecords;
use App\Filament\Resources\ReferralInvites\ReferralInviteResource;
use App\Models\ReferralInvite;
use Filament\Actions\Action;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListReferralInvites extends SuperadminListRecords
{
    protected static string $resource = ReferralInviteResource::class;

    public function mount(): void
    {
        parent::mount();
        app(ReferralInviteService::class)->expireOverdue();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Esporta CSV')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->authorize('network.view')
                ->modalDescription('Scarica gli inviti che vedi nell\'elenco (filtri e ricerca compresi), pronti per Excel.')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    private function exportCsv(): StreamedResponse
    {
        $query = $this->getFilteredSortedTableQuery()->with('consultant');
        AuditLog::record('export.referral_invites', 'Esportazione inviti referral in CSV', ['ReferralInvite', 'elenco'], ['righe' => (clone $query)->count()]);
        $fmt = fn ($d, string $f = 'd/m/Y H:i') => $d?->setTimezone('Europe/Rome')->format($f);

        return response()->streamDownload(function () use ($query, $fmt): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Codice incaricato', 'Incaricato', 'Persona', 'Email', 'Telefono', 'Canale', 'Stato', 'Inviato il', 'Promemoria', 'Aperto il', 'Aperture', 'Registrato il', 'Primo ordine', 'Scade il', 'Nota'], ';');
            $query->reorder('referral_invites.id')->chunkById(200, function ($rows) use ($out, $fmt): void {
                /** @var ReferralInvite $r */
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r->consultant?->code, $r->consultant?->name, $r->recipientLabel(), $r->email, $r->phone,
                        ReferralInvite::CHANNELS[$r->channel] ?? $r->channel, $r->displayLabel(), $fmt($r->sent_at), $r->reminders_count,
                        $fmt($r->opened_at), $r->opens_count, $fmt($r->registered_at), $fmt($r->first_paid_at, 'd/m/Y'), $fmt($r->expires_at, 'd/m/Y'), $r->failure_reason,
                    ], ';');
                }
            }, 'referral_invites.id', 'id');
            fclose($out);
        }, 'inviti-referral-'.now('Europe/Rome')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
