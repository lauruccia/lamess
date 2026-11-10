<?php

declare(strict_types=1);

namespace App\Filament\Resources\TranslationStrings;

use App\Domain\Access\Superadmin;
use App\Domain\Audit\AuditLog;
use App\Domain\I18n\Locales;
use App\Domain\I18n\TranslationService;
use App\Filament\Resources\TranslationStrings\Pages\ListTranslationStrings;
use App\Models\TranslationString;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Traduzioni — SOLO super_admin. Una riga per testo e per lingua: il testo italiano è la chiave. Anche l'italiano si
 * modifica da qui (la correzione sostituisce il testo scritto nella pagina, senza deploy). Le righe tradotte da una
 * macchina restano «da rivedere» finché non si approvano.
 */
class TranslationStringResource extends Resource
{
    protected static ?string $model = TranslationString::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $modelLabel = 'traduzione';

    protected static ?string $pluralModelLabel = 'Traduzioni';

    protected static ?string $navigationLabel = 'Traduzioni';

    protected static ?string $slug = 'traduzioni';

    protected static ?int $navigationSort = 61;

    public const STATUS_LABELS = [
        TranslationString::MISSING => 'Mancante',
        TranslationString::AUTO => 'Da rivedere',
        TranslationString::APPROVED => 'Approvata',
    ];

