<?php

declare(strict_types=1);

namespace Tests\Feature\Stability;

use App\Domain\Access\Roles;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\User;
use App\Support\Errors\ErrorExplainer;
use ErrorException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\View\ViewException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\ShopDb;
use Tests\TestCase;
use Throwable;

/** Errori del server: al posto del «500» generico una pagina che dice che cosa è successo e che cosa ne è stato dell'ordine. */
final class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
    }

    private function boom(Throwable $e, string $uri = '/_prova-errore', string $method = 'get'): void
    {
        Route::middleware('web')->{$method}($uri, fn () => throw $e);
    }

    private function staff(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    private static function mysql(string $message, string $state, int $code): QueryException
    {
        $pdo = new PDOException($message);
        $pdo->errorInfo = [$state, $code, $message];

        return new QueryException('mysql', 'select 1', [], $pdo);
    }

    /** @return array<string, array{0: Throwable, 1: string}> */
    public static function problems(): array
    {
        return [
            'tabella mancante dopo un deploy' => [self::mysql("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'lamess_portale.shipments' doesn't exist", '42S02', 1146), 'db_schema'],
            'colonna mancante dopo un deploy' => [self::mysql("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'weight_grams' in 'field list'", '42S22', 1054), 'db_schema'],
            'troppe connessioni sull\'hosting' => [self::mysql("SQLSTATE[HY000] [1203] User lamess_user already has more than 'max_user_connections' active connections", 'HY000', 1203), 'db_down'],
            'database irraggiungibile' => [self::mysql('SQLSTATE[HY000] [2002] Connection refused', 'HY000', 2002), 'db_down'],
            'deadlock' => [self::mysql('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', '40001', 1213), 'db_busy'],
            'attesa del blocco scaduta' => [self::mysql('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction', 'HY000', 1205), 'db_busy'],
            'doppio invio' => [self::mysql("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'FT-2026-0012' for key 'invoices_number_unique'", '23000', 1062), 'db_duplicate'],
            'testo troppo lungo' => [self::mysql("SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'city' at row 1", '22001', 1406), 'db_data'],
            'campo obbligatorio vuoto' => [self::mysql("SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'buyer_user_id' cannot be null", '23000', 1048), 'db_constraint'],
            'email non partita' => [new TransportException('Connection could not be established with host "mail.example.test:465"'), 'mail'],
            'Stripe non risponde' => [new ConnectionException('cURL error 28: Operation timed out for https://api.stripe.com/v1/checkout/sessions'), 'external'],
            'memoria esaurita' => [new ErrorException('Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)'), 'limits_memory'],
            'spazio esaurito' => [new ErrorException('file_put_contents(): Write of 120 bytes failed with errno=28 No space left on device'), 'storage_full'],
            'cartella non scrivibile' => [new ErrorException('file_put_contents(/home/lamess/storage/framework/views/x.php): Failed to open stream: Permission denied'), 'storage'],
            'file mancante dopo un deploy' => [new \Error('Class "App\Domain\Shipping\PosteClient" not found'), 'deploy'],
            'errore del codice' => [new ErrorException('Attempt to read property "type" on null'), 'code'],
            'errore del codice dentro una vista' => [new ViewException('Undefined array key "vat_number" (View: invoice.blade.php)', 0, 1, 'x.php', 3, new ErrorException('Undefined array key "vat_number"')), 'code'],
            'errore del database dentro una vista' => [new ViewException('x', 0, 1, 'x.php', 3, self::mysql("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x' in 'field list'", '42S22', 1054)), 'db_schema'],
            'altro' => [new RuntimeException('boh'), 'unknown'],
        ];
    }

    #[DataProvider('problems')]
    public function test_each_kind_of_problem_is_recognised(Throwable $e, string $kind): void
    {
        $this->assertSame($kind, ErrorExplainer::classify($e)['kind']);
    }

    public function test_an_order_number_in_the_query_is_not_mistaken_for_an_error_code(): void
    {
        $e = self::mysql("SQLSTATE[HY000]: General error: 1105 Unknown error (SQL: select * from orders where number = '2026-22001-40001')", 'HY000', 1105);

        $this->assertSame('db_other', ErrorExplainer::classify($e)['kind']);
    }

    public function test_a_real_missing_table_names_what_is_missing_for_the_staff(): void
    {
        try {
            DB::table('tabella_che_non_esiste')->count();
            $this->fail();
        } catch (QueryException $e) {
            $p = ErrorExplainer::classify($e);
        }

        $this->assertSame('db_schema', $p['kind']);
        $this->assertStringContainsString('«tabella_che_non_esiste»', $p['staff']);
        $this->assertStringContainsString('phpMyAdmin', $p['staff']);
    }

    public function test_visitor_sees_the_explanation_with_a_reference_and_no_technical_detail(): void
    {
        $this->boom(self::mysql("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'lamess_portale.shipments' doesn't exist", '42S02', 1146));

        $r = $this->get('/_prova-errore')->assertStatus(500);

        $r->assertSee('Il sito è stato aggiornato')->assertSee('Stavi solo consultando: non è stato modificato nulla.');
        $this->assertMatchesRegularExpression('/<code>LM-\d{6}-\d{4}-[A-Z0-9]{4}<\/code>/', $r->getContent());
        $r->assertDontSee('shipments')->assertDontSee('Per chi gestisce il sito')->assertDontSee('QueryException');
    }

    public function test_admin_sees_what_to_check_and_only_super_admin_the_technical_detail(): void
    {
        $this->boom(self::mysql("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'lamess_portale.shipments' doesn't exist", '42S02', 1146));

        $this->actingAs($this->staff(Roles::ADMIN))->get('/_prova-errore')->assertStatus(500)
            ->assertSee('Per chi gestisce il sito')->assertSee('«shipments»', false)->assertSee('registro-aggiornamenti')->assertDontSee('QueryException');
        $this->actingAs($this->staff(Roles::SUPER_ADMIN))->get('/_prova-errore')->assertStatus(500)
            ->assertSee('Per chi gestisce il sito')->assertSee('QueryException');
    }

    public function test_the_reference_on_the_page_is_the_one_written_in_the_log(): void
    {
        $this->boom(new RuntimeException('boh'));
        $logged = null;
        Log::listen(function ($m) use (&$logged): void {
            $logged = $m->context['rif'] ?? $logged;
        });

        $r = $this->get('/_prova-errore')->assertStatus(500);

        $this->assertNotNull($logged);
        $r->assertSee($logged);
    }

    public function test_other_responses_stay_as_they_were(): void
    {
        $this->boom(new RuntimeException('boh'));
        $this->boom(new RuntimeException('boh'), '/webhooks/_prova', 'post');

        $this->get('/pagina-che-non-esiste')->assertStatus(404)->assertDontSee('LM-');
        $this->getJson('/_prova-errore')->assertStatus(500)->assertJsonStructure(['message']);
        $hook = $this->post('/webhooks/_prova')->assertStatus(500);
        $this->assertStringStartsWith('Errore interno (unknown), rif. LM-', $hook->getContent());
        config(['app.debug' => true]);
        $this->assertStringContainsString('<title>Laravel</title>', (string) $this->get('/_prova-errore')->assertStatus(500)->getContent());
    }

    private function request(string $uri, string $method, ?User $user): Request
    {
        $r = Request::create($uri, $method, server: ['REQUEST_TIME_FLOAT' => microtime(true)]);
        $r->setUserResolver(fn () => $user);

        return $r;
    }

    public function test_failed_checkout_says_whether_the_order_was_registered(): void
    {
        $this->plan();
        $this->shipping('0.00');
        $buyer = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');

        $none = ErrorExplainer::context($this->request('/area/checkout', 'POST', $buyer), 'db_busy', false);
        $this->assertStringContainsString('L\'ordine non è stato registrato', $none['effect']);
        $this->assertArrayHasKey('Torna al carrello', $none['links']);

        $req = $this->request('/area/checkout', 'POST', $buyer);
        $o = app(OrderService::class)->place($buyer, [$p->id => 1], 'IT', 'transfer', $this->billing());
        $some = ErrorExplainer::context($req, 'mail', false);
        $this->assertStringContainsString("L'ordine {$o->number} è stato registrato", $some['effect']);
        $this->assertStringContainsString('In attesa di pagamento', $some['effect']);
        $this->assertSame("/area/ordini/{$o->id}", array_values($some['links'])[0]);

        // database fermo: non si può verificare e non si prova nemmeno a leggerlo
        $down = ErrorExplainer::context($this->request('/area/checkout', 'POST', $buyer), 'db_down', false);
        $this->assertStringContainsString('non riusciamo a verificare', $down['effect']);
    }

    public function test_order_pages_show_the_current_state_only_to_the_owner(): void
    {
        $this->plan();
        $this->shipping('0.00');
        $buyer = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $o = app(OrderService::class)->place($buyer, [$p->id => 1], 'IT', 'transfer', $this->billing());

        $mine = ErrorExplainer::context($this->request("/area/ordini/{$o->id}/riprova", 'POST', $buyer), 'external', false);
        $this->assertSame('Stavi pagando un ordine.', $mine['doing']);
        $this->assertStringContainsString("L'ordine {$o->number} è al sicuro", $mine['effect']);

        $other = ErrorExplainer::context($this->request("/area/ordini/{$o->id}/fattura", 'GET', User::factory()->create()), 'pdf', false);
        $this->assertStringNotContainsString($o->number, $other['effect']);

        $admin = ErrorExplainer::context($this->request("/admin/orders/{$o->id}", 'GET', $this->staff(Roles::ADMIN)), 'mail', true);
        $this->assertStringContainsString("L'ordine {$o->number} in questo momento risulta: «In attesa di pagamento»", $admin['effect']);
    }

    /** Caso reale: il pagamento è registrato, poi l'email di conferma non parte (SMTP fermo, coda «sync» come su cPanel). */
    public function test_payment_registered_but_mail_not_sent_is_explained_with_the_real_order_state(): void
    {
        $this->plan();
        $this->shipping('0.00');
        $buyer = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $o = app(OrderService::class)->place($buyer, [$p->id => 1], 'IT', 'transfer', $this->billing());
        config(['lamess.mail_enabled' => true, 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'queue.default' => 'sync']);
        // in prova l'invio è spento all'avvio (AppServiceProvider): qui si riaccende come sul sito vero
        Event::forget(MessageSending::class);
        app('mail.manager')->forgetMailers();
        Route::middleware('web')->post('/area/ordini/{id}/prova-pagamento', fn (int $id) => app(OrderService::class)->markPaid(Order::findOrFail($id)));

        $r = $this->actingAs($buyer)->post("/area/ordini/{$o->id}/prova-pagamento")->assertStatus(500);

        $r->assertSee('Non siamo riusciti a inviare un')->assertSee("L'ordine {$o->number} è al sicuro e in questo momento risulta: «Pagato».");
        $this->assertSame('paid', $o->refresh()->status);
    }
}
