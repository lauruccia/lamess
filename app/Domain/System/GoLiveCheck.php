<?php

declare(strict_types=1);

namespace App\Domain\System;

use App\Domain\Audit\AuditLog;
use App\Domain\Payouts\SepaExport;
use App\Models\Consultant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * «Controllo messa online»: elenca ciò che va a posto prima di aprire il portale ai clienti veri.
 * Stati: ok · warn (da valutare) · fail (blocca la pubblicazione).
 */
final class GoLiveCheck
{
    /** @return list<array{label: string, status: 'ok'|'warn'|'fail', detail: string}> */
    public function run(): array
    {
        $r = [];
        $add = function (string $label, string $status, string $detail) use (&$r): void {
            $r[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
        };
        $prod = app()->environment('production');

        $open = (bool) config('site.open');
        $add('Sito aperto al pubblico', 'ok', $open ? 'Aperto: i visitatori vedono il sito.' : 'Chiuso: i visitatori vedono «Lamèss apre presto». Si apre da Impostazioni di sistema → Sito quando i controlli qui sotto sono a posto.');
        $add('Ambiente di produzione', $prod ? 'ok' : 'warn', $prod ? 'APP_ENV=production' : 'APP_ENV è «'.app()->environment().'»: in linea va impostato su production.');
        $add('Modalità debug spenta', config('app.debug') ? 'fail' : 'ok', config('app.debug') ? 'APP_DEBUG=true mostrerebbe errori interni ai visitatori: va messo a false.' : 'Spenta.');
        $https = str_starts_with((string) config('app.url'), 'https://');
        $add('Indirizzo sicuro (https)', $https ? 'ok' : ($prod ? 'fail' : 'warn'), $https ? (string) config('app.url') : 'APP_URL non inizia con https://: sessioni e password non sarebbero protette.');
        $add('Chiave dell\'applicazione', config('app.key') ? 'ok' : 'fail', config('app.key') ? 'Impostata (non cambiarla più).' : 'APP_KEY mancante.');
        $driver = DB::connection()->getDriverName();
        $add('Database', in_array($driver, ['mysql', 'mariadb'], true) ? 'ok' : ($prod ? 'warn' : 'ok'), "Tipo: {$driver}".(in_array($driver, ['mysql', 'mariadb'], true) ? '' : ' (in linea si usa MySQL/MariaDB).'));
        $add('Accesso admin con 2FA obbligatoria', config('lamess.admin_2fa_required') ? 'ok' : 'fail', config('lamess.admin_2fa_required') ? 'Attiva.' : 'ADMIN_2FA_REQUIRED=false: chi entra nel back-office deve avere la verifica in due passaggi.');
        $secure = config('session.secure') === true;
        $add('Cookie di sessione solo HTTPS', $secure ? 'ok' : ($prod ? 'fail' : 'warn'), $secure ? 'Attivo.' : 'SESSION_SECURE_COOKIE non è attivo: aggiungi SESSION_SECURE_COOKIE=true nel file .env.');
        $add('Token di installazione rimosso', config('lamess.install_token') ? 'fail' : 'ok', config('lamess.install_token') ? 'Cancella la riga LAMESS_INSTALL_TOKEN dal file .env.' : 'Rimosso.');

        $beat = Cache::get('lamess.scheduler.heartbeat');
        $min = $beat ? CarbonImmutable::parse($beat)->diffInMinutes(now(), true) : null;
        $add('Cron del server attivo', $min !== null && $min <= 20 ? 'ok' : 'fail', $min === null ? 'Mai partito: imposta schedule:run ogni minuto in cPanel.' : ($min <= 20 ? 'Ultimo giro '.(int) $min.' minuti fa.' : 'Fermo da '.(int) $min.' minuti.'));

        $b = app(BackupService::class)->latestAgeHours();
        $add('Backup del database recente', $b !== null && $b <= 26 ? 'ok' : 'warn', $b === null ? 'Nessun backup ancora: crealo da Sistema → Backup.' : ($b <= 26 ? 'Ultimo backup '.(int) $b.' ore fa.' : 'Ultimo backup di '.(int) $b.' ore fa.'));

        $a = AuditLog::verify();
        $add('Catena del log integra', $a->ok ? 'ok' : 'fail', $a->ok ? "{$a->checked} righe controllate." : (string) $a->problem);

        $add('Log di audit protetto nel database', $a->dbProtected ? 'ok' : 'warn', $a->dbProtected
            ? 'Il database vieta modifiche e cancellazioni sulla tabella del log.'
            : 'Mancano i blocchi del database sul log: la catena di hash scopre le manomissioni ma non le impedisce. Se l\'hosting lo consente, assegna il privilegio TRIGGER all\'utente del database e riesegui la migrazione; intanto annota l\'«ancora» da Sistema → Verifica log.');

        foreach (['payment_driver' => 'Pagamento con carta', 'signature_driver' => 'Firma elettronica'] as $key => $label) {
            $fake = config("lamess.{$key}") === 'fake';
            $add($label.' reale', $fake ? 'fail' : 'ok', $fake ? 'Usa ancora il sistema di prova («fake»): non si può aprire ai clienti veri.' : 'Driver «'.config("lamess.{$key}").'».');
        }
        if (config('lamess.signature_driver') === 'email') {
            $mailOn = (bool) config('lamess.mail_enabled') && ! in_array((string) config('mail.default'), ['log', 'array'], true);
            $add('Firma con codice per email: invio attivo', $mailOn ? 'ok' : 'fail', $mailOn
                ? 'Il codice parte dall\'indirizzo configurato in «Posta in uscita». Prova una firma vera con un indirizzo tuo prima di aprire.'
                : 'La firma usa il codice per email ma l\'invio email è spento (o su «log»): nessun cliente riceverebbe il codice. Attiva «Invio email attivo» e configura l\'SMTP.');
        }
        if (config('lamess.payment_driver') === 'stripe') {
            $key = (string) config('services.stripe.secret');
            $hook = (string) config('services.stripe.webhook_secret');
            $add('Stripe: chiavi e webhook', $key !== '' && $hook !== '' ? (str_starts_with($key, 'sk_test_') ? 'warn' : 'ok') : 'fail', match (true) {
                $key === '' => 'STRIPE_SECRET mancante.',
                $hook === '' => 'STRIPE_WEBHOOK_SECRET mancante: i pagamenti non verrebbero mai confermati.',
                str_starts_with($key, 'sk_test_') => 'Chiave di PROVA (sk_test_): in linea serve quella live.',
                default => 'Impostati. Il webhook deve puntare a '.url('/webhooks/stripe').'.',
            });
        }
        $kyc = (string) config('lamess.kyc_driver');
        $add('Verifica identità online', $kyc === 'manual' ? 'warn' : (filled(config('services.stripe.secret')) ? 'ok' : 'fail'), $kyc === 'manual'
            ? 'Spenta: i documenti li controlla il Consorzio a mano (va bene, ma è più lento).'
            : (filled(config('services.stripe.secret')) ? "Driver «{$kyc}»." : 'Driver attivo ma STRIPE_SECRET mancante.'));

        $sinkName = (string) config('lamess.invoice_sink_driver');
        $add('Invio delle fatture al gestionale/SDI', match ($sinkName) {
            'fake' => 'warn', 'file' => 'warn', default => filled(config('lamess.sdi.mail_to')) ? 'ok' : 'fail',
        }, match ($sinkName) {
            'fake' => 'Le fatture sono generate ma non trasmesse: decidere come inviarle.',
            'file' => 'L\'XML viene scritto in storage/app/private/sdi-out ma qualcuno deve ritirarlo e caricarlo sullo SdI.',
            'mail' => filled(config('lamess.sdi.mail_to')) ? 'XML spedito a '.config('lamess.sdi.mail_to').' (serve «Invio email attivo»).' : 'LAMESS_SDI_MAIL_TO mancante.',
            default => "Driver «{$sinkName}».",
        });
        if ($sinkName !== 'fake') {
            $c = (array) config('lamess.seller');
            $missing = array_filter([
                'partita IVA (11 cifre)' => strlen(preg_replace('/\D/', '', (string) ($c['vat_number'] ?? '')) ?? '') === 11,
                'CAP' => strlen(preg_replace('/\D/', '', (string) ($c['zip'] ?? '')) ?? '') === 5,
                'comune' => filled($c['city'] ?? null), 'provincia' => strlen(trim((string) ($c['province'] ?? ''))) === 2,
            ], fn ($ok) => ! $ok);
            $add('Dati del venditore per la fattura elettronica', $missing === [] ? 'ok' : 'fail', $missing === [] ? 'Completi.' : 'Mancano: '.implode(', ', array_keys($missing)).' (Impostazioni → Venditore).');
        }

        $name = (string) config('lamess.seller.name');
        $sellerOk = $name !== '' && ! str_contains($name, 'da configurare') && filled(config('lamess.seller.vat_number')) && filled(config('lamess.seller.address'));
        $add('Dati del venditore completi', $sellerOk ? 'ok' : 'fail', $sellerOk ? $name : 'Mancano denominazione, partita IVA o indirizzo (Impostazioni → Venditore).');
        $sepa = filled(config('lamess.sepa.debtor_name')) && SepaExport::validIban(SepaExport::normalizeIban((string) config('lamess.sepa.debtor_iban')));
        $add('Conto per i bonifici (SEPA)', $sepa ? 'ok' : 'warn', $sepa ? 'Impostato.' : 'Intestatario e IBAN non impostati (Impostazioni → Bonifici SEPA).');

        $fiscal = (bool) config('lamess.fiscal.receipts_enabled') && (bool) config('lamess.fiscal.withholding_enabled');
        $add('Ricevute e trattenute fiscali', $fiscal ? 'ok' : 'warn', $fiscal
            ? 'Ricevute e ritenuta attive: i parametri vanno comunque confermati col commercialista.'
            : 'Ricevute o ritenuta spente: i bonifici sono pari al lordo. Far validare i parametri col commercialista (Impostazioni → Ricevute e trattenute) e accenderli.');

        $mail = (bool) config('lamess.mail_enabled');
        $mailer = (string) config('mail.default');
        $add('Posta in uscita', $mail && ! in_array($mailer, ['log', 'array'], true) ? 'ok' : 'warn', $mail ? "Attiva con «{$mailer}»." : 'Invio email SPENTO: nessuna notifica parte (voluto finché non si è pronti).');

        $noIban = Consultant::query()->where('status', 'active')->where(fn ($q) => $q->whereNull('iban')->orWhere('iban', ''))->count();
        $add('IBAN degli incaricati', $noIban === 0 ? 'ok' : 'warn', $noIban === 0 ? 'Tutti gli incaricati attivi hanno l\'IBAN.' : "{$noIban} incaricati attivi senza IBAN: non saranno pagabili finché non lo inseriscono.");

        $test = User::query()->where(fn ($q) => $q->where('email', 'like', '%.test')->orWhere('email', 'like', '%@example.%'))->count();
        $add('Utenti di prova', $test === 0 ? 'ok' : 'warn', $test === 0 ? 'Nessuno.' : "{$test} utenti con email di prova (.test / example): da rimuovere prima di aprire.");

        $w = is_writable(storage_path()) && is_writable(base_path('bootstrap/cache'));
        $add('Cartelle scrivibili', $w ? 'ok' : 'fail', $w ? 'storage e bootstrap/cache scrivibili.' : 'storage/ o bootstrap/cache non sono scrivibili.');

        return $r;
    }

    /** @param list<array{status: string}> $checks */
    public static function summary(array $checks): string
    {
        $fail = count(array_filter($checks, fn ($c) => $c['status'] === 'fail'));
        $warn = count(array_filter($checks, fn ($c) => $c['status'] === 'warn'));

        return $fail > 0 ? "NON PRONTO: {$fail} controlli bloccanti, {$warn} da valutare" : ($warn > 0 ? "Quasi pronto: {$warn} controlli da valutare" : 'Tutto a posto');
    }
}