    public static function canViewAny(): bool
    {
        return Superadmin::is();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** @return array<string, string> codice → nome nativo */
    public static function localeOptions(): array
    {
        return collect(Locales::all())->mapWithKeys(fn (array $l) => [$l['code'] => $l['native_name'].' ('.strtoupper($l['code']).')'])->all();
    }

    /** @return array<string, string> lingue da tradurre (tutte tranne la sorgente) */
    public static function targetOptions(): array
    {
        return collect(self::localeOptions())->except(Locales::default())->all();
    }

    /** Segnaposto (:nome, %s), tag ed entità devono restare uguali al testo italiano. */
    public static function sameTokens(string $source, string $text): bool
    {
        $svc = new TranslationService;
        $a = $svc->mask($source)[1];
        $b = $svc->mask($text)[1];
        sort($a);
        sort($b);

        return $a === $b;
    }

    public static function table(Table $table): Table
    {
        $firstTarget = array_key_first(self::targetOptions());

        return $table->defaultSort('id')->paginated([25, 50, 100])->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('locale')->label('Lingua')->badge()->formatStateUsing(fn (string $state) => strtoupper($state))->sortable(),
                TextColumn::make('source')->label('Testo italiano')->wrap()->limit(220)->searchable()->tooltip(fn (TranslationString $r) => mb_strlen($r->source) > 220 ? $r->source : null),
                TextColumn::make('text')->label('Traduzione')->wrap()->limit(220)->placeholder('—')->searchable()
                    ->tooltip(fn (TranslationString $r) => mb_strlen((string) $r->text) > 220 ? $r->text : null),
                TextColumn::make('status')->label('Stato')->badge()->sortable()
                    ->color(fn (string $state) => match ($state) {
                        TranslationString::APPROVED => 'success', TranslationString::AUTO => 'warning', default => 'gray'
                    })
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state),
            ])
            ->filters([
                SelectFilter::make('locale')->label('Lingua')->options(self::localeOptions())->default($firstTarget),
                SelectFilter::make('status')->label('Stato')->options(self::STATUS_LABELS),
                SelectFilter::make('orphan')->label('Testi')->options(['0' => 'Presenti nel sito', '1' => 'Orfani (non più nel sito)'])->default('0')
                    ->query(fn (Builder $q, array $data) => ($data['value'] ?? null) === null || $data['value'] === '' ? $q : $q->where('orphan', $data['value'] === '1')),
            ])
            ->recordActions([
                Action::make('edit')->label('Modifica')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Modifica')->modalWidth('3xl')
                    ->modalHeading(fn (TranslationString $r) => 'Modifica la traduzione ('.strtoupper($r->locale).')')
                    ->fillForm(fn (TranslationString $r) => ['source' => $r->source, 'text' => $r->text ?? ($r->locale === Locales::default() ? $r->source : ''), 'approve' => true])
                    ->schema(fn (TranslationString $r) => [
                        Textarea::make('source')->label('Testo italiano (sorgente)')->rows(3)->disabled()->dehydrated(false),
                        Textarea::make('text')->label($r->locale === Locales::default() ? 'Testo italiano da mostrare nel sito' : 'Traduzione')->rows(6)->autosize()
                            ->helperText($r->locale === Locales::default() ? 'Se lo lasci uguale all\'originale non cambia nulla.' : 'Non toccare segnaposto (:nome), tag HTML e nomi del brand.')
                            ->rules([fn () => function (string $attribute, $value, \Closure $fail) use ($r): void {
                                if ($r->locale !== Locales::default() && trim((string) $value) !== '' && ! self::sameTokens($r->source, (string) $value)) {
                                    $fail('Segnaposto, tag o termini del brand non coincidono con il testo italiano: controlla che ci siano tutti, identici.');
                                }
                            }]),
                        Toggle::make('approve')->label('Segna come approvata')->default(true)->visible($r->locale !== Locales::default()),
                    ])
                    ->action(function (array $data, TranslationString $record): void {
                        $text = trim((string) ($data['text'] ?? ''));
                        $before = $record->text;
                        if ($record->locale === Locales::default()) {
                            $text = $text === $record->source ? '' : $text;
                            $record->forceFill(['text' => $text === '' ? null : $text, 'status' => $text === '' ? TranslationString::MISSING : TranslationString::APPROVED])->save();
                        } else {
                            $record->forceFill(['text' => $text === '' ? null : $text, 'status' => $text === '' ? TranslationString::MISSING : (($data['approve'] ?? true) ? TranslationString::APPROVED : TranslationString::AUTO)])->save();
                        }
                        AuditLog::record('traduzioni.modificata', 'Traduzione modificata ('.strtoupper($record->locale).')', $record, ['testo' => ['prima' => $before, 'dopo' => $record->text]]);
                        Notification::make()->title('Traduzione salvata')->success()->send();
                    }),
                Action::make('translate')->label('Traduci in automatico')->icon('heroicon-o-sparkles')->iconButton()->tooltip('Traduci in automatico')
                    ->visible(fn (TranslationString $r) => $r->locale !== Locales::default())
                    ->requiresConfirmation()->modalDescription('Sostituisce la traduzione di questa riga con quella del servizio automatico (stato «da rivedere»).')
                    ->action(fn (TranslationString $record) => self::runTranslate($record->locale, [$record->id])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approve')->label('Approva le selezionate')->icon('heroicon-o-check-badge')->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $ids = $records->filter(fn (TranslationString $r) => $r->locale !== Locales::default() && filled($r->text))->pluck('id');
                            TranslationString::whereIn('id', $ids)->update(['status' => TranslationString::APPROVED]);
                            $records->pluck('locale')->unique()->each(fn ($l) => Locales::forgetStrings($l));
                            AuditLog::record('traduzioni.approvate', $ids->count().' traduzioni approvate', null, ['righe' => $ids->count()]);
                            Notification::make()->title($ids->count().' traduzioni approvate')->success()->send();
                        }),
                    BulkAction::make('review')->label('Rimanda in revisione')->icon('heroicon-o-arrow-uturn-left')->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $ids = $records->filter(fn (TranslationString $r) => $r->locale !== Locales::default() && filled($r->text))->pluck('id');
                            TranslationString::whereIn('id', $ids)->update(['status' => TranslationString::AUTO]);
                            $records->pluck('locale')->unique()->each(fn ($l) => Locales::forgetStrings($l));
                            Notification::make()->title($ids->count().' traduzioni da rivedere')->success()->send();
                        }),
                    BulkAction::make('translate')->label('Traduci in automatico')->icon('heroicon-o-sparkles')->requiresConfirmation()->deselectRecordsAfterCompletion()
                        ->modalDescription('Le righe selezionate (tranne l\'italiano) si traducono con il servizio automatico e restano «da rivedere». Un testo già tradotto viene sostituito.')
                        ->action(function (Collection $records): void {
                            foreach ($records->where('locale', '!=', Locales::default())->groupBy('locale') as $locale => $rows) {
                                self::runTranslate((string) $locale, $rows->pluck('id')->all());
                            }
                        }),
                ]),
            ]);
    }

    /** @param list<int>|null $ids */
    public static function runTranslate(string $locale, ?array $ids = null, int $limit = 200): void
    {
        try {
            $r = (new TranslationService)->translate($locale, $ids, $limit);
        } catch (\Throwable $e) {
            Notification::make()->title('Traduzione automatica non riuscita')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }
        AuditLog::record('traduzioni.automatiche', $r['translated'].' testi tradotti in automatico ('.strtoupper($locale).')', null, ['tradotti' => $r['translated'], 'non_riusciti' => $r['failed']]);
        $n = Notification::make()->title($r['translated'].' testi tradotti, da rivedere');
        if ($r['failed'] > 0 || $r['error'] !== null) {
            $n->body(($r['error'] ?? $r['failed'].' testi non tradotti (segnaposto o tag non tornavano).').' Puoi ripetere l\'operazione.')->warning();
        } else {
            $n->success();
        }
        $n->send();
    }

    public static function getPages(): array
    {
        return ['index' => ListTranslationStrings::route('/')];
    }
}
