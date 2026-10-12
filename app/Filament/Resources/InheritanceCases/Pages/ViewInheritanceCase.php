<?php

declare(strict_types=1);

namespace App\Filament\Resources\InheritanceCases\Pages;

use App\Domain\Succession\InheritanceService;
use App\Domain\Succession\SuccessionException;
use App\Filament\Resources\InheritanceCases\InheritanceCaseResource;
use App\Models\InheritanceCase;
use App\Models\InheritanceDocument;
use App\Models\InheritanceHeir;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewInheritanceCase extends ViewRecord
{
    protected static string $resource = InheritanceCaseResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pratica')->columns(3)->schema([
                TextEntry::make('number')->label('Numero'),
                TextEntry::make('consultant.code')->label('Codice'),
                TextEntry::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state) => InheritanceCaseResource::STATUS[$state] ?? $state),
                TextEntry::make('death_date')->label('Data del decesso')->date('d/m/Y'),
                TextEntry::make('outcome')->label('Destino del codice')->formatStateUsing(fn (?string $state) => ['succession' => 'Subentro di un erede', 'termination' => 'Cessato'][$state] ?? 'Da decidere'),
                TextEntry::make('estate_amount')->label('Compensi liquidati (€)')->placeholder('non ancora'),
                TextEntry::make('note')->label('Note')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Documenti')->schema([
                TextEntry::make('docs')->hiddenLabel()->state(fn (InheritanceCase $c) => $c->documents()->orderBy('id')->get()->map(fn (InheritanceDocument $d) => sprintf(
                    '#%d %s — %s (%s)%s', $d->id, InheritanceCase::DOCUMENT_TYPES[$d->type] ?? $d->type, $d->original_name,
                    ['pending' => 'da esaminare', 'approved' => 'approvato', 'rejected' => 'rifiutato'][$d->status], $d->notes ? ': '.$d->notes : '',
                ))->implode("\n") ?: 'Nessun documento')->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
            Section::make('Eredi')->schema([
                TextEntry::make('heirs_list')->hiddenLabel()->state(fn (InheritanceCase $c) => $c->heirs()->orderBy('id')->get()->map(fn (InheritanceHeir $h) => sprintf(
                    '#%d %s (%s) — %s%%%s%s', $h->id, $h->full_name, $h->tax_code, rtrim(rtrim((string) $h->share_percent, '0'), '.'),
                    $h->amount !== null ? " — {$h->amount} € [".['defined' => 'definito', 'to_pay' => 'da pagare', 'paid' => 'pagato'][$h->status].']' : '', $h->reference ? " · {$h->reference}" : '',
                ))->implode("\n") ?: 'Nessun erede indicato')->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
        ]);
    }

    private function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            $this->record->refresh();
            Notification::make()->title($ok)->success()->send();
        } catch (SuccessionException $e) {
            Notification::make()->title('Non eseguibile')->body($e->getMessage())->danger()->send();
        }
    }

    private function svc(): InheritanceService
    {
        return app(InheritanceService::class);
    }

    protected function getHeaderActions(): array
    {
        $open = fn () => $this->record->status !== 'closed';

        return [
            Action::make('addDocument')->authorize('network.manage')->label('Carica documento')->icon('heroicon-o-document-plus')->visible($open)
                ->schema([
                    Select::make('type')->label('Tipo')->options(InheritanceCase::DOCUMENT_TYPES)->required(),
                    FileUpload::make('file')->label('File')->required()->disk('local')->directory('inheritance-tmp')->visibility('private')->storeFileNamesIn('original_name')->maxSize(20480)
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png']),
                ])
                ->action(fn (array $data) => $this->run(fn () => $this->svc()->addDocument($this->record, $data['type'], $data['file'], $data['original_name'] ?? null), 'Documento caricato')),
            Action::make('reviewDocument')->authorize('network.manage')->label('Esamina documento')->icon('heroicon-o-clipboard-document-check')->color('gray')->visible($open)
                ->schema([
                    Select::make('document_id')->label('Documento')->required()->options(fn () => $this->record->documents()->where('status', 'pending')->get()->mapWithKeys(fn ($d) => [$d->id => "#{$d->id} ".(InheritanceCase::DOCUMENT_TYPES[$d->type] ?? $d->type).' — '.$d->original_name])),
                    Radio::make('decision')->label('Esito')->options(['approve' => 'Valido', 'reject' => 'Non valido'])->required()->default('approve'),
                    Textarea::make('notes')->label('Note (obbligatorie se non valido)'),
                ])
                ->action(fn (array $data) => $this->run(fn () => $this->svc()->reviewDocument($this->record->documents()->findOrFail($data['document_id']), $data['decision'] === 'approve', (string) ($data['notes'] ?? '')), 'Documento esaminato')),
            Action::make('setHeirs')->authorize('network.manage')->label('Eredi e quote')->icon('heroicon-o-user-group')->color('gray')
                ->visible(fn () => in_array($this->record->status, ['reported', 'verified'], true))
                ->fillForm(fn () => ['heirs' => $this->record->heirs()->orderBy('id')->get(['full_name', 'tax_code', 'share_percent', 'iban', 'iban_holder'])->map(fn ($h) => $h->toArray())->all()])
                ->schema([
                    Repeater::make('heirs')->label('Eredi')->minItems(1)->columns(3)->schema([
                        TextInput::make('full_name')->label('Nome e cognome')->required(),
                        TextInput::make('tax_code')->label('Codice fiscale')->required()->length(16),
                        TextInput::make('share_percent')->label('Quota %')->numeric()->required()->minValue(0.001)->maxValue(100),
                        TextInput::make('iban')->label('IBAN')->columnSpan(2),
                        TextInput::make('iban_holder')->label('Intestatario IBAN'),
                    ]),
                ])
                ->action(fn (array $data) => $this->run(fn () => $this->svc()->setHeirs($this->record, array_values($data['heirs'])), 'Eredi salvati')),
            Action::make('verify')->authorize('network.manage')->label('Verifica la pratica')->icon('heroicon-o-check-badge')->color('success')->requiresConfirmation()
                ->visible(fn () => $this->record->status === 'reported')
                ->action(fn () => $this->run(fn () => $this->svc()->verify($this->record), 'Pratica verificata')),
            Action::make('liquidate')->authorize('network.manage')->label('Liquida i compensi agli eredi')->icon('heroicon-o-banknotes')->color('warning')
                ->visible(fn () => $this->record->status === 'verified')
                ->modalDescription(fn () => 'Si congelano i compensi maturati e non pagati (oggi '.$this->svc()->estate($this->record->consultant).' €) e si dividono tra gli eredi. Non si può ripetere. Nessuna ritenuta è applicata: valida il trattamento fiscale col commercialista.')
                ->requiresConfirmation()
                ->action(fn () => $this->run(fn () => $this->svc()->liquidate($this->record), 'Compensi liquidati')),
            Action::make('payHeir')->authorize('payouts.manage')->label('Segna un pagamento')->icon('heroicon-o-check-circle')->color('gray')
                ->visible(fn () => $this->record->heirs()->where('status', 'to_pay')->exists())
                ->schema([
                    Select::make('heir_id')->label('Erede')->required()->options(fn () => $this->record->heirs()->where('status', 'to_pay')->get()->mapWithKeys(fn ($h) => [$h->id => "{$h->full_name} — {$h->amount} €"])),
                    TextInput::make('reference')->label('CRO / riferimento del bonifico')->required(),
                    DatePicker::make('paid_at')->label('Data')->default(now()->toDateString())->required(),
                ])
                ->action(fn (array $data) => $this->run(fn () => $this->svc()->markHeirPaid($this->record->heirs()->findOrFail($data['heir_id']), $data['reference'], CarbonImmutable::parse($data['paid_at'], config('app.timezone'))), 'Pagamento registrato')),
            Action::make('succeed')->authorize('network.manage')->label('Subentro di un erede')->icon('heroicon-o-arrow-right-circle')->color('success')
                ->visible(fn () => $this->record->outcome === null && $this->record->status !== 'reported' && $this->record->status !== 'closed')
                ->modalDescription('L\'erede deve essere già registrato sul portale con i dati anagrafici compilati. Firma una nuova Lettera IVD e completa la verifica documenti; il codice resta quello del defunto.')
                ->schema([
                    Select::make('user_id')->label('Erede (utente registrato)')->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search) => User::where('email', 'like', "%{$search}%")->limit(20)->pluck('email', 'id')->all())
                        ->getOptionLabelUsing(fn ($value) => User::find($value)?->email ?? ''),
                ])
                ->action(fn (array $data) => $this->run(fn () => $this->svc()->succeed($this->record, User::findOrFail($data['user_id'])), 'Subentro avviato')),
            Action::make('endAccount')->authorize('network.manage')->label('Fai cessare il codice')->icon('heroicon-o-stop-circle')->color('danger')
                ->visible(fn () => $this->record->outcome === null && $this->record->status !== 'closed')
                ->modalDescription('Nessun erede prosegue l\'attività: l\'account cessa e gli incaricati diretti passano allo sponsor del defunto. Non si annulla.')
                ->requiresConfirmation()
                ->action(fn () => $this->run(fn () => $this->svc()->endAccount($this->record), 'Codice cessato')),
        ];
    }
}
