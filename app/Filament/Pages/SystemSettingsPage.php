<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLog;
use App\Domain\Settings\SystemSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Impostazioni di sistema — SOLO super_admin. Le impostazioni di sicurezza (2FA, driver, chiavi) restano nel .env:
 * qui si vedono soltanto, nella sezione «Stato del sistema».
 *
 * @property-read Schema $form
 */
class SystemSettingsPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $navigationLabel = 'Impostazioni di sistema';

    protected static ?string $title = 'Impostazioni di sistema';

    protected static ?string $slug = 'impostazioni';

    protected static ?int $navigationSort = 91;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(SystemSettings::all() + ['reason' => null]);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];
        $groups = [];
        foreach ($this->definitions() as $key => $def) {
            $groups[$def['group']][(string) $key] = $def;
        }
        foreach ($groups as $group => $defs) {
            $fields = [];
            foreach ($defs as $key => $def) {
                $field = match ($def['type']) {
                    'bool' => Toggle::make($key),
                    'int' => TextInput::make($key)->numeric()->integer(),
                    'text' => Textarea::make($key)->rows(3),
                    'select' => Select::make($key)->options($def['options'] ?? [])->native(false),
                    default => TextInput::make($key),
                };
                $fields[] = $field->label($def['label'])->helperText(trim(($def['help'] ?? '').(SystemSettings::overridden($key) ? ' Valore impostato da pannello.' : ' Valore del file di configurazione (.env).')));
            }
            $sections[] = Section::make($group)->columns(2)->schema($fields);
        }
        $sections[] = Section::make('Motivo della modifica')->schema([
            TextInput::make('reason')->label('Motivo (facoltativo, finisce nel log di audit)')->maxLength(250),
        ]);

        return $schema->components($sections)->statePath('data');
    }

    /**
     * Le impostazioni che questa pagina mostra (tutte, per il super_admin).
     *
     * @return array<string, array<string, mixed>>
     */
    protected function definitions(): array
    {
        return SystemSettings::DEFINITIONS;
    }

    /** @return list<mixed> riquadri informativi sopra il modulo */
    protected function intro(): array
    {
        return [];
    }

    protected function showSystemStatus(): bool
    {
        return true;
    }

    public function content(Schema $schema): Schema
    {
        $components = $this->intro();
        $components[] = Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([Actions::make([Action::make('save')->label('Salva le impostazioni')->submit('save')])]);
        if ($this->showSystemStatus()) {
            $components[] = Section::make('Stato del sistema (sola lettura)')->description('Si cambia dal file .env sul server.')->columns(2)->schema(
                array_map(fn (array $row) => Text::make($row[0].': '.$row[1]), $this->statusRows())
            );
        }

        return $schema->components($components);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $reason = isset($state['reason']) && $state['reason'] !== '' ? (string) $state['reason'] : null;
        unset($state['reason']);
        try {
            $changes = SystemSettings::update($state, $reason);
        } catch (ValidationException $e) {
            Notification::make()->title('Valori non validi')->body(collect($e->errors())->flatten()->implode("\n"))->danger()->send();

            return;
        }
        $changes === []
            ? Notification::make()->title('Nessuna modifica')->send()
            : Notification::make()->title('Impostazioni salvate')->body(count($changes).' valore/i aggiornato/i e registrato/i nel log di audit.')->success()->send();
    }

    /** @return list<array{0: string, 1: string}> */
    private function statusRows(): array
    {
        $yes = fn (bool $b) => $b ? 'sì' : 'no';

        return [
            ['Ambiente', (string) config('app.env')],
            ['Modalità debug', $yes((bool) config('app.debug')).(config('app.debug') && config('app.env') === 'production' ? ' — DA SPEGNERE in produzione' : '')],
            ['2FA obbligatoria per il back-office', $yes((bool) config('lamess.admin_2fa_required'))],
            ['Invio email', $yes((bool) config('lamess.mail_enabled'))],
            ['Versione PHP', PHP_VERSION],
            ['Laravel', app()->version()],
            ['Database', (string) config('database.default')],
            ['Driver firma / pagamenti / fatture', config('lamess.signature_driver').' / '.config('lamess.payment_driver').' / '.config('lamess.invoice_sink_driver')],
            ['Log di audit protetto dal database', $yes(AuditLog::dbProtected())],
        ];
    }
}
