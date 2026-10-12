<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Access\Roles;
use App\Domain\Access\SuperAdminCreator;
use App\Domain\Audit\AuditLog;
use App\Models\ShippingRate;
use Database\Seeders\PrelaunchPlanSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Installazione guidata per hosting senza terminale (cPanel). Una sola volta:
 * esiste solo con LAMESS_INSTALL_TOKEN (≥ 24 caratteri), senza file storage/app/installed.lock e senza super_admin.
 * In ogni altro caso risponde 404, come se la pagina non esistesse.
 */
final class InstallController extends Controller
{
    public static function lockPath(): string
    {
        return storage_path('app/installed.lock');
    }

    public function show(): mixed
    {
        $this->guard();

        return view('site.install', ['checks' => $this->requirements(), 'done' => null]);
    }

    public function run(Request $request): mixed
    {
        $this->guard();
        $token = (string) config('lamess.install_token');
        if (! hash_equals($token, (string) $request->input('token'))) {
            return back()->withErrors(['token' => 'Token di installazione non corretto.']);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'confirmed'],
        ]);
        $checks = $this->requirements();
        if (collect($checks)->contains(fn ($c) => ! $c['ok'] && ($c['blocking'] ?? true))) {
            return back()->withErrors(['token' => 'I requisiti del server non sono soddisfatti: vedi l\'elenco.'])->withInput();
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            AuditLog::forget();
            Roles::sync();
            (new PrelaunchPlanSeeder)->run();
            // Spedizione ordine singolo in Italia: 9,00 € IVA inclusa (7,38 netti + 22%). Modificabile in «Tariffe di spedizione».
            ShippingRate::firstOrCreate(['country_code' => 'IT'], ['amount_net' => '7.38', 'vat_rate' => '22.00', 'active' => true]);
            $user = (new SuperAdminCreator)->create($data['name'], $data['email'], $data['password'], false, 'installazione guidata');
            AuditLog::record('system.installed', 'Installazione guidata completata', null, ['php' => PHP_VERSION]);
            @file_put_contents(self::lockPath(), now()->toIso8601String());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput($request->except(['password', 'password_confirmation', 'token']));
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['token' => 'Installazione interrotta: '.$e->getMessage().'. Controlla i dati del database nel file .env e riprova (si può ripetere).'])->withInput($request->except(['password', 'password_confirmation', 'token']));
        }

        return view('site.install', ['checks' => [], 'done' => ['email' => $user->email, 'cron' => $this->cronLine()]]);
    }

    private function guard(): void
    {
        $token = config('lamess.install_token');
        abort_unless(is_string($token) && strlen($token) >= 24, 404);
        abort_if(is_file(self::lockPath()), 404);
        try {
            $exists = (new SuperAdminCreator)->exists();
        } catch (Throwable) {
            $exists = false;   // tabelle non ancora create: installazione ancora da fare
        }
        abort_if($exists, 404);
    }

    /** @return list<array{label: string, ok: bool, detail: string, blocking?: bool}> */
    private function requirements(): array
    {
        $out = [['label' => 'PHP 8.3 o superiore', 'ok' => version_compare(PHP_VERSION, '8.3.0', '>='), 'detail' => 'versione '.PHP_VERSION]];
        foreach (['ctype', 'fileinfo', 'json', 'mbstring', 'openssl', 'pdo', 'tokenizer', 'xml', 'zip', 'gd', 'intl'] as $ext) {
            $out[] = ['label' => "Estensione $ext", 'ok' => extension_loaded($ext), 'detail' => extension_loaded($ext) ? 'presente' : 'mancante: attivala da cPanel → Seleziona versione PHP', 'blocking' => ! in_array($ext, ['gd', 'intl', 'zip'], true)];
        }
        foreach ([storage_path(), storage_path('app'), storage_path('framework'), storage_path('logs'), base_path('bootstrap/cache')] as $dir) {
            $out[] = ['label' => 'Scrivibile: '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $dir), 'ok' => is_writable($dir), 'detail' => is_writable($dir) ? 'ok' : 'impostare i permessi 755/775'];
        }
        try {
            DB::connection()->getPdo();
            $out[] = ['label' => 'Connessione al database', 'ok' => true, 'detail' => DB::connection()->getDriverName()];
        } catch (Throwable $e) {
            $out[] = ['label' => 'Connessione al database', 'ok' => false, 'detail' => 'non riuscita: controlla DB_* nel file .env'];
        }
        $out[] = ['label' => 'APP_KEY impostata', 'ok' => (string) config('app.key') !== '', 'detail' => 'chiave dell\'applicazione'];
        $out[] = ['label' => 'APP_DEBUG spento', 'ok' => ! config('app.debug'), 'detail' => config('app.debug') ? 'in produzione va false' : 'ok', 'blocking' => false];

        return $out;
    }

    private function cronLine(): string
    {
        return '* * * * * /usr/local/bin/php '.base_path('artisan').' schedule:run >> /dev/null 2>&1';
    }
}
