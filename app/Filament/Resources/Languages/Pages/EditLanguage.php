<?php

declare(strict_types=1);

namespace App\Filament\Resources\Languages\Pages;

use App\Domain\Audit\AuditLog;
use App\Domain\I18n\Locales;
use App\Filament\Resources\Languages\LanguageResource;
use Filament\Resources\Pages\EditRecord;

class EditLanguage extends EditRecord
{
    protected static string $resource = LanguageResource::class;

    protected function afterSave(): void
    {
        Locales::forgetLanguages();
        AuditLog::record('lingue.modificata', 'Lingua modificata: '.$this->record->native_name.' ('.$this->record->code.')', $this->record, [
            'stato' => $this->record->status,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return LanguageResource::getUrl('index');
    }
}
