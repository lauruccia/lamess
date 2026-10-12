<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SiteClosed;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // il cookie referral contiene solo un codice pubblico: non serve cifrarlo
        $middleware->encryptCookies(except: ['lm_ref']);
        // utenti già autenticati che aprono /registrazione o /accedi vanno all'area riservata (non a «/», che rimanda a /registrazione: loop)
        $middleware->redirectUsersTo('/area');
        $middleware->append(SecurityHeaders::class);
        // dopo la sessione, così sa chi è collegato: il sito resta chiuso ai visitatori finché non si apre da Impostazioni
        $middleware->web(append: [SiteClosed::class]);
        // e prima del controllo di accesso: anche /area deve mostrare «Lamèss apre presto», non il rinvio al login
        $middleware->appendToPriorityList(after: StartSession::class, append: SiteClosed::class);
        $middleware->validateCsrfTokens(except: ['webhooks/*']); // chiamate server-to-server, autenticate dalla firma
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
