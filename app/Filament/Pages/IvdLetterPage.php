<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\Actor;
use App\Domain\Features\Feature;
use App\Domain\Onboarding\IvdLetterSigning;
use App\Domain\Onboarding\IvdLetterTemplate;
use App\Domain\Onboarding\OnboardingException;
use App\Models\IvdLetterEdit;
use App\Models\IvdLetterSignature;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Testo della Lettera di Incarico: lo si modifica qui e si sceglie, al salvataggio, che tipo di modifica è.
 *  - «Salva correzione (refuso)»: sistema un errore di battitura nella versione in vigore. Nessuna firma richiesta.
 *  - «Salva modifica contrattuale»: crea una nuova versione, che va fatta firmare.
 *  - «Invia da firmare»: manda la versione in vigore a tutti gli incaricati registrati che non l'hanno ancora firmata.
 *
 * @property-read Schema $form
 */
class IvdLetterPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Rete';

    protected static ?string $navigationLabel = 'Lettera di incarico';

    protected static ?string $title = 'Lettera di incarico';

    protected static ?string $slug = 'lettera-di-incarico';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.ivd-letter';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('network.manage') && Feature::enabled('backoffice.page.lettera_incarico');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(['body' => app(IvdLetterTemplate::class)->body()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            RichEditor::make('body')->label('Testo della lettera')->required()->columnSpanFull()
                ->toolbarButtons([['bold', 'italic', 'underline', 'strike'], ['h2', 'h3'], ['bulletList', 'orderedList'], ['undo', 'redo']])
                ->extraInputAttributes(['style' => 'min-height:34rem'])
                ->helperText('Le parole tra doppie quadre, come [[CASELLE_REDDITO]] o [[IMPORTO_CANONE]], le compila il sistema: non cancellarle né modificarle. Le caselle dell\'art. 12 devono esserci tutte.'),
        ])->statePath('data');
    }

    /** HTML del testo come è adesso nell'editor (anche non salvato). */
    private function editorHtml(): string
    {
        $state = $this->form->getState();

        return (string) ($state['body'] ?? '');
    }

    private function changeSummary(): HtmlString
    {
        try {
            $d = app(IvdLetterTemplate::class)->diff(app(IvdLetterTemplate::class)->body(), $this->editorHtml());
        } catch (\Throwable) {
            return new HtmlString('Non riesco a confrontare il testo con quello in vigore.');
        }
        $limit = IvdLetterTemplate::MAX_TYPO_WORDS;
        $html = '<p><strong>Rispetto al testo in vigore:</strong> '.$d['added'].' parole aggiunte, '.$d['removed'].' tolte'
            .($d['numbers_changed'] ? ' — <strong>cambiano numeri, importi o date</strong>' : '').'.</p>';
        $html .= '<p style="opacity:.75">Un refuso può toccare al massimo '.$limit.' parole e nessun numero.</p>';
        if ($d['changes'] !== []) {
            $html .= '<ul style="margin-top:.5rem;list-style:disc;padding-left:1.2rem;font-size:.85rem">';
            foreach ($d['changes'] as $c) {
                $html .= '<li>'.e($c).'</li>';
            }
            $html .= '</ul>';
        }

        return new HtmlString($html);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('typo')->label('Salva correzione (refuso)')->icon('heroicon-o-pencil-square')->color('gray')
                ->modalHeading('Salvare come correzione di refuso?')
                ->modalDescription(fn (): HtmlString => new HtmlString('<p>La versione resta la stessa e <strong>nessuno deve firmare di nuovo</strong>. Le lettere già firmate non cambiano.</p>'.$this->changeSummary()->toHtml()))
                ->modalSubmitActionLabel('Salva la correzione')
                ->schema([Textarea::make('summary')->label('Cosa hai corretto (facoltativo, resta nello storico)')->rows(2)->maxLength(500)])
                ->action(function (array $data): void {
                    try {
                        $v = app(IvdLetterTemplate::class)->saveTypoFix($this->editorHtml(), trim((string) ($data['summary'] ?? '')), Actor::label());
                        $this->form->fill(['body' => $v->body]);
                        Notification::make()->title("Correzione salvata nella versione {$v->version}")->body('Nessuna firma richiesta.')->success()->send();
                    } catch (OnboardingException $e) {
                        Notification::make()->title('Correzione non salvata')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Action::make('contractual')->label('Salva modifica contrattuale (da firmare)')->icon('heroicon-o-document-check')->color('warning')
                ->modalHeading('Salvare come modifica contrattuale?')
                ->modalDescription(fn (): HtmlString => new HtmlString('<p>Nasce una <strong>nuova versione</strong> della lettera: i nuovi incaricati firmano questa. Chi è già incaricato la firma solo quando premi <strong>«Invia da firmare»</strong>; le richieste ancora aperte della versione precedente vengono annullate.</p>'.$this->changeSummary()->toHtml()))
                ->modalSubmitActionLabel('Crea la nuova versione')
                ->schema(fn (): array => [
                    Textarea::make('summary')->label('Cosa cambia per gli incaricati')->helperText('Lo leggono nell\'email e nella pagina di firma.')->required()->rows(3)->maxLength(1500),
                    Textarea::make('articles_1341')->label('Articoli da approvare espressamente (artt. 1341-1342 c.c.)')->helperText('Controlla che l\'elenco sia ancora giusto dopo le tue modifiche.')->rows(3)
                        ->default(app(IvdLetterTemplate::class)->articles1341()),
                ])
                ->action(function (array $data): void {
                    try {
                        $v = app(IvdLetterTemplate::class)->saveContractualChange($this->editorHtml(), (string) $data['summary'], (string) ($data['articles_1341'] ?? ''), Actor::label());
                        $this->form->fill(['body' => $v->body]);
                        Notification::make()->title("Nuova versione {$v->version} in vigore")->body('Premi «Invia da firmare» per chiedere la firma a chi è già incaricato.')->success()->persistent()->send();
                    } catch (OnboardingException $e) {
                        Notification::make()->title('Modifica non salvata')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Action::make('send')->label('Invia da firmare')->icon('heroicon-o-paper-airplane')->color('success')
                ->modalHeading('Inviare la lettera da firmare?')
                ->modalDescription(function (): HtmlString {
                    $v = app(IvdLetterTemplate::class)->current();
                    $n = $v === null ? app(IvdLetterSigning::class)->eligible($this->virtualVersion())->count() : app(IvdLetterSigning::class)->eligible($v)->count();
                    $mail = config('lamess.mail_enabled') ? 'Ognuno riceve un\'email con il link per firmare e un avviso nella sua area.' : '<strong>L\'invio email è spento</strong>: gli incaricati vedranno solo l\'avviso nella loro area riservata.';

                    return new HtmlString("<p>La versione in vigore va a <strong>{$n} incaricati</strong> registrati che non l'hanno ancora firmata.</p><p>{$mail}</p>");
                })
                ->modalSubmitActionLabel('Invia')
                ->action(function (): void {
                    try {
                        // la prima volta la versione in vigore esiste solo come testo di base: la si salva così com'è
                        $v = app(IvdLetterTemplate::class)->ensureCurrent(Actor::label());
                        $r = app(IvdLetterSigning::class)->send($v, Actor::label());
                    } catch (OnboardingException $e) {
                        Notification::make()->title('Invio non riuscito')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }
                    if ($r['created'] === 0) {
                        Notification::make()->title('Nessun incaricato da avvisare')->body('Tutti hanno già firmato questa versione o hanno già una richiesta aperta.')->warning()->send();

                        return;
                    }
                    Notification::make()->title("Inviata a {$r['created']} incaricati")->body($r['emailed'] > 0 ? "{$r['emailed']} email in partenza." : 'Nessuna email inviata (invio email spento o indirizzo mancante): vedranno l\'avviso nell\'area.')->success()->send();
                }),
            Action::make('remind')->label('Promemoria ai non firmatari')->icon('heroicon-o-bell')->color('gray')
                ->visible(fn (): bool => ($v = app(IvdLetterTemplate::class)->current()) !== null && IvdLetterSignature::where('ivd_letter_version_id', $v->id)->where('status', 'pending')->exists())
                ->requiresConfirmation()->modalDescription('Rimanda l\'email a chi non ha ancora firmato.')
                ->action(function (): void {
                    $v = app(IvdLetterTemplate::class)->current();
                    $n = $v === null ? 0 : app(IvdLetterSigning::class)->remind($v);
                    Notification::make()->title($n > 0 ? "Promemoria inviato a {$n} incaricati" : 'Nessuna email inviata (invio email spento?)')->color($n > 0 ? 'success' : 'warning')->send();
                }),
        ];
    }

    private function virtualVersion(): \App\Models\IvdLetterVersion
    {
        return new \App\Models\IvdLetterVersion(['version' => (string) config('lamess.ivd_template_version')]);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $tpl = app(IvdLetterTemplate::class);
        $signing = app(IvdLetterSigning::class);
        $v = $tpl->current();

        return [
            'version' => $v, 'label' => $tpl->versionLabel(), 'mailOn' => (bool) config('lamess.mail_enabled'),
            'counts' => $v !== null ? $signing->counts($v) : ['signed' => 0, 'pending' => 0, 'eligible' => $signing->eligible($this->virtualVersion())->count()],
            'signatures' => $v === null ? collect() : IvdLetterSignature::with('consultant')->where('ivd_letter_version_id', $v->id)->orderByRaw("case status when 'pending' then 0 when 'signed' then 1 else 2 end")->orderBy('id')->limit(500)->get(),
            'edits' => IvdLetterEdit::with('version')->latest('id')->limit(15)->get(),
        ];
    }
}
