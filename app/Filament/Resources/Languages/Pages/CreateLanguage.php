<?php

declare(strict_types=1);

namespace App\Filament\Resources\Languages\Pages;

use App\Domain\Audit\AuditLog;
use App\Domain\I18n\Locales;
use App\Domain\I18n\TranslationSync;
use App\Filament\Resources\Languages\LanguageResource;
use App\Models\Language;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateLanguage extends CreateRecord
{
    protected static string $resource = LanguageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['is_default'] = false;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Language $language */
        $language = $this->record;
        Locales::forgetLanguages();
        $n = app(TranslationSync::class)->seedLanguage($language->code);
        AuditLog::record('lingue.creata', 'Lingua creata: '.$language->native_name.' ('.$language->code.')', $language, ['testi' => $n]);
        Notification::make()->title('Lingua creata')->body($n.' testi pronti da tradurre nella pagina Traduzioni.')->success()->send();
    }

    protected function getRedirectUrl(): string
    {
        return LanguageResource::getUrl('index');
    }
}
