<?php

declare(strict_types=1);

namespace App\Support\Errors;

use App\Domain\Access\Roles;
use App\Models\Order;
use App\Support\StatusLabels;
use Dompdf\Exception;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\ViewException;
use League\Flysystem\FilesystemException;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Al posto della pagina generica «500»: riconosce il tipo di problema (database, aggiornamento non completato, email,
 * pagamento, spazio sul server, errore del codice…) e dice a chi sta usando il sito che cosa è successo, che cosa ne è
 * stato del suo ordine e che cosa fare. Ogni errore ha un codice di riferimento, scritto anche nel log.
 *
 * Non deve mai peggiorare le cose: se la spiegazione stessa fallisce, resta la risposta originale.
 */
final class ErrorExplainer
{
    private const ATTRIBUTE = 'lm_error_ref';

    /** Codice di riferimento dell'errore per questa richiesta: lo stesso sulla pagina e nel log (contesto «rif»). */
    public static function reference(Request $r): string
    {
        $ref = $r->attributes->get(self::ATTRIBUTE);
        if (! is_string($ref)) {
            $ref = 'LM-'.date('ymd-Hi').'-'.strtoupper(Str::random(4));
            $r->attributes->set(self::ATTRIBUTE, $ref);
        }

        return $ref;
    }

    /** Sostituisce la risposta 5xx con la pagina che spiega l'errore. Tutto il resto (404, 419, validazione, JSON, debug) resta com'è. */
    public static function respond(Response $response, Throwable $e, Request $r): Response
    {
        if ($response->getStatusCode() < 500 || $e instanceof HttpExceptionInterface || config('app.debug')) {
            return $response;
        }
        try {
            $ref = self::reference($r);
            $problem = self::classify($e);
            // Chiamate dei provider (Stripe, KMoney): niente pagina, una riga leggibile nel loro pannello.
            if ($r->is('webhooks/*')) {
                return response("Errore interno ({$problem['kind']}), rif. {$ref}", $response->getStatusCode(), ['Content-Type' => 'text/plain; charset=UTF-8']);
            }
            if ($r->is('api/*') || $r->expectsJson()) {
                return $response;
            }
            $staff = self::staffLevel($r);

            return response()->view('errors.problema', [
                'status' => $response->getStatusCode(),
                'ref' => $ref,
                'problem' => $problem,
                'context' => self::context($r, $problem['kind'], $staff > 0),
                'staff' => $staff > 0,
                'technical' => $staff > 1 ? self::technical($e) : null,
                'when' => now()->setTimezone('Europe/Rome')->format('d/m/Y H:i'),
            ], $response->getStatusCode());
        } catch (Throwable) {
            return $response;
        }
    }

    /**
     * Che problema è.
     *
     * @return array{kind:string,title:string,what:string,retry:string,staff:string}
     */
    public static function classify(Throwable $e): array
    {
        $chain = [];
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            $chain[] = $x;
        }
        $text = implode(' | ', array_map(fn (Throwable $x) => $x->getMessage(), $chain));
        $find = function (string ...$classes) use ($chain): ?Throwable {
            foreach ($chain as $x) {
                foreach ($classes as $c) {
                    if ($x instanceof $c) {
                        return $x;
                    }
                }
            }

            return null;
        };

        // Limiti del server (memoria, tempo): valgono qualunque cosa stesse facendo la pagina.
        if (str_contains($text, 'Allowed memory size')) {
            return self::problem('limits_memory', 'L\'operazione è troppo pesante per il server',
                'La pagina ha chiesto più memoria di quella che il server concede e si è fermata a metà.',
                'Riprova con meno dati alla volta (un periodo più corto, meno righe, un filtro). Se succede su una pagina normale, segnalacelo.',
                'Superato il limite di memoria di PHP (memory_limit). Si alza da cPanel → «Select PHP Version» → Options, oppure si riduce l\'elenco/esportazione richiesto.');
        }
        if (str_contains($text, 'Maximum execution time')) {
            return self::problem('limits_time', 'L\'operazione ha impiegato troppo tempo',
                'Il server ha interrotto la pagina perché stava impiegando più del tempo massimo consentito.',
                'Riprova tra qualche minuto. Se stavi esportando o calcolando molti dati, prova con un periodo più corto.',
                'Superato max_execution_time di PHP. Controlla se un servizio esterno era lento (pagamenti, email) o se l\'elenco è molto grande; il limite si alza da cPanel → «Select PHP Version» → Options.');
        }
        if (preg_match('/No space left on device|Disk quota exceeded|disk full|errno=(28|122)/i', $text)) {
            return self::problem('storage_full', 'Lo spazio sul server è esaurito',
                'Il server non ha più spazio per salvare dati, quindi non è riuscito a completare l\'operazione.',
                'Non dipende da te e riprovare subito non serve: stiamo liberando spazio. Riprova più tardi.',
                'Spazio disco o quota cPanel esauriti. Controlla lo spazio in cPanel e libera storage/logs (log vecchi), storage/framework/cache e i backup non più utili.');
        }

