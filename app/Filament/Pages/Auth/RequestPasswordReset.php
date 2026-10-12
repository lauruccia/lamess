<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Recupero password del back-office: risponde sempre allo stesso modo, anche se l'email non esiste,
 * così non si può scoprire chi ha un account (nemmeno il super_admin).
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    protected function getFailureNotification(string $status): ?Notification
    {
        if ($status === Password::INVALID_USER) {
            return $this->getSentNotification(Password::RESET_LINK_SENT);
        }

        return parent::getFailureNotification($status);
    }
}
