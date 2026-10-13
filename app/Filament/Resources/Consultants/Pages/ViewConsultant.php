<?php

declare(strict_types=1);

namespace App\Filament\Resources\Consultants\Pages;

use App\Domain\Access\Actor;
use App\Domain\Audit\AuditLog;
use App\Domain\Features\Feature;
use App\Domain\Network\NetworkException;
use App\Domain\Network\NetworkService;
use App\Domain\Network\TreeOperations;
use App\Domain\Onboarding\BadgeService;
use App\Filament\Resources\Consultants\ConsultantResource;
use App\Filament\Support\KycRequestAction;
use App\Filament\Support\SendDocumentsAction;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\ConsultantNote;
use App\Models\Customer;
use App\Models\IvdContract;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Cache;

class ViewConsultant extends ViewRecord
{
    protected static string $resource = ConsultantResource::class;

    /** Ogni apertura della scheda finisce nel log di audit (una volta ogni 10 minuti per la stessa coppia utente/incaricato). */
    public function mount(int|string $record): void
    {
        parent::mount($record);
        $user = auth()->user();
        if ($user !== null && Cache::add("c360.view.{$user->id}.{$this->record->id}", 1, 600)) {
            AuditLog::record('consultant.view', "Aperta la scheda dell’incaricato {$this->record->code}", $this->record);
        }
    }

    private function change(string $to, array $data = []): void
    {
        try {
            app(NetworkService::class)->changeStatus($this->record, $to, $data['reason'] ?? '', null, Actor::label());
            $this->record->refresh();
            Notification::make()->title('Stato aggiornato')->success()->send();
        } catch (NetworkException $e) {
            Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
        }
    }

    /** Ultima lettera d'incarico firmata dall'incaricato (per il certificato di firma). */
    private function signedContractId(): ?int
    {
        $id = IvdContract::where('consultant_id', $this->record->id)->whereNotNull('signed_at')->where(fn ($q) => $q->whereNull('signature_provider')->orWhere('signature_provider', '!=', 'odoo'))->latest('signed_at')->value('id');

        return $id === null ? null : (int) $id;
    }

    public function getTitle(): string
    {
        return 'Scheda incaricato';
    }

    /** Le azioni di servizio diventano icone con la descrizione al passaggio del mouse: stanno su una riga; Sospendi/Riattiva/Cessa restano a testo. */
    protected function getHeaderActions(): array
    {
        $icons = ['requestKyc', 'sendDocuments', 'printBadge', 'signatureCertificate', 'move', 'reassignClients', 'forceRank', 'holdRank', 'addNote', 'revealIban'];

        return array_map(
            static fn ($a) => in_array($a->getName(), $icons, true) ? $a->iconButton()->tooltip($a->getLabel()) : $a,
            $this->headerActionList(),
        );
    }