        if (($q = $find(QueryException::class, \PDOException::class)) !== null) {
            return self::database($q, $text);
        }

        if ($find(TransportExceptionInterface::class) !== null) {
            return self::problem('mail', 'Non siamo riusciti a inviare un\'email',
                'L\'operazione prevedeva l\'invio di un\'email e il server di posta non l\'ha accettata. Quello che stavi facendo è stato con ogni probabilità registrato lo stesso: è l\'email a non essere partita.',
                'Non ripetere l\'operazione prima di aver controllato qui sopra se è già stata registrata.',
                'Il server SMTP ha rifiutato o non ha risposto. Controlla in cPanel la casella mittente (password, quota, limite orario di invii) e i dati MAIL_* nel file .env. L\'operazione che ha fatto partire l\'email di norma è già salvata: verifica lo stato prima di ripeterla.');
        }

        if (($h = $find(ConnectionException::class, RequestException::class, TransferException::class)) !== null) {
            $service = match (true) {
                str_contains($text, 'stripe.com') => 'il servizio dei pagamenti con carta (Stripe)',
                (bool) preg_match('/kmoney/i', $text) => 'KMoney',
                (bool) preg_match('/poste/i', $text) => 'il servizio di spedizione (Poste)',
                default => 'un servizio esterno collegato al sito',
            };

            return self::problem('external', 'Un servizio collegato non ha risposto',
                "Per completare l'operazione il sito doveva parlare con {$service}, che in questo momento non risponde o ha risposto con un errore.",
                'Di solito dura pochi minuti. Riprova tra poco, dopo aver controllato qui sopra a che punto è rimasta l\'operazione.',
                "Chiamata verso {$service} fallita (timeout o errore di rete). Se si ripete: controlla lo stato del servizio e le chiavi/indirizzi nel file .env.");
        }

        if ($find(DecryptException::class, MissingAppKeyException::class) !== null) {
            return self::problem('config_key', 'La configurazione del sito non è corretta',
                'Il sito non riesce a leggere alcuni dati protetti perché la sua chiave di sicurezza non è quella giusta.',
                'Non dipende da te e riprovare non serve finché non sistemiamo la configurazione.',
                'APP_KEY mancante o diversa da quella con cui sono stati cifrati i dati (sessioni, collegamenti KMoney). Va ripristinata nel file .env la chiave originale; dopo, svuota bootstrap/cache/config.php se presente.');
        }

        if (preg_match('/Permission denied|failed to open stream|could not be opened in append mode|Unable to (write|create|read|copy|move|delete)|is not writable/i', $text)
            || $find(FilesystemException::class, FileNotFoundException::class) !== null) {
            return self::problem('storage', 'Il server non riesce a leggere o salvare un file',
                'Per completare l\'operazione serviva leggere o scrivere un file (un documento, un\'immagine, un PDF) e il server non c\'è riuscito.',
                'Riprova tra qualche minuto. Se stavi caricando un file, prova con un file più piccolo o in un altro formato.',
                'File mancante o cartella non scrivibile. Controlla da File Manager che storage/ (app, framework/cache, framework/sessions, framework/views, logs) e bootstrap/cache esistano con permessi 755/775 e che il file richiesto ci sia.');
        }

        if ($find(Exception::class) !== null) {
            return self::problem('pdf', 'Non siamo riusciti a creare il documento PDF',
                'I dati ci sono, ma la creazione del PDF (fattura, etichetta, statino…) non è andata a buon fine.',
                'Riprova tra poco: il documento si può generare di nuovo, i dati non sono persi.',
                'Errore di Dompdf durante la creazione del PDF: di solito un\'immagine mancante o un dato inatteso nel documento. Vedi i dettagli nel log.');
        }

