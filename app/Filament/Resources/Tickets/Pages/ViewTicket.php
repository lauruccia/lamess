<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Pages;

use App\Domain\Access\Actor;
use App\Domain\Compliance\ComplianceException;
use App\Domain\Compliance\ComplianceService;
use App\Domain\Payouts\ReceiptService;
use App\Domain\Payouts\StatementLifecycle;
use App\Domain\Support\SupportException;
use App\Domain\Support\TicketService;
use App\Filament\Resources\ComplianceCases\Pages\ListComplianceCases;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\CommissionReceipt;
use App\Models\CommissionStatement;
use App\Models\ComplianceCase;
use App\Models\Consultant;
use App\Models\Ticket;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Richiesta')->columns(3)->schema([
                TextEntry::make('number')->label('Numero'),
                TextEntry::make('category')->label('Categoria')->formatStateUsing(fn (string $state) => Ticket::CATEGORIES[$state] ?? $state),
                TextEntry::make('status')->label('Stato')->badge(),
                TextEntry::make('consultant.code')->label('Incaricato')->placeholder('—'),
                TextEntry::make('subject')->label('Oggetto')->columnSpan(2),
            ]),
            Section::make('Conversazione')->schema([
                TextEntry::make('thread')->hiddenLabel()->state(fn (Ticket $t) => $t->messages()->get()->map(fn ($m) => sprintf(
                    '[%s] %s%s: %s', $m->created_at->setTimezone('Europe/Rome')->format('d/m/Y H:i'),
                    $m->is_internal ? 'NOTA INTERNA — ' : '', $m->is_staff ? 'Assistenza' : 'Utente', $m->body,
                ))->implode("\n\n"))->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
        ]);
    }

    /** Il ticket è un reclamo su estratto o ricevuta ancora da decidere (si chiude solo con una decisione). */
    private function hasOpenDispute(): bool
    {
        $r = $this->record->related;

        return ($r instanceof CommissionStatement && $r->status === CommissionStatement::DISPUTED) || ($r instanceof CommissionReceipt && $r->status === 'disputed');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')->authorize('support.manage')->label('Rispondi')->icon('heroicon-o-paper-airplane')
                ->visible(fn () => $this->record->status !== Ticket::CLOSED)
                ->schema([Textarea::make('body')->label('Messaggio')->required()->rows(5), Toggle::make('internal')->label('Nota interna (il richiedente non la vede)')])
                ->action(function (array $data, TicketService $svc): void {
                    try {
                        $svc->reply($this->record, Auth::user(), $data['body'], true, (bool) ($data['internal'] ?? false));
                        Notification::make()->title('Messaggio registrato')->success()->send();
                    } catch (SupportException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('assign')->authorize('support.manage')->label('Prendi in carico')->icon('heroicon-o-hand-raised')->color('gray')
                ->visible(fn () => $this->record->status !== Ticket::CLOSED)
                ->action(fn (TicketService $svc) => $svc->assign($this->record, Actor::label())),
            Action::make('openCase')->authorize('compliance.manage')->label('Apri un caso di conformità')->icon('heroicon-o-shield-exclamation')->color('danger')
                ->visible(fn () => $this->record->category === 'report' && $this->record->status !== Ticket::CLOSED && ! ComplianceCase::where('ticket_id', $this->record->id)->exists())
                ->fillForm(fn () => ['description' => (string) $this->record->messages()->where('is_internal', false)->orderBy('id')->value('body')])
                ->schema(ListComplianceCases::openForm())
                ->action(function (array $data): void {
                    try {
                        $case = app(ComplianceService::class)->openCase(Consultant::findOrFail($data['consultant_id']), $data['category'], $data['description'], $this->record);
                        Notification::make()->title("Caso {$case->number} aperto")->success()->send();
                    } catch (ComplianceException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('decide')->authorize('support.manage')->label('Decidi il reclamo')->icon('heroicon-o-scale')->color('warning')
                ->visible(fn () => $this->hasOpenDispute())
                ->schema([
                    Select::make('outcome')->label('Esito')->required()->options(['upheld' => 'Accolto', 'rejected' => 'Respinto']),
                    Textarea::make('note')->label('Motivazione (la legge l’incaricato)')->required(),
                ])
                ->modalDescription('Chiude il ticket. Se accogli il reclamo, la correzione economica si fa con una rettifica nel mese aperto.')
                ->action(function (array $data, StatementLifecycle $statements, ReceiptService $receipts): void {
                    try {
                        $related = $this->record->related;
                        $related instanceof CommissionReceipt
                            ? $receipts->resolve($related, $data['outcome'], $data['note'], Auth::user())
                            : $statements->resolve($related, $data['outcome'], $data['note'], Auth::user());
                        Notification::make()->title('Reclamo deciso')->success()->send();
                    } catch (SupportException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('close')->authorize('support.manage')->label('Chiudi')->icon('heroicon-o-check-circle')->color('gray')
                ->visible(fn () => $this->record->status !== Ticket::CLOSED && ! $this->hasOpenDispute())
                ->requiresConfirmation()->action(fn (TicketService $svc) => $svc->close($this->record)),
            Action::make('reopen')->authorize('support.manage')->label('Riapri')->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn () => $this->record->status === Ticket::CLOSED)
                ->action(function (TicketService $svc): void {
                    try {
                        $svc->reopen($this->record);
                    } catch (SupportException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
