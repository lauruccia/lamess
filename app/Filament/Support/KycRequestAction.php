<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\Actor;
use App\Domain\Onboarding\KycService;
use App\Domain\Onboarding\OnboardingException;
use App\Models\Consultant;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/** «Richiedi documenti»: l'admin chiede all'incaricato i documenti per la verifica KYC (scheda, elenco e azione di massa). */
final class KycRequestAction
{
    /** @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Field> */
    private static function form(?Consultant $c = null): array
    {
        return [
            CheckboxList::make('types')->label('Documenti da richiedere')->required()->columns(1)
                ->options((array) config('lamess.kyc_document_types'))
                ->default($c !== null ? app(KycService::class)->missingTypes($c) : (array) config('lamess.kyc_required_documents')),
            Textarea::make('message')->label('Messaggio per l’incaricato (facoltativo)')->rows(3),
            TextInput::make('due_days')->label('Giorni per rispondere')->numeric()->minValue(0)->maxValue(90)->default((int) config('lamess.kyc_request_due_days', 7))
                ->helperText('0 = nessuna scadenza. Se non risponde, riceve dei promemoria.'),
        ];
    }

    private static function send(Consultant $c, array $data): bool
    {
        try {
            app(KycService::class)->request($c, (array) $data['types'], Actor::label(), $data['message'] ?? null, (int) ($data['due_days'] ?? 0));

            return true;
        } catch (OnboardingException $e) {
            Notification::make()->title("{$c->code}: richiesta non inviata")->body($e->getMessage())->danger()->send();

            return false;
        }
    }

    private static function base(): Action
    {
        return Action::make('requestKyc')->authorize('kyc.manage')->label('Richiedi documenti')->icon('heroicon-o-document-arrow-up')->color('gray')
            ->modalHeading('Richiedi i documenti all’incaricato')
            ->modalDescription('L’incaricato riceve una email e vede cosa caricare nella sua area. La richiesta si chiude da sola quando carica tutto.')
            ->schema(self::form());
    }

    /** Azione sulla riga dell'elenco (il record arriva da Filament). */
    public static function forRow(): Action
    {
        return self::base()->iconButton()->tooltip('Richiedi documenti')
            ->visible(fn (Consultant $record): bool => $record->status !== 'terminated')
            ->fillForm(fn (Consultant $record): array => ['types' => app(KycService::class)->missingTypes($record)])
            ->action(function (array $data, Consultant $record): void {
                if (self::send($record, $data)) {
                    Notification::make()->title('Richiesta inviata')->success()->send();
                }
            });
    }

    /** Azione nella scheda dell'incaricato: `$record` restituisce l'incaricato della pagina. */
    public static function forPage(callable $record): Action
    {
        return self::base()
            ->visible(fn (): bool => $record()->status !== 'terminated')
            ->fillForm(fn (): array => ['types' => app(KycService::class)->missingTypes($record())])
            ->action(function (array $data) use ($record): void {
                if (self::send($record(), $data)) {
                    Notification::make()->title('Richiesta inviata')->success()->send();
                }
            });
    }

    public static function bulk(): BulkAction
    {
        return BulkAction::make('requestKycBulk')->authorize('kyc.manage')->label('Richiedi documenti KYC')->icon('heroicon-o-document-arrow-up')->color('gray')
            ->modalDescription('Stessa richiesta a tutti gli incaricati selezionati.')->schema(self::form())
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                $ok = 0;
                foreach ($records as $c) {
                    $ok += self::send($c, $data) ? 1 : 0;
                }
                Notification::make()->title("Richieste inviate: {$ok} su {$records->count()}")->success()->send();
            });
    }
}
