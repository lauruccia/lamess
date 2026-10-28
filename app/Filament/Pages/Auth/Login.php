<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use SensitiveParameter;

/**
 * Login del back-office che accetta anche l'indirizzo con la «è» (nome@lamèss.it).
 *
 * Il campo standard di Filament è <input type="email">: Safari/iPhone rifiuta le lettere accentate
 * nel dominio ("Inserisci un indirizzo email") e blocca l'invio. Qui il campo è testuale (tastiera
 * email comunque attiva) e il dominio viene convertito in Punycode (xn--lamss-6ra.it) prima del controllo.
 */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/login.form.email.label'))
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes([
                'inputmode' => 'email',
                'autocapitalize' => 'none',
                'autocorrect' => 'off',
                'spellcheck' => 'false',
            ]);
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $credentials = parent::getCredentialsFromFormData($data);
        $credentials['email'] = self::toAscii((string) $credentials['email']);

        return $credentials;
    }

    private static function toAscii(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $domain = mb_strtolower(substr($email, $at + 1));

        if (preg_match('/[^\x20-\x7e]/', $domain) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            $domain = $ascii !== false ? $ascii : str_replace('lamèss.it', 'xn--lamss-6ra.it', $domain);
        }

        return $local.'@'.$domain;
    }
}
