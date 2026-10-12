<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComplianceCases\Pages;

use App\Domain\Compliance\ComplianceException;
use App\Domain\Compliance\ComplianceService;
use App\Filament\Resources\ComplianceCases\ComplianceCaseResource;
use App\Filament\Resources\Sanctions\SanctionResource;
use App\Models\ComplianceCase;
use App\Models\Sanction;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewComplianceCase extends ViewRecord
{
    protected static string $resource = ComplianceCaseResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Caso')->columns(3)->schema([
                TextEntry::make('number')->label('Numero'),
                TextEntry::make('consultant.code')->label('Incaricato'),
                TextEntry::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state) => ComplianceCaseResource::statusLabel($state)),
                TextEntry::make('category')->label('Categoria')->formatStateUsing(fn (string $state) => ComplianceCase::CATEGORIES[$state] ?? $state),
                TextEntry::make('opened_by')->label('Aperto da')->placeholder('—'),
                TextEntry::make('ticket.number')->label('Ticket collegato')->placeholder('—'),
                TextEntry::make('description')->label('Fatti')->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-line']),
                TextEntry::make('decision_note')->label('Decisione')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Sanzioni')->schema([
                TextEntry::make('sanctions_list')->hiddenLabel()->state(fn (ComplianceCase $c) => $c->sanctions()->get()->map(fn (Sanction $s) => sprintf(
                    '%s — %s dal %s%s: %s', Sanction::TYPES[$s->type], ['active' => 'in corso', 'lifted' => 'revocata', 'expired' => 'scaduta'][$s->status], $s->starts_at->setTimezone('Europe/Rome')->format('d/m/Y'),
                    $s->ends_at ? ' al '.$s->ends_at->setTimezone('Europe/Rome')->format('d/m/Y') : '', $s->reason,
                ))->implode("\n") ?: 'Nessuna')->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
        ]);
    }

    private function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            $this->record->refresh();
            Notification::make()->title($ok)->success()->send();
        } catch (ComplianceException $e) {
            Notification::make()->title('Non eseguibile')->body($e->getMessage())->danger()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('investigate')->authorize('compliance.manage')->label('Avvia l\'istruttoria')->icon('heroicon-o-magnifying-glass')->color('gray')
                ->visible(fn () => $this->record->status === 'open')
                ->action(fn () => $this->run(fn () => app(ComplianceService::class)->investigate($this->record), 'Istruttoria avviata')),
            Action::make('sanction')->authorize('compliance.manage')->label('Applica una sanzione')->icon('heroicon-o-exclamation-triangle')->color('danger')
                ->visible(fn () => $this->record->isOpen() || $this->record->status === 'sanctioned')
                ->schema(SanctionResource::applyForm(false))
                ->action(function (array $data): void {
                    SanctionResource::apply($this->record->consultant, $data, $this->record);
                    $this->record->refresh();
                }),
            Action::make('dismiss')->authorize('compliance.manage')->label('Archivia')->icon('heroicon-o-archive-box')->color('gray')
                ->visible(fn () => $this->record->isOpen())
                ->schema([Textarea::make('note')->label('Motivazione')->required()])
                ->action(fn (array $data) => $this->run(fn () => app(ComplianceService::class)->dismiss($this->record, $data['note']), 'Caso archiviato')),
        ];
    }
}