        if (preg_match('/Class "[^"]+" not found|Target class \[[^\]]+\] does not exist|View \[[^\]]+\] not found|Failed opening required|Unable to locate/i', $text)) {
            return self::problem('deploy', 'L\'aggiornamento del sito non è completo',
                'Il sito è stato aggiornato da poco e una sua parte non è ancora al suo posto.',
                'Riprova tra qualche minuto: nel frattempo completiamo l\'aggiornamento.',
                'Manca un file o una classe: l\'aggiornamento è arrivato a metà. Rifai «Update from Remote» e «Deploy HEAD Commit» su cPanel; se resta, cancella da File Manager i file in bootstrap/cache (tranne .gitignore) e in storage/framework/views.');
        }

        if ($e instanceof FatalError || $find(\Error::class, \ErrorException::class, ViewException::class) !== null) {
            return self::problem('code', 'Questa pagina ha un errore nostro',
                'C\'è un errore nel programma del sito in questo punto preciso: con i dati di questa operazione la pagina non riesce a completarsi. Il resto del sito funziona.',
                'Riprovare allo stesso modo darà lo stesso risultato. Segnalaci il codice qui sotto: ci dice esattamente dove intervenire.',
                'Errore di programmazione (dato mancante o inatteso in questa pagina). Non è un problema del server: va corretto nel codice. Manda il codice di riferimento a chi sviluppa il portale.');
        }

