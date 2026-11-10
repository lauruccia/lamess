<?php

declare(strict_types=1);

namespace App\Filament\Resources\TranslationStrings\Pages;

use App\Domain\Audit\AuditLog;
use App\Domain\I18n\Locales;
use App\Domain\I18n\TranslationExchange;
use App\Domain\I18n\TranslationSync;
use App\Filament\Resources\TranslationStrings\TranslationStringResource;
use App\Models\TranslationString;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListTranslationStrings extends ListRecords
{
    protected static string $resource = TranslationStringResource::class;

    protected ?string $subheading = 'Una riga per testo e per lingua. Scegli la lingua dal filtro, correggi i testi e approvali; le lingue in bozza si attivano da «Lingue».';

    public function mount(): void
    {
        parent::mount();
        // prima visita: allinea da solo la tabella con il codice, così non serve nessun comando sul server
        if (! TranslationString::query()->exists()) {
            app(TranslationSync::class)->run();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scan')->label('Cerca nuove stringhe')->icon('heroicon-o-magnifying-glass')->color('gray')
                ->requiresConfirmation()->modalHeading('Cercare nuovi testi nel sito?')
                ->modalDescription('Legge le pagine del sito, aggiunge i testi nuovi a tutte le lingue e porta qui le traduzioni consegnate con il codice. Non cancella e non sovrascrive nulla.')
                ->action(function (): void {
                    $r = app(TranslationSync::class)->run();
                    AuditLog::record('traduzioni.scansione', 'Ricerca nuove stringhe', null, $r);
                    Notification::make()->title('Ricerca completata')
                        ->body("{$r['found']} testi nel sito · {$r['added']} righe nuove · {$r['imported']} traduzioni importate · {$r['orphaned']} orfane")->success()->send();
                }),
            Action::make('autoTranslate')->label('Traduci le mancanti')->icon('heroicon-o-sparkles')->color('gray')
                ->schema([
                    Select::make('locale')->label('Lingua')->options(TranslationStringResource::targetOptions())->required(),
                    TextInput::make('limit')->label('Quante righe al massimo')->numeric()->integer()->minValue(1)->maxValue(1000)->default(200)->required()
                        ->helperText('Per non superare i tempi del server si traduce a gruppi: ripeti l\'operazione finché non restano mancanti.'),
                ])
                ->modalDescription('Traduce con il servizio automatico i testi ancora vuoti. Restano «da rivedere» finché il traduttore non li approva.')
                ->action(fn (array $data) => TranslationStringResource::runTranslate((string) $data['locale'], null, (int) $data['limit'])),
            Action::make('export')->label('Esporta per il traduttore')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->schema([Select::make('locale')->label('Lingua')->options(TranslationStringResource::localeOptions())->required()])
                ->action(function (array $data) {
                    $csv = app(TranslationExchange::class)->export((string) $data['locale']);
                    AuditLog::record('traduzioni.esportate', 'Esportazione traduzioni ('.strtoupper($data['locale']).')');

                    return response()->streamDownload(function () use ($csv): void {
                        echo $csv;
                    }, 'traduzioni-'.$data['locale'].'-'.now('Europe/Rome')->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
            Action::make('import')->label('Importa dal traduttore')->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->schema([
                    Select::make('locale')->label('Lingua')->options(TranslationStringResource::localeOptions())->required(),
                    FileUpload::make('file')->label('File CSV esportato e controllato')->disk('local')->directory('i18n-import')->visibility('private')->required()
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel']),
                    Toggle::make('approve')->label('Segna come approvate le righe importate')->default(true),
                ])
                ->modalDescription('Aggiorna le traduzioni con quelle del file (colonne: Testo italiano, Traduzione). Le righe senza traduzione non cambiano.')
                ->action(function (array $data): void {
                    $path = Storage::disk('local')->path((string) $data['file']);
                    try {
                        $r = app(TranslationExchange::class)->import((string) $data['locale'], $path, (bool) $data['approve']);
                    } catch (\Throwable $e) {
                        Notification::make()->title('Importazione non riuscita')->body($e->getMessage())->danger()->send();

                        return;
                    } finally {
                        Storage::disk('local')->delete((string) $data['file']);
                    }
                    Locales::forgetStrings((string) $data['locale']);
                    AuditLog::record('traduzioni.importate', 'Importazione traduzioni ('.strtoupper($data['locale']).')', null, $r);
                    Notification::make()->title('Importazione completata')->body("{$r['updated']} righe aggiornate · {$r['unknown']} testi non riconosciuti · {$r['empty']} senza traduzione")->success()->send();
                }),
        ];
    }
}
