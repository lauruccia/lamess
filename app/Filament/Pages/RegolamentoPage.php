<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\Actor;
use App\Domain\Features\Feature;
use App\Domain\Onboarding\OnboardingException;
use App\Domain\Onboarding\RegolamentoDocument;
use App\Models\LegalDocumentEdit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * «Principi generali e Regolamento» (Allegato 1): il testo che l'incaricato accetta, con una casella dedicata, prima di firmare la Lettera.
 *  - «Salva correzione (refuso)»: stessa versione.
 *  - «Pubblica nuova versione»: da quel momento chi firma accetta la nuova versione. Chi ha già firmato non è obbligato a rifirmare:
 *    se serve una nuova accettazione, va fatta con una modifica contrattuale della Lettera.
 *
 * @property-read Schema $form
 */
class RegolamentoPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Rete';

    protected static ?string $navigationLabel = 'Regolamento incaricati';

    protected static ?string $title = 'Principi generali e Regolamento';

    protected static ?string $slug = 'regolamento-incaricati';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.regolamento';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('network.manage') && Feature::enabled('backoffice.page.regolamento_incaricati');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(['body' => app(RegolamentoDocument::class)->html()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            RichEditor::make('body')->label('Testo del Regolamento')->required()->columnSpanFull()
                ->toolbarButtons([['bold', 'italic', 'underline', 'strike'], ['h2', 'h3'], ['bulletList', 'orderedList'], ['undo', 'redo']])
                ->extraInputAttributes(['style' => 'min-height:34rem']),
        ])->statePath('data');
    }

    private function editorHtml(): string
    {
        return (string) ($this->form->getState()['body'] ?? '');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('typo')->label('Salva correzione (refuso)')->icon('heroicon-o-pencil-square')->color('gray')
                ->modalHeading('Salvare come correzione di refuso?')
                ->modalDescription('La versione resta la stessa. Il testo cambia subito sul sito e nella pagina di firma.')
                ->schema([Textarea::make('summary')->label('Cosa hai corretto (facoltativo, resta nello storico)')->rows(2)->maxLength(500)])
                ->action(function (array $data): void {
                    try {
                        $d = app(RegolamentoDocument::class)->saveTypoFix($this->editorHtml(), trim((string) ($data['summary'] ?? '')), Actor::label());
                        $this->form->fill(['body' => $d->body]);
                        Notification::make()->title("Correzione salvata nella versione {$d->version}")->success()->send();
                    } catch (OnboardingException $e) {
                        Notification::make()->title('Correzione non salvata')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Action::make('version')->label('Pubblica nuova versione')->icon('heroicon-o-document-check')->color('warning')
                ->modalHeading('Pubblicare una nuova versione del Regolamento?')
                ->modalDescription('Da subito chi firma la Lettera accetta questa versione. Chi ha già firmato non deve rifirmare, salvo che tu faccia una modifica contrattuale della Lettera.')
                ->schema(fn (): array => [
                    TextInput::make('version')->label('Numero della nuova versione')->placeholder('es. 2.1')->required()->maxLength(30),
                    Textarea::make('summary')->label('Cosa cambia')->required()->rows(3)->maxLength(1500),
                    Textarea::make('articles_1341')->label('Articoli da approvare espressamente (artt. 1341-1342 c.c.)')->rows(3)
                        ->default(app(RegolamentoDocument::class)->articles1341()),
                ])
                ->action(function (array $data): void {
                    try {
                        $d = app(RegolamentoDocument::class)->saveNewVersion($this->editorHtml(), (string) $data['version'], (string) $data['summary'], (string) ($data['articles_1341'] ?? ''), Actor::label());
                        $this->form->fill(['body' => $d->body]);
                        Notification::make()->title("Regolamento versione {$d->version} in vigore")->success()->persistent()->send();
                    } catch (OnboardingException $e) {
                        Notification::make()->title('Versione non pubblicata')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $svc = app(RegolamentoDocument::class);
        $row = $svc->row();

        return [
            'version' => $svc->version(), 'row' => $row,
            'edits' => $row === null ? collect() : LegalDocumentEdit::where('legal_document_id', $row->id)->latest('id')->limit(15)->get(),
        ];
    }
}