    private function headerActionList(): array
    {
        $reason = [Textarea::make('reason')->label('Motivazione')->required()];

        $sponsorSelect = fn (string $name, string $label) => Select::make($name)->label($label)->searchable()->live()->required()
            ->getSearchResultsUsing(fn (string $search) => Consultant::where('status', 'active')->where(fn ($w) => $w->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->code} — {$c->name}"]))
            ->getOptionLabelUsing(fn ($value) => ($c = Consultant::find($value)) ? "{$c->code} — {$c->name}" : '');

        return [
            KycRequestAction::forPage(fn () => $this->record),
            SendDocumentsAction::forPage(fn () => $this->record),
            Action::make('printBadge')->authorize('network.manage')->label('Stampa tesserino')->icon('heroicon-o-identification')->color('gray')
                ->visible(fn () => app(BadgeService::class)->isIssuable($this->record))
                ->url(fn () => url("/admin-files/badge/{$this->record->id}"), shouldOpenInNewTab: true),
            Action::make('signatureCertificate')->authorize('network.manage')->label(fn () => $this->signedContractId() === null ? 'Certificato di firma (nessuna firma fatta nel portale: se importata da Odoo, le prove restano in Odoo)' : 'Certificato di firma')->icon('heroicon-o-shield-check')->color('gray')
                ->disabled(fn () => $this->signedContractId() === null)
                ->url(fn () => $this->signedContractId() === null ? null : url('/admin-files/contract/'.$this->signedContractId().'/certificate'), shouldOpenInNewTab: true),
            Action::make('move')->label('Sposta / cambia sponsor')->icon('heroicon-o-arrows-right-left')->color('gray')
                ->visible(fn () => $this->record->status !== 'terminated' && auth()->user()?->isSuperAdmin() === true && Feature::enabled('consultant360.action.move_sponsor'))
                ->modalDescription('Solo super_admin e solo in casi estremi. Eccezione al Reg. 2.10.a: serve un motivo e finisce nel log. Lo sponsor cambia subito; i periodi aperti usano il nuovo albero al prossimo calcolo, quelli chiusi restano come sono.')
                ->schema([
                    $sponsorSelect('new_sponsor_id', 'Nuovo sponsor'),
                    Toggle::make('with_branch')->label('Il ramo segue l’incaricato')->default(true)->live()
                        ->helperText('Spento: si sposta solo lui e i suoi diretti passano al suo sponsor attuale.'),
                    DatePicker::make('effective_from')->label('Efficace dal')->default(now()->toDateString())->maxDate(now()->toDateString())->required()->live()
                        ->helperText('Serve a individuare i mesi già chiusi che l\'operazione tocca.'),
                    Toggle::make('recalc_closed')->label('Ricalcola i mesi chiusi interessati')->default(false)->visible(fn () => auth()->user()?->isSuperAdmin() === true)
                        ->helperText('Registra i mesi da riaprire (la riapertura arriva con la fase 6). Spento: i mesi chiusi restano com\'erano.'),
                    Text::make(function (Get $get): string {
                        $new = $get('new_sponsor_id') ? Consultant::find($get('new_sponsor_id')) : null;
                        if (! $new) {
                            return 'Scegli il nuovo sponsor per vedere l\'anteprima.';
                        }
                        $p = app(TreeOperations::class)->previewMove($this->record, $new, (bool) $get('with_branch'), $get('effective_from') ? new DateTimeImmutable($get('effective_from')) : null);
                        $fmt = fn (array $x) => $x === [] ? 'nessuno' : collect($x)->map(fn ($v, $k) => "{$k} ({$v['movements']} mov., {$v['pv']} PV)")->implode(', ');
                        $lines = $p['ok'] ? [] : array_map(fn ($e) => "⚠ {$e}", $p['errors']);
                        $lines[] = "Persone spostate: {$p['moved_count']} · variazione di profondità: {$p['depth_shift']}";
                        $lines[] = 'Da: '.(implode(' ← ', $p['from_chain']) ?: 'nessuno sponsor').' | A: '.(implode(' ← ', $p['to_chain']));
                        $lines[] = 'Mesi aperti toccati: '.$fmt($p['open_periods']);
                        $lines[] = 'Mesi chiusi toccati: '.$fmt($p['closed_periods']);

                        return implode("\n", $lines);
                    })->extraAttributes(['style' => 'white-space: pre-line']),
                    Textarea::make('reason')->label('Motivo')->required(),
                ])
                ->action(function (array $data): void {
                    abort_unless(auth()->user()?->isSuperAdmin() === true, 403);
                    try {
                        $r = app(TreeOperations::class)->moveBranch(
                            $this->record, Consultant::findOrFail($data['new_sponsor_id']), $data['reason'], (bool) $data['with_branch'],
                            new DateTimeImmutable($data['effective_from']), (bool) ($data['recalc_closed'] ?? false),
                        );
                        $this->record->refresh();
                        Notification::make()->title('Spostamento eseguito')->body("Persone spostate: {$r['moved_count']}")->success()->send();
                    } catch (NetworkException $e) {
                        Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('reassignClients')->authorize('network.restructure')->label('Riassegna i clienti')->icon('heroicon-o-user-group')->color('gray')
                ->visible(fn () => Feature::enabled('consultant360.action.reassign_clients'))
                ->schema([
                    $sponsorSelect('to_consultant_id', 'Nuovo incaricato'),
                    Textarea::make('reason')->label('Motivo')->required(),
                ])
                ->modalDescription('Tutti i clienti assegnati a questo incaricato passano all’incaricato scelto. Gli ordini già fatti non cambiano.')
                ->action(function (array $data): void {
                    try {
                        $n = app(TreeOperations::class)->reassignCustomers(Customer::where('assigned_consultant_id', $this->record->id)->get(), Consultant::findOrFail($data['to_consultant_id']), $data['reason']);
                        Notification::make()->title("Clienti riassegnati: {$n}")->success()->send();
                    } catch (NetworkException $e) {
                        Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('forceRank')->authorize('network.restructure')->label('Forza qualifica')->icon('heroicon-o-star')->color('gray')
                ->visible(fn () => $this->record->status !== 'terminated' && Feature::enabled('consultant360.action.force_rank'))
                ->modalDescription('Cambio manuale: viene segnalato come «forzato» e registrato nel log.')
                ->schema([
                    Select::make('rank_code')->label('Qualifica')->required()->options(fn () => collect(CompPlanVersion::inForce()?->data['ranks'] ?? [])->mapWithKeys(fn ($r) => [$r['code'] => $r['name']])->all()),
                    DatePicker::make('effective_from')->label('Efficace dal')->default(now()->toDateString())->required(),
                    Textarea::make('reason')->label('Motivo')->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(TreeOperations::class)->forceRank($this->record, $data['rank_code'], new DateTimeImmutable($data['effective_from']), $data['reason']);
                        Notification::make()->title('Qualifica forzata')->success()->send();
                    } catch (NetworkException $e) {
                        Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('holdRank')->authorize('network.restructure')->label('Mantieni qualifica')->icon('heroicon-o-shield-check')->color('gray')
                ->visible(fn () => $this->record->status !== 'terminated' && Feature::enabled('consultant360.action.hold_rank'))
                ->modalDescription('La qualifica attuale non si riduce per mancanza di clienti fino alla data scelta (può comunque salire). Lascia la data vuota per togliere la protezione. Viene registrato nel log.')
                ->fillForm(fn () => ['rank_hold_until' => $this->record->rank_hold_until?->format('Y-m-d'), 'reason' => $this->record->rank_hold_reason])
                ->schema([
                    DatePicker::make('rank_hold_until')->label('Mantieni la qualifica fino al')->native(false)->displayFormat('d/m/Y')->format('Y-m-d')
                        ->helperText('Sono protetti i mesi che finiscono entro questa data: per proteggere tutto ottobre indica il 31/10.'),
                    Textarea::make('reason')->label('Motivo')->required()->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $until = filled($data['rank_hold_until'] ?? null) ? (string) $data['rank_hold_until'] : null;
                    $before = $this->record->rank_hold_until?->format('Y-m-d');
                    $this->record->update(['rank_hold_until' => $until, 'rank_hold_reason' => $until === null ? null : $data['reason']]);
                    AuditLog::record(
                        $until === null ? 'rank.hold_removed' : 'rank.hold',
                        $until === null ? "Protezione della qualifica tolta: incaricato {$this->record->code}" : "Qualifica mantenuta fino al {$until}: incaricato {$this->record->code}",
                        $this->record, ['fino_al' => ['prima' => $before, 'dopo' => $until]], $data['reason'],
                    );
                    Notification::make()->title($until === null ? 'Protezione tolta' : 'Qualifica mantenuta fino al '.CarbonImmutable::parse($until)->format('d/m/Y'))->body('Vale dal prossimo ricalcolo del periodo (automatico ogni notte).')->success()->send();
                }),
            Action::make('suspend')->authorize('network.manage')->label('Sospendi')->color('warning')->schema($reason)
                ->visible(fn () => $this->record->status === 'active' && Feature::enabled('consultant360.action.suspend'))->action(fn (array $data) => $this->change('suspended', $data)),
            Action::make('reactivate')->authorize('network.manage')->label('Riattiva')->color('success')
                ->visible(fn () => in_array($this->record->status, ['suspended', 'pending'], true) && Feature::enabled('consultant360.action.suspend'))->requiresConfirmation()->action(fn () => $this->change('active')),
            Action::make('terminate')->authorize('network.manage')->label('Cessa')->color('danger')->schema($reason)
                ->modalDescription('Gli incaricati diretti vengono ricollegati al primo ascendente con contratto in essere (Reg. 2.8). L\'operazione non si annulla.')
                ->visible(fn () => $this->record->status !== 'terminated' && Feature::enabled('consultant360.action.terminate'))->action(fn (array $data) => $this->change('terminated', $data)),
            Action::make('addNote')->authorize('network.manage')->label('Aggiungi nota')->icon('heroicon-o-pencil-square')->color('gray')
                ->visible(fn () => Feature::enabled('consultant360.section.notes'))
                ->modalDescription('Nota interna: la vedono solo gli amministratori, non si modifica né si cancella.')
                ->schema([
                    Textarea::make('body')->label('Nota')->required()->maxLength(2000)->rows(4),
                    Toggle::make('pinned')->label('Fissa in cima allo storico'),
                ])
                ->action(function (array $data): void {
                    ConsultantNote::create(['consultant_id' => $this->record->id, 'body' => $data['body'], 'pinned' => (bool) ($data['pinned'] ?? false), 'author_label' => Actor::label()]);
                    AuditLog::record('consultant.note', "Nota aggiunta all’incaricato {$this->record->code}", $this->record, ['fissata' => (bool) ($data['pinned'] ?? false)]);
                    Notification::make()->title('Nota aggiunta')->success()->send();
                }),
            Action::make('revealIban')->authorize('network.manage')->label('Mostra IBAN')->icon('heroicon-o-eye')->color('gray')
                ->visible(fn () => filled($this->record->iban) && Feature::enabled('consultant360.action.reveal_iban'))
                ->requiresConfirmation()->modalDescription('L\'IBAN compare per intero e la visualizzazione viene registrata nel log.')
                ->action(function (): void {
                    AuditLog::record('consultant.reveal_iban', "IBAN mostrato per intero: incaricato {$this->record->code}", $this->record);
                    Notification::make()->title('IBAN')->body((string) $this->record->iban.($this->record->iban_holder ? " — {$this->record->iban_holder}" : ''))->persistent()->send();
                }),
        ];
    }
}
