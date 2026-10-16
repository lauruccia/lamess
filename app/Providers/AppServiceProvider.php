<?php

namespace App\Providers;

use App\Domain\Access\Roles;
use App\Domain\Billing\FakeInvoiceSink;
use App\Domain\Billing\FatturaPaBuilder;
use App\Domain\Billing\FileInvoiceSink;
use App\Domain\Billing\InvoiceSink;
use App\Domain\Billing\MailInvoiceSink;
use App\Domain\Onboarding\EmailSignatureProvider;
use App\Domain\Onboarding\FakeSignatureProvider;
use App\Domain\Onboarding\IdentityVerifier;
use App\Domain\Onboarding\NoIdentityVerifier;
use App\Domain\Onboarding\SignatureProvider;
use App\Domain\Onboarding\StripeIdentityVerifier;
use App\Domain\Orders\DisabledKMoneyProvider;
use App\Domain\Orders\FakeKMoneyProvider;
use App\Domain\Orders\FakePaymentProvider;
use App\Domain\Orders\KMoneyApiProvider;
use App\Domain\Orders\KMoneyProvider;
use App\Domain\Orders\PaymentProvider;
use App\Domain\Orders\StripeCheckoutProvider;
use App\Domain\Settings\SystemSettings;
use App\Filament\Support\ConsultantList;
use App\Listeners\AuditSecurityEvents;
use App\Support\StatusLabels;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Una sola istanza per richiesta: piano in vigore, periodo e qualifiche si leggono una volta, non per ogni riga dell'elenco.
        $this->app->scoped(ConsultantList::class);

        $this->app->bind(SignatureProvider::class, fn () => match (config('lamess.signature_driver')) {
            'fake' => new FakeSignatureProvider,
            'email' => new EmailSignatureProvider,
            default => throw new \RuntimeException('Driver di firma non configurato: '.config('lamess.signature_driver')),
        });
        $this->app->bind(PaymentProvider::class, fn () => match (config('lamess.payment_driver')) {
            'fake' => new FakePaymentProvider,
            'stripe' => new StripeCheckoutProvider((string) config('services.stripe.secret')),
            default => throw new \RuntimeException('Driver di pagamento non configurato: '.config('lamess.payment_driver')),
        });
        $this->app->bind(KMoneyProvider::class, fn () => match (config('lamess.kmoney_driver')) {
            'fake' => new FakeKMoneyProvider,
            'api' => new KMoneyApiProvider,
            default => new DisabledKMoneyProvider,
        });
        $this->app->bind(IdentityVerifier::class, fn () => match (config('lamess.kyc_driver')) {
            'manual' => new NoIdentityVerifier,
            'stripe_identity' => new StripeIdentityVerifier((string) config('services.stripe.secret')),
            default => throw new \RuntimeException('Driver di verifica identità non configurato: '.config('lamess.kyc_driver')),
        });
        $this->app->bind(InvoiceSink::class, fn () => match (config('lamess.invoice_sink_driver')) {
            'fake' => new FakeInvoiceSink,
            'file' => new FileInvoiceSink(new FatturaPaBuilder),
            'mail' => new MailInvoiceSink(new FatturaPaBuilder),
            default => throw new \RuntimeException('Driver fatture non configurato: '.config('lamess.invoice_sink_driver')),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Impostazioni di sistema salvate dal pannello: sovrascrivono .env/config PRIMA di ogni altra lettura (anche del blocco email)
        SystemSettings::applyToConfig();

        // Codici di stato in inglese → etichette italiane in tabelle e schede del back-office (chi imposta un formato proprio lo mantiene)
        $translate = function ($column): void {
            if (in_array($column->getName(), StatusLabels::COLUMNS, true)) {
                $column->formatStateUsing(fn ($state) => StatusLabels::label($state));
            }
        };
        TextColumn::configureUsing($translate);
        TextEntry::configureUsing($translate);

        // Il super_admin ha ogni permesso (spatie/permission li registra come gate).
        Gate::before(fn ($user) => $user->hasRole(Roles::SUPER_ADMIN) ? true : null);

        // Accessi, blocchi, reset password, ruoli e permessi → log di audit
        Event::subscribe(AuditSecurityEvents::class);

        // Blocco globale delle email (LAMESS_MAIL_ENABLED=false): nessun messaggio esce, né da notifiche né da Mail::.
        // Il mailer viene forzato su "log" e il listener annulla comunque l'invio (ritornare false ferma il Mailer).
        if (! config('lamess.mail_enabled')) {
            config(['mail.default' => 'log']);
            Event::listen(MessageSending::class, function (MessageSending $event): bool {
                Log::warning('[EMAIL BLOCCATA] invio disattivato (lamess.mail_enabled=false)', [
                    'a' => collect($event->message->getTo())->map(fn ($a) => $a->getAddress())->all(),
                    'oggetto' => $event->message->getSubject(),
                ]);

                return false;
            });
        }
    }
}
