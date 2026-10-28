<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Analytics\Tracker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Statistiche del sito: vedi App\Domain\Analytics\Tracker. Non blocca mai la richiesta, qualunque cosa succeda. */
final class TrackVisit
{
    public function __construct(private readonly Tracker $tracker) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        try {
            if ($this->tracker->shouldTrack($request, $response)) {
                $this->tracker->stamp($request);
            }
        } catch (Throwable $e) {
            Log::debug('Statistiche: contrassegno non riuscito', ['errore' => $e->getMessage()]);
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->tracker->record($request);
        } catch (Throwable $e) {
            Log::debug('Statistiche: registrazione non riuscita', ['errore' => $e->getMessage()]);
        }
    }
}
