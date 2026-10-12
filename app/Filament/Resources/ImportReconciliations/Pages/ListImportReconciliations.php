<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportReconciliations\Pages;

use App\Filament\Resources\ImportReconciliations\ImportReconciliationResource;
use App\Models\ImportReconciliation;
use Filament\Actions\Action;
use App\Filament\Pages\Base\SuperadminListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListImportReconciliations extends SuperadminListRecords
{
    protected static string $resource = ImportReconciliationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')->label('Scarica CSV')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->action(fn (): StreamedResponse => response()->streamDownload(function (): void {
                    $out = fopen('php://output', 'wb');
                    fwrite($out, "\xEF\xBB\xBF");
                    fputcsv($out, ['Gravità', 'Tipo', 'Riferimento', 'Nome', 'Messaggio', 'Stato', 'Nota', 'Dati Odoo'], ';');
                    foreach (ImportReconciliation::query()->orderBy('severity')->orderBy('code')->orderBy('subject_ref')->cursor() as $r) {
                        fputcsv($out, [$r->severity, ImportReconciliation::CODES[$r->code] ?? $r->code, $r->subject_ref, $r->subject_name, $r->message,
                            $r->status === 'accepted' ? 'approvata' : 'aperta', $r->resolution_note, json_encode($r->odoo, JSON_UNESCAPED_UNICODE)], ';');
                    }
                    fclose($out);
                }, 'riconciliazione-odoo.csv', ['Content-Type' => 'text/csv; charset=UTF-8'])),
        ];
    }
}
