<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\Actor;
use App\Domain\Onboarding\ConsultantDocuments;
use App\Domain\Onboarding\OnboardingException;
use App\Models\Consultant;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/** «Invia documenti»: l'admin manda per email all'incaricato lettera firmata, certificato, tesserino, estratti conto e ricevute. */
final class SendDocumentsAction
{
    private static function send(Consultant $c, array $keys, ?string $note): ?array
    {
        try {
            return app(ConsultantDocuments::class)->send($c, $keys, Actor::label(), $note);
        } catch (OnboardingException $e) {
            Notification::make()->title("{$c->code}: invio non riuscito")->body($e->getMessage())->danger()->send();

            return null;
        }
    }

    /** Dalla scheda dell'incaricato: si scelgono tra i documenti realmente disponibili per lui. */
    public static function forPage(callable $record): Action
    {
        return Action::make('sendDocuments')->authorize('network.manage')->label('Invia documenti')->icon('heroicon-o-paper-airplane')->color('gray')
            ->modalHeading('Invia documenti all’incaricato')
            ->modalDescription('I documenti vengono allegati a una email all’indirizzo dell’incaricato. L’invio resta nel log di audit.')
            ->schema(fn (): array => [
                CheckboxList::make('documents')->label('Documenti')->required()->columns(1)->options(app(ConsultantDocuments::class)->options($record())),
                Textarea::make('note')->label('Messaggio (facoltativo)')->rows(2),
            ])
            ->action(function (array $data) use ($record): void {
                $r = self::send($record(), (array) $data['documents'], $data['note'] ?? null);
                if ($r !== null) {
                    Notification::make()->title('Documenti inviati')->body(implode(', ', $r['sent']).($r['skipped'] ? ' — non disponibili: '.implode(', ', $r['skipped']) : ''))->success()->send();
                }
            });
    }

    /** Dall'elenco: solo i documenti «generali»; chi non ne ha qualcuno lo salta (viene segnalato). */
    public static function bulk(): BulkAction
    {
        return BulkAction::make('sendDocumentsBulk')->authorize('network.manage')->label('Invia documenti')->icon('heroicon-o-paper-airplane')->color('gray')
            ->modalDescription('Per ogni incaricato selezionato vengono allegati i documenti scelti, se disponibili per lui.')
            ->schema([
                CheckboxList::make('documents')->label('Documenti')->required()->columns(1)
                    ->options(['letter' => 'Lettera di incarico firmata', 'certificate' => 'Certificato di completamento della firma', 'badge' => 'Tesserino di riconoscimento']),
                Textarea::make('note')->label('Messaggio (facoltativo)')->rows(2),
            ])->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                $ok = 0;
                foreach ($records as $c) {
                    $ok += self::send($c, (array) $data['documents'], $data['note'] ?? null) !== null ? 1 : 0;
                }
                Notification::make()->title("Invii riusciti: {$ok} su {$records->count()}")->success()->send();
            });
    }
}
