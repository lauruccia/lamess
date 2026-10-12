<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Verifica della catena di hash del log di audit — SOLO super_admin. */
class AuditVerificationPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $navigationLabel = 'Verifica del log';

    protected static ?string $title = 'Verifica del log di audit';

    protected static ?string $slug = 'verifica-audit';

    protected static ?int $navigationSort = 93;

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public function mount(): void
    {
        $this->check(record: false);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verify')->label('Verifica ora')->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    $this->check(record: true);
                    $this->result['ok']
                        ? Notification::make()->title('Catena integra')->success()->send()
                        : Notification::make()->title('Catena compromessa')->body((string) $this->result['problem'])->danger()->persistent()->send();
                }),
            Action::make('anchor')->label('Confronta con un\'ancora annotata')->icon('heroicon-o-bookmark')->color('gray')
                ->schema([TextInput::make('anchor')->label('Ancora (numero:hash)')->required()->placeholder('123:abcdef…')])
                ->action(function (array $data): void {
                    $problem = AuditLog::checkAnchor((string) $data['anchor']);
                    $problem === null
                        ? Notification::make()->title('L\'ancora coincide: nessuna riga è stata tolta o cambiata')->success()->send()
                        : Notification::make()->title('Ancora NON coincidente')->body($problem)->danger()->persistent()->send();
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Esito')->schema([
                Text::make(fn () => $this->result === null ? '—' : ($this->result['ok'] ? 'CATENA INTEGRA' : 'CATENA COMPROMESSA'))->badge()
                    ->color(fn () => $this->result === null ? 'gray' : ($this->result['ok'] ? 'success' : 'danger')),
                Text::make(fn () => $this->summary()),
            ]),
            Section::make('Ancora da annotare')
                ->description('Cancellare le ultime righe non rompe la catena. Annota questo valore fuori dal sistema (un quaderno, un altro file) e confrontalo di tanto in tanto: se la riga non c\'è più o è diversa, il log è stato accorciato o alterato.')
                ->schema([Text::make(fn () => (string) ($this->result['anchor'] ?? 'Il log è ancora vuoto.'))->fontFamily('mono')->copyable()]),
            Section::make('Protezione nel database')->schema([
                Text::make(fn () => ($this->result['db_protected'] ?? false)
                    ? 'Attiva: il database rifiuta qualsiasi modifica o cancellazione delle righe.'
                    : 'Non attiva: questo database non ha consentito i trigger di protezione. Restano la catena di hash e il blocco nel codice. Se possibile, assegna il privilegio TRIGGER all\'utente del database e riesegui la migrazione.'),
            ]),
        ]);
    }

    private function check(bool $record): void
    {
        $r = AuditLog::verify();
        $this->result = [
            'ok' => $r->ok, 'checked' => $r->checked, 'total' => $r->total, 'bad_id' => $r->badId, 'problem' => $r->problem,
            'anchor' => $r->anchor(), 'db_protected' => $r->dbProtected, 'at' => now('Europe/Rome')->format('d/m/Y H:i:s'),
        ];
        if ($record) {
            AuditLog::record('audit.verified', $r->ok ? 'Verifica del log: catena integra' : 'Verifica del log: catena COMPROMESSA', ['AuditLog', 'catena'], [
                'righe_controllate' => $r->checked, 'righe_totali' => $r->total, 'esito' => $r->ok ? 'integra' : 'compromessa', 'problema' => $r->problem,
            ]);
        }
    }

    private function summary(): string
    {
        if ($this->result === null) {
            return '';
        }

        return "Controllate {$this->result['checked']} righe su {$this->result['total']} alle {$this->result['at']}."
            .($this->result['problem'] ? ' '.ucfirst((string) $this->result['problem']).'.' : '');
    }
}
