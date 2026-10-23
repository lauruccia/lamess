<?php

use App\Domain\Access\Impersonation;
use App\Domain\Audit\AuditLog;
use App\Domain\Billing\BillingException;
use App\Domain\Billing\FatturaPaBuilder;
use App\Domain\Billing\InvoiceService;
use App\Domain\Onboarding\BadgeService;
use App\Domain\Onboarding\SignatureCertificate;
use App\Domain\Orders\ShippingLabelService;
use App\Domain\Payouts\ExportService;
use App\Domain\Payouts\PayoutException;
use App\Domain\Payouts\ReceiptService;
use App\Domain\Payouts\StatementService;
use App\Domain\System\BackupService;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\BadgeVerifyController;
use App\Http\Controllers\BonusStatementController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\KMoneyController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use App\Models\CommissionReceipt;
use App\Models\Consultant;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\IvdContract;
use App\Models\KycDocument;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Period;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', [SiteController::class, 'home'])->name('home');
Route::get('/installazione', [InstallController::class, 'show']);
Route::post('/installazione', [InstallController::class, 'run'])->middleware('throttle:6,1');
Route::get('/negozio', [ShopController::class, 'index']);
Route::get('/negozio/{slug}', [ShopController::class, 'show'])->where('slug', '[a-z0-9-]+');
Route::get('/media/{path}', [ShopController::class, 'media'])->where('path', 'shop/.*');
Route::get('/carrello', [ShopController::class, 'cartView']);
Route::get('/carrello/mini', [ShopController::class, 'mini']);
Route::post('/carrello/aggiungi', [ShopController::class, 'add'])->middleware('throttle:60,1');
Route::post('/carrello/aggiorna', [ShopController::class, 'update']);
Route::post('/carrello/riga', [ShopController::class, 'line'])->middleware('throttle:120,1');
Route::post('/carrello/codice', [ShopController::class, 'applyCode'])->middleware('throttle:20,1');
Route::post('/carrello/codice/rimuovi', [ShopController::class, 'removeCode']);
Route::get('/privacy', [LegalController::class, 'show'])->defaults('page', 'privacy');
Route::get('/termini', [LegalController::class, 'show'])->defaults('page', 'termini');
Route::get('/politiche-di-reso', [LegalController::class, 'show'])->defaults('page', 'politiche-di-reso');
Route::get('/piano-compensi', [LegalController::class, 'show'])->defaults('page', 'piano-compensi');
Route::get('/regolamento', [LegalController::class, 'show'])->defaults('page', 'regolamento');
Route::redirect('/terms', '/termini', 301);
Route::get('/r/{code}', [SiteController::class, 'referral'])->name('referral');
Route::get('/verifica-tesserino/{code}/{signature}', [BadgeVerifyController::class, 'show'])->where(['code' => '[A-Za-z0-9-]+', 'signature' => '[a-f0-9]+'])->middleware('throttle:30,1');