        return self::problem('unknown', 'Qualcosa è andato storto',
            'Il sito ha incontrato un problema che non rientra tra quelli che sa riconoscere.',
            'Riprova tra qualche minuto, dopo aver controllato qui sopra a che punto è rimasta l\'operazione.',
            'Errore non classificato: i dettagli sono nel log, cercando il codice di riferimento.');
    }

    /** @return array{kind:string,title:string,what:string,retry:string,staff:string} */
    private static function database(Throwable $q, string $text): array
    {
        $driver = $q instanceof \PDOException ? (int) ($q->errorInfo[1] ?? 0) : 0;
        if ($q->getPrevious() instanceof \PDOException) {
            $driver = (int) ($q->getPrevious()->errorInfo[1] ?? $driver);
        }
        $is = fn (array $codes, string $pattern): bool => in_array($driver, $codes, true) || preg_match($pattern, $text) === 1;

        if ($is([1146, 1054], '/SQLSTATE\[42S(02|22)\]|no such table|no such column|has no column named|Base table or view not found|Unknown column/i')) {
            preg_match('/(?:Table \'[^.\']+\.([^\']+)\'|no such table: (\S+)|Unknown column \'([^\']+)\'|no such column: (\S+)|has no column named (\S+))/i', $text, $m);
            $name = trim((string) (array_values(array_filter(array_slice($m, 1)))[0] ?? ''), '"`');

            return self::problem('db_schema', 'Il sito è stato aggiornato, ma l\'aggiornamento non è completo',
                'È stata pubblicata una versione nuova del sito e questa pagina ha bisogno di una parte del database che non è ancora stata preparata.',
                'Non dipende da te e riprovare subito non serve: la pagina tornerà a funzionare appena completiamo l\'aggiornamento.',
                'Al database manca '.($name !== '' ? "«{$name}»" : 'una tabella o una colonna').': dopo l\'ultimo deploy non è stato lanciato l\'SQL in phpMyAdmin. Lo trovi nell\'ultima voce di docs/registro-aggiornamenti.md (il deploy non esegue le migrazioni).');
        }
        if ($is([1040, 1041, 1044, 1045, 1049, 1203, 1226, 2002, 2003, 2006, 2013], '/SQLSTATE\[(08\w{3}|HY000)\] \[\d+\]|Connection refused|server has gone away|Too many connections|max_user_connections|max_connections|Access denied for user|Unknown database|unable to open database|Database file at path/i')) {
            return self::problem('db_down', 'Il database non risponde',
                'In questo momento il sito non riesce a collegarsi al proprio database (troppi accessi insieme oppure un fermo temporaneo del server).',
                'Riprova tra un minuto. Finché il database non risponde non viene salvato nulla.',
                'Connessione al database fallita. Se compare «Too many connections / max_user_connections» è il limite dell\'hosting: passa da solo in pochi minuti. Se compare «Access denied» o «Unknown database» controlla DB_DATABASE, DB_USERNAME e DB_PASSWORD nel file .env e l\'utente in cPanel → MySQL Databases.');
        }
        if ($is([1205, 1213], '/SQLSTATE\[40001\]|Deadlock|Lock wait timeout|database is locked/i')) {
            return self::problem('db_busy', 'Due operazioni sugli stessi dati nello stesso momento',
                'Un\'altra operazione stava lavorando sugli stessi dati (lo stesso ordine, lo stesso prodotto o lo stesso sconto) e il database ha fermato la tua per non creare pasticci. La tua operazione è stata annullata per intero.',
                'Aspetta qualche secondo e riprova: di norma va a buon fine al secondo tentativo.',
                'Blocco tra due operazioni contemporanee (deadlock o attesa scaduta). È raro e di solito innocuo: l\'operazione è stata annullata per intero e si può ripetere. Se capita spesso alla stessa ora, coincide con un\'operazione automatica (ricalcolo notturno, addebiti degli abbonamenti).');
        }
        if ($is([1062], '/Duplicate entry|UNIQUE constraint failed/i')) {
            return self::problem('db_duplicate', 'Questo dato risulta già registrato',
                'L\'operazione voleva salvare qualcosa che esiste già (succede, per esempio, premendo due volte lo stesso pulsante o inviando due volte lo stesso modulo). La seconda registrazione è stata fermata.',
                'Prima di riprovare controlla qui sopra: molto probabilmente la prima volta è andata a buon fine.',
                'Valore duplicato su un campo che deve essere unico (numero documento, email, codice…). Se non è un doppio clic, nei dettagli del log c\'è il campo coinvolto.');
        }
        if ($is([1406, 1264, 1265, 1292, 1366], '/SQLSTATE\[22\d{3}\]|Data too long|Out of range|Incorrect \w+ value|Data truncated/i')) {
            preg_match('/for column \'?([^\' ]+)\'?/i', $text, $m);

            return self::problem('db_data', 'Un dato inserito non è accettato',
                'Uno dei valori scritti è troppo lungo oppure in un formato che il sito non riesce a salvare (per esempio un testo molto lungo in un campo breve, o caratteri particolari).',
                'Torna indietro, accorcia o correggi i dati inseriti e riprova. Non è stato salvato nulla.',
                'Il database ha rifiutato un valore'.(isset($m[1]) ? " per il campo «{$m[1]}»" : '').' (troppo lungo o formato errato). Manca un controllo sul modulo: segnala il codice a chi sviluppa il portale.');
        }
        if ($is([1048, 1364, 1451, 1452], '/SQLSTATE\[23000\]|Integrity constraint|NOT NULL constraint|FOREIGN KEY constraint|cannot be null|doesn\'t have a default value/i')) {
            return self::problem('db_constraint', 'Manca un dato necessario, oppure il dato è collegato ad altro',
                'L\'operazione non si può salvare così: manca un\'informazione obbligatoria, oppure si sta toccando qualcosa a cui sono collegati altri dati (per esempio un cliente con ordini).',
                'Non è stato salvato nulla. Controlla di aver compilato tutto e riprova; se si ripete segnalaci il codice qui sotto.',
                'Vincolo del database violato (campo obbligatorio vuoto o record collegato ad altri). Se stavi eliminando qualcosa, ha dati collegati: va disattivato, non eliminato.');
        }

        return self::problem('db_other', 'Il database ha rifiutato l\'operazione',
            'Il database non ha accettato questa operazione, che è stata annullata.',
            'Riprova tra qualche minuto; se si ripete segnalaci il codice qui sotto.',
            'Errore del database non classificato: il messaggio completo è nel log, cercando il codice di riferimento.');
    }

    /** @return array{kind:string,title:string,what:string,retry:string,staff:string} */
    private static function problem(string $kind, string $title, string $what, string $retry, string $staff): array
    {
        return compact('kind', 'title', 'what', 'retry', 'staff');
    }

    /**
     * Che cosa stava facendo la persona e che cosa ne è stato: per ordini e pagamenti si guarda il database, non si tira a indovinare.
     *
     * @return array{doing:string,effect:string,links:array<string,string>}
     */
    public static function context(Request $r, string $kind, bool $staff): array
    {
        $path = trim($r->path(), '/');
        $write = ! $r->isMethodSafe();
        $dbOk = $kind !== 'db_down';
        // Le azioni del back-office passano da Livewire: la pagina vera è quella da cui parte la richiesta.
        if (str_starts_with($path, 'livewire')) {
            $path = trim((string) parse_url((string) $r->headers->get('referer'), PHP_URL_PATH), '/');
            $write = true;
        }
        $user = null;
        try {
            $user = $dbOk ? $r->user() : null;
        } catch (Throwable) {
        }

        if (str_starts_with($path, 'admin')) {
            return self::adminContext($path, $write, $dbOk);
        }

        if ($path === 'area/checkout' && $write) {
            $order = $user === null ? null : self::try(fn () => Order::where('buyer_user_id', $user->id)
                ->where('created_at', '>=', now()->subSeconds((int) ceil(microtime(true) - self::startedAt($r)) + 2))->latest('id')->first());
            if ($order instanceof Order) {
                return ['doing' => 'Stavi confermando un ordine.',
                    'effect' => "L'ordine {$order->number} è stato registrato e risulta: «".self::status($order).'». Non rifarlo: lo ritrovi tra i tuoi ordini e, se serve, completi il pagamento da lì.',
                    'links' => ["Apri l'ordine {$order->number}" => "/area/ordini/{$order->id}", 'I miei ordini' => '/area/ordini']];
            }
            if ($order === null && $user !== null) {
                return ['doing' => 'Stavi confermando un ordine.',
                    'effect' => 'L\'ordine non è stato registrato e non è partito alcun pagamento. Il carrello è ancora com\'era: puoi riprovare.',
                    'links' => ['Torna al carrello' => '/carrello', 'I miei ordini' => '/area/ordini']];
            }

            return ['doing' => 'Stavi confermando un ordine.',
                'effect' => 'In questo momento non riusciamo a verificare se l\'ordine è stato registrato. Prima di riprovare apri «I miei ordini»: se l\'ordine c\'è, non rifarlo.',
                'links' => ['I miei ordini' => '/area/ordini', 'Torna al carrello' => '/carrello']];
        }

        if (preg_match('#^area/ordini/(\d+)#', $path, $m)) {
            $order = $user === null ? null : self::try(fn () => Order::where('buyer_user_id', $user->id)->find((int) $m[1]));
            $doing = match (true) {
                str_ends_with($path, '/fattura') => 'Stavi scaricando la fattura di un ordine.',
                str_ends_with($path, '/riprova'), str_ends_with($path, '/resto') => 'Stavi pagando un ordine.',
                str_ends_with($path, '/annulla') => 'Stavi annullando un ordine.',
                str_ends_with($path, '/reso') => 'Stavi chiedendo un reso.',
                default => 'Stavi aprendo un ordine.',
            };

            return ['doing' => $doing,
                'effect' => $order instanceof Order
                    ? "L'ordine {$order->number} è al sicuro e in questo momento risulta: «".self::status($order).'».'.($write ? ' Se lo stato non è quello che ti aspettavi, l\'operazione non è andata a buon fine e puoi ripeterla.' : '')
                    : 'I tuoi ordini non sono stati toccati: li ritrovi nell\'elenco.',
                'links' => ['I miei ordini' => '/area/ordini']];
        }

        if (str_starts_with($path, 'area/checkout') || str_starts_with($path, 'carrello')) {
            return ['doing' => 'Stavi preparando un ordine.', 'effect' => 'Non è stato creato nessun ordine e non è partito alcun pagamento. Il carrello non va perso.', 'links' => ['Torna al carrello' => '/carrello']];
        }
        if (str_starts_with($path, 'area/abbonamenti')) {
            return ['doing' => $write ? 'Stavi modificando un abbonamento.' : 'Stavi aprendo i tuoi abbonamenti.',
                'effect' => $write ? 'La modifica potrebbe non essere stata applicata: riapri «I miei abbonamenti» e controlla lo stato prima di ripeterla.' : 'I tuoi abbonamenti non sono stati toccati.',
                'links' => ['I miei abbonamenti' => '/area/abbonamenti']];
        }
        if (str_starts_with($path, 'area/kmoney')) {
            return ['doing' => 'Stavi collegando o usando il conto KMoney.', 'effect' => 'Riapri la pagina del profilo per vedere se il conto risulta collegato. Nessun addebito KMoney parte senza un ordine registrato.', 'links' => ['I miei dati' => '/area/profilo', 'I miei ordini' => '/area/ordini']];
        }
        if ($path === 'registrazione' && $write) {
            return ['doing' => 'Ti stavi registrando.', 'effect' => 'La registrazione potrebbe essere andata a buon fine lo stesso: prova ad accedere con l\'email e la password che hai scelto prima di registrarti di nuovo.', 'links' => ['Accedi' => '/accedi']];
        }
        if (str_starts_with($path, 'area/profilo') || str_starts_with($path, 'area/indirizzi')) {
            return ['doing' => $write ? 'Stavi salvando i tuoi dati.' : 'Stavi aprendo i tuoi dati.',
                'effect' => $write ? 'Le modifiche potrebbero non essere state salvate: riapri la pagina e controlla.' : 'I tuoi dati non sono stati toccati.',
                'links' => ['I miei dati' => '/area/profilo']];
        }

        return ['doing' => $write ? 'Stavi inviando un\'operazione.' : 'Stavi aprendo una pagina.',
            'effect' => $write ? 'L\'operazione potrebbe non essere stata registrata: controlla prima di ripeterla.' : 'Stavi solo consultando: non è stato modificato nulla.',
            'links' => $user !== null ? ['La mia area' => '/area'] : []];
    }

    /** @return array{doing:string,effect:string,links:array<string,string>} */
    private static function adminContext(string $path, bool $write, bool $dbOk): array
    {
        $areas = ['orders' => 'gli ordini', 'customers' => 'i clienti', 'subscriptions' => 'gli abbonamenti', 'invoices' => 'le fatture', 'consultants' => 'gli incaricati', 'products' => 'i prodotti', 'payouts' => 'i pagamenti dei compensi'];
        $segments = explode('/', $path);
        $area = $areas[$segments[1] ?? ''] ?? null;
        $doing = 'Stavi lavorando nel back-office'.($area !== null ? " su {$area}" : '').'.';
        $links = ['Torna alla pagina' => '/'.$path];
        $effect = $write
            ? 'L\'operazione potrebbe essere stata eseguita solo in parte: ricarica la pagina e controlla lo stato prima di ripeterla.'
            : 'Stavi solo consultando: non è stato modificato nulla.';
        if ($dbOk && ($segments[1] ?? '') === 'orders' && ctype_digit((string) ($segments[2] ?? ''))) {
            $order = self::try(fn () => Order::find((int) $segments[2]));
            if ($order instanceof Order) {
                $effect = "L'ordine {$order->number} in questo momento risulta: «".self::status($order).'».'.($write ? ' Ricarica la pagina: se lo stato è già quello che volevi, l\'operazione è riuscita e si è fermato solo un passaggio successivo (per esempio l\'email al cliente); non ripeterla.' : '');
            }
        }

        return compact('doing', 'effect', 'links');
    }

    /** 0 = cliente o visitatore, 1 = chi gestisce il sito (vede che cosa controllare), 2 = vede anche i dettagli tecnici. */
    private static function staffLevel(Request $r): int
    {
        try {
            $u = $r->user();
            if ($u === null) {
                return 0;
            }
            if ($u->hasRole(Roles::SUPER_ADMIN)) {
                return 2;
            }

            return $u->hasAnyRole(Roles::backoffice()) ? 1 : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array{class:string,message:string,where:string} */
    private static function technical(Throwable $e): array
    {
        $root = $e;
        while ($root->getPrevious() !== null) {
            $root = $root->getPrevious();
        }
        $file = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $e->getFile());

        return ['class' => $e::class.($root !== $e ? ' ← '.$root::class : ''), 'message' => Str::limit($e->getMessage(), 600), 'where' => $file.':'.$e->getLine()];
    }

    private static function status(Order $order): string
    {
        return (string) StatusLabels::label((string) $order->status);
    }

    private static function startedAt(Request $r): float
    {
        return (float) ($r->server('REQUEST_TIME_FLOAT') ?: (defined('LARAVEL_START') ? LARAVEL_START : microtime(true)));
    }

    /** Una lettura dal database che non deve mai far fallire la pagina di errore: false = non è stato possibile verificare. */
    private static function try(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return false;
        }
    }
}