Route::middleware('guest')->group(function () {
    Route::get('/registrazione', [SiteController::class, 'registerForm'])->name('register');
    Route::post('/registrazione', [SiteController::class, 'register'])->middleware('throttle:10,1');
    Route::get('/accedi', [SiteController::class, 'loginForm'])->name('login');
    Route::post('/accedi', [SiteController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/password-dimenticata', [SiteController::class, 'forgotForm'])->name('password.request');
    Route::post('/password-dimenticata', [SiteController::class, 'forgot'])->middleware('throttle:5,1');
    Route::get('/password-reset/{token}', [SiteController::class, 'resetForm'])->name('password.reset');
    Route::post('/password-reset', [SiteController::class, 'reset'])->middleware('throttle:10,1')->name('password.update');
});
Route::post('/area/torna-admin', function (Request $request) {
    Impersonation::stop($request) ?: abort(403);

    return redirect('/admin');
})->middleware('auth')->name('impersonation.stop');
Route::post('/esci', [SiteController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->prefix('area')->group(function () {
    Route::get('/', [AreaController::class, 'home'])->name('area');
    Route::get('/diventa-incaricato', [AreaController::class, 'becomeForm']);
    Route::post('/diventa-incaricato', [AreaController::class, 'apply']);
    Route::post('/diventa-incaricato/firma', [AreaController::class, 'sign'])->middleware('impersonation.safe')->middleware('throttle:10,1');
    Route::post('/diventa-incaricato/codice', [AreaController::class, 'resendCode'])->middleware('throttle:5,1');
    Route::get('/onboarding', [AreaController::class, 'onboarding']);
    Route::post('/onboarding/verifica-online', [AreaController::class, 'startOnlineKyc'])->middleware('throttle:6,1');
    Route::post('/onboarding/documento', [AreaController::class, 'uploadDocument'])->middleware('impersonation.safe');
    Route::post('/onboarding/iban', [AreaController::class, 'saveIban'])->middleware('impersonation.safe');
    Route::get('/file/{kind}', [AreaController::class, 'download']);
    Route::get('/rete', [AreaController::class, 'network']);
    Route::get('/rete/{id}/dettaglio', [AreaController::class, 'networkDetail'])->whereNumber('id')->middleware('throttle:120,1');
    Route::get('/clienti', [AreaController::class, 'customers']);
    Route::get('/profilo', [AreaController::class, 'profile']);
    Route::post('/profilo', [AreaController::class, 'saveProfile']);
    Route::post('/indirizzi', [AreaController::class, 'addAddress'])->middleware('throttle:20,1');
    Route::post('/indirizzi/{address}', [AreaController::class, 'updateAddress'])->middleware('throttle:20,1');
    Route::post('/indirizzi/{address}/{action}', [AreaController::class, 'addressAction'])->whereIn('action', ['elimina', 'predefinito'])->middleware('throttle:20,1');
    Route::post('/profilo/password', [AreaController::class, 'savePassword'])->middleware('impersonation.safe')->middleware('throttle:6,1');
    Route::get('/statini-bonus', [BonusStatementController::class, 'index']);
    Route::get('/statini-bonus/{week}', [BonusStatementController::class, 'show']);
    Route::get('/statini-bonus/{week}/pdf', [BonusStatementController::class, 'pdf']);
    Route::get('/statini', [AreaController::class, 'statements']);
    Route::get('/statini/{period}', [AreaController::class, 'statement']);
    Route::get('/statini/{period}/pdf', [AreaController::class, 'statementPdf']);
    Route::post('/statini/{period}/reclamo', [AreaController::class, 'disputeStatement'])->middleware('throttle:6,1');
    Route::get('/assistenza', [SupportController::class, 'index']);
    Route::post('/assistenza', [SupportController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/assistenza/{ticket}', [SupportController::class, 'show']);
    Route::post('/assistenza/{ticket}/rispondi', [SupportController::class, 'reply'])->middleware('throttle:20,1');
    Route::post('/assistenza/{ticket}/chiudi', [SupportController::class, 'close'])->middleware('throttle:20,1');
    Route::get('/cessione', [TransferController::class, 'show']);
    Route::post('/cessione', [TransferController::class, 'store'])->middleware('throttle:5,1');
    Route::post('/cessione/{transfer}/annulla', [TransferController::class, 'cancel'])->middleware('throttle:10,1');
    Route::get('/conformita', [ComplianceController::class, 'index']);
    Route::post('/conformita/materiali', [ComplianceController::class, 'submit'])->middleware('throttle:10,1');
    Route::post('/conformita/materiali/{material}/ritira', [ComplianceController::class, 'withdraw'])->middleware('throttle:10,1');
    Route::get('/novita', [ContentController::class, 'news']);
    Route::get('/novita/{slug}', [ContentController::class, 'newsShow']);
    Route::get('/materiali', [ContentController::class, 'downloads']);
    Route::get('/materiali/{download}/scarica', [ContentController::class, 'download'])->middleware('throttle:30,1');
    Route::get('/eventi', [ContentController::class, 'events']);
    Route::post('/eventi/{event}/iscrizione', [ContentController::class, 'register'])->middleware('throttle:20,1');
    Route::post('/eventi/{event}/annulla', [ContentController::class, 'unregister'])->middleware('throttle:20,1');
    Route::get('/referral', [ContentController::class, 'referral']);
    Route::get('/referral/qr.svg', [ContentController::class, 'referralQr']);
    Route::post('/referral/inviti', [ContentController::class, 'inviteStore'])->middleware('throttle:20,1');
    Route::post('/referral/inviti/{invite}/ricorda', [ContentController::class, 'inviteRemind'])->middleware('throttle:20,1');
    Route::get('/wallet', [WalletController::class, 'wallet']);
    Route::post('/wallet/prelievo', [WalletController::class, 'withdraw'])->middleware('impersonation.safe')->middleware('throttle:10,1');
    Route::post('/wallet/prelievo/{withdrawal}/documento', [WalletController::class, 'addDocument'])->middleware('impersonation.safe')->middleware('throttle:10,1');
    Route::get('/wallet/prelievo/{withdrawal}/documento', [WalletController::class, 'document']);
    Route::post('/wallet/prelievo/{withdrawal}/annulla', [WalletController::class, 'cancelWithdrawal'])->middleware('impersonation.safe')->middleware('throttle:10,1');
    Route::get('/ricevute', [WalletController::class, 'receipts']);
    Route::get('/ricevute/{receipt}', [WalletController::class, 'receipt']);
    Route::get('/ricevute/{receipt}/pdf', [WalletController::class, 'receiptPdf']);
    Route::post('/ricevute/{receipt}/reclamo', [WalletController::class, 'disputeReceipt'])->middleware('throttle:6,1');
    Route::get('/checkout', [ShopController::class, 'checkoutForm']);
    Route::get('/checkout/spedizione', [ShopController::class, 'checkoutShipping']);
    Route::get('/checkout/pagamento', [ShopController::class, 'checkoutPayment']);
    Route::get('/checkout/riepilogo', [ShopController::class, 'checkoutReview']);
    Route::post('/checkout/passo/{step}', [ShopController::class, 'checkoutAdvance'])->whereNumber('step')->middleware('throttle:60,1');
    Route::post('/checkout', [ShopController::class, 'checkout'])->middleware('impersonation.safe')->middleware('throttle:20,1');
    Route::post('/checkout/dati', [ShopController::class, 'saveBilling'])->middleware('throttle:30,1');
    Route::post('/checkout/kmoney', [ShopController::class, 'checkoutConnectKMoney'])->middleware('impersonation.safe')->middleware('throttle:20,1');
    Route::post('/checkout/nazione', [ShopController::class, 'checkoutCountry'])->middleware('throttle:60,1');
    Route::post('/carrello/per-altri', [ShopController::class, 'addForOther']);
    Route::get('/abbonamenti', [ShopController::class, 'subscriptions']);
    Route::post('/abbonamenti/{subscription}/{action}', [ShopController::class, 'subscriptionAction'])->whereIn('action', ['pausa', 'riprendi', 'annulla', 'salta'])->middleware('throttle:20,1');
    Route::post('/abbonamenti/{subscription}/carta', [ShopController::class, 'subscriptionCard'])->middleware('throttle:10,1');
    Route::post('/abbonamenti/{subscription}/indirizzo', [ShopController::class, 'subscriptionAddress'])->middleware('throttle:20,1');
    Route::get('/ordini', [ShopController::class, 'orders']);
    Route::get('/ordini/{order}', [ShopController::class, 'order']);
    Route::get('/kmoney/collega', [KMoneyController::class, 'connect'])->middleware('impersonation.safe')->middleware('throttle:20,1');
    Route::get('/kmoney/ritorno', [KMoneyController::class, 'back']);
    Route::get('/kmoney/mandato', [KMoneyController::class, 'mandate'])->middleware('impersonation.safe')->middleware('throttle:20,1');
    Route::post('/kmoney/scollega', [KMoneyController::class, 'disconnect'])->middleware('impersonation.safe')->middleware('throttle:10,1');
    Route::post('/ordini/{order}/resto', [ShopController::class, 'chooseRest'])->middleware('throttle:10,1');
    Route::post('/ordini/{order}/riprova', [ShopController::class, 'retryCard'])->middleware('throttle:10,1');
    Route::post('/ordini/{order}/annulla', [ShopController::class, 'cancelOwn'])->middleware('throttle:10,1');
    Route::post('/ordini/{order}/reso', [ShopController::class, 'requestReturn'])->middleware('throttle:10,1');
    Route::get('/ordini/{order}/fattura', [ShopController::class, 'invoicePdf']);
    Route::get('/note-credito/{note}', [ShopController::class, 'creditNotePdf']);
});

// Notifiche server-to-server dei provider (firmate, senza sessione né CSRF)
Route::post('/webhooks/kmoney', [KMoneyController::class, 'webhook'])->middleware('throttle:120,1');
Route::post('/webhooks/stripe', [WebhookController::class, 'stripe'])->middleware('throttle:120,1');

// File riservati agli amministratori (lettere firmate, documenti KYC)
Route::middleware(['auth'])->prefix('admin-files')->group(function () {
    Route::get('/contract/{contract}', function (IvdContract $contract) {
        abort_unless(auth()->user()->can('network.manage') && $contract->pdf_path && Storage::exists($contract->pdf_path), 403);

        return Storage::response($contract->pdf_path);
    });
    Route::get('/contract/{contract}/certificate', function (IvdContract $contract) {
        abort_unless(auth()->user()->can('network.manage') && $contract->signed_at !== null && $contract->signature_provider !== 'odoo', 403);

        return response(app(SignatureCertificate::class)->pdf($contract), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="certificato-firma-'.$contract->id.'.pdf"']);
    });
    Route::get('/orders/etichette', function (Request $request) {
        abort_unless(auth()->user()->can('orders.manage'), 403);
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));
        abort_if($ids === [], 404);
        $orders = Order::with('items', 'buyer')->whereIn('id', $ids)->orderBy('id')->get();
        AuditLog::record('order.labels_printed', 'Stampate le etichette di spedizione: '.$orders->count().' ordini', null, ['ordini' => $orders->pluck('number')->all()]);

        return response(app(ShippingLabelService::class)->pdf($orders, $request->query('formato') !== 'singola'), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="etichette-spedizione.pdf"']);
    });
    Route::get('/badge/{consultant}', function (Consultant $consultant) {
        abort_unless(auth()->user()->can('network.manage'), 403);
        $badges = app(BadgeService::class);
        abort_unless($badges->isIssuable($consultant), 404, 'Il tesserino si stampa solo per gli incaricati attivi.');
        AuditLog::record('badge.printed', "Stampato il tesserino dell’incaricato {$consultant->code}", $consultant);

        return $badges->response($consultant);
    });
    Route::get('/invoice/{invoice}', function (Invoice $invoice) {
        abort_unless(auth()->user()->can('orders.view'), 403);

        return response(app(InvoiceService::class)->pdf($invoice), 200, ['Content-Type' => 'application/pdf']);
    });
    Route::get('/invoice/{invoice}/xml', function (Invoice $invoice) {
        abort_unless(auth()->user()->can('orders.view'), 403);
        try {
            $f = app(FatturaPaBuilder::class)->build($invoice);
        } catch (BillingException $e) {
            abort(422, $e->getMessage());
        }

        return response($f['xml'], 200, ['Content-Type' => 'application/xml', 'Content-Disposition' => 'attachment; filename="'.$f['filename'].'"']);
    });
    Route::get('/credit-note/{note}/xml', function (CreditNote $note) {
        abort_unless(auth()->user()->can('orders.view'), 403);
        try {
            $f = app(FatturaPaBuilder::class)->build($note);
        } catch (BillingException $e) {
            abort(422, $e->getMessage());
        }

        return response($f['xml'], 200, ['Content-Type' => 'application/xml', 'Content-Disposition' => 'attachment; filename="'.$f['filename'].'"']);
    });
    Route::get('/credit-note/{note}', function (CreditNote $note) {
        abort_unless(auth()->user()->can('orders.view'), 403);

        return response(app(InvoiceService::class)->pdf($note), 200, ['Content-Type' => 'application/pdf']);
    });
    Route::get('/statement/{period:code}/{consultant}', function (Period $period, Consultant $consultant) {
        abort_unless(auth()->user()->can('payouts.view'), 403);
        try {
            return response(app(StatementService::class)->pdf($period, $consultant), 200, ['Content-Type' => 'application/pdf']);
        } catch (PayoutException $e) {
            abort(404, $e->getMessage());
        }
    });
    Route::get('/export/commissions/{period:code}', function (Period $period) {
        abort_unless(auth()->user()->can('payouts.view'), 403);
        try {
            $csv = app(ExportService::class)->commissions($period);
        } catch (PayoutException $e) {
            abort(404, $e->getMessage());
        }
        AuditLog::record('export.commissions', "Esportate le provvigioni di {$period->code}", $period);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=provvigioni-{$period->code}.csv"]);
    });
    Route::get('/receipt/{receipt}', function (CommissionReceipt $receipt) {
        abort_unless(auth()->user()->can('payouts.view'), 403);

        return response(app(ReceiptService::class)->pdf($receipt), 200, ['Content-Type' => 'application/pdf']);
    });
    Route::get('/export/payouts', function () {
        abort_unless(auth()->user()->can('payouts.view'), 403);
        $payouts = Payout::with('consultant', 'period')->where('status', Payout::TO_PAY)->orderBy('id')->get();
        AuditLog::record('export.payouts', 'Esportato l\'elenco dei bonifici da fare: '.$payouts->count(), null, ['pagamenti' => $payouts->pluck('id')->all()]);
        Payout::whereIn('id', $payouts->pluck('id'))->update(['exported_at' => now()]);

        return response(app(ExportService::class)->payouts($payouts), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename=bonifici-da-fare.csv']);
    });
    Route::get('/backup/{name}', function (string $name) {
        abort_unless(auth()->user()->isSuperAdmin(), 403);
        AuditLog::record('backup.downloaded', "Scaricato il backup {$name}", null, ['file' => $name]);

        return response()->download(app(BackupService::class)->path($name));
    });
    Route::get('/kyc/{doc}', function (KycDocument $doc) {
        abort_unless(auth()->user()->can('network.manage') && Storage::exists($doc->path), 403);

        return Storage::response($doc->path);
    });
});
