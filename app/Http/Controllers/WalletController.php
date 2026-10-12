<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Payouts\ReceiptService;
use App\Domain\Payouts\StatementLifecycle;
use App\Domain\Support\SupportException;
use App\Domain\Wallet\WalletException;
use App\Domain\Wallet\WalletService;
use App\Domain\Wallet\WithdrawalService;
use App\Models\CommissionReceipt;
use App\Models\Consultant;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Wallet, prelievi e ricevute dell’incaricato. */
class WalletController extends Controller
{
    private function me(Request $request): Consultant
    {
        $c = $request->user()->consultant;
        abort_if($c === null || $c->status === 'terminated', 404);

        return $c;
    }

    public function wallet(Request $request, WalletService $wallet)
    {
        $c = $this->me($request);

        return view('site.area.wallet', [
            'enabled' => WithdrawalService::enabled(), 's' => $wallet->situation($c),
            'transactions' => WalletTransaction::where('consultant_id', $c->id)->orderByDesc('id')->limit(100)->get(),
            'requests' => WithdrawalRequest::where('consultant_id', $c->id)->orderByDesc('id')->limit(20)->get(),
            'fee' => (string) config('lamess.fiscal.bank_fee'),
        ]);
    }

    public function withdraw(Request $request, WithdrawalService $svc): RedirectResponse
    {
        $c = $this->me($request);
        $data = $request->validate(['amount' => ['required', 'regex:/^\d{1,9}([.,]\d{1,2})?$/']], ['amount.regex' => 'Indica un importo valido, per esempio 120,50.', 'required' => 'Indica l\'importo.']);
        try {
            $svc->request($c, str_replace(',', '.', $data['amount']));
        } catch (WalletException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Richiesta di prelievo inviata. Il pagamento avviene entro 5 giorni lavorativi dall\'approvazione.');
    }

    public function cancelWithdrawal(Request $request, WithdrawalRequest $withdrawal, WithdrawalService $svc): RedirectResponse
    {
        $c = $this->me($request);
        abort_unless((int) $withdrawal->consultant_id === (int) $c->id, 404);
        try {
            $svc->cancel($withdrawal);
        } catch (WalletException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('status', 'Richiesta annullata.');
    }

    public function receipts(Request $request)
    {
        $c = $this->me($request);

        return view('site.area.receipts', ['rows' => CommissionReceipt::where('consultant_id', $c->id)->orderByDesc('id')->get()]);
    }

    private function myReceipt(Request $request, CommissionReceipt $receipt): CommissionReceipt
    {
        abort_unless((int) $receipt->consultant_id === (int) $this->me($request)->id, 404);

        return $receipt;
    }

    public function receipt(Request $request, CommissionReceipt $receipt)
    {
        $this->myReceipt($request, $receipt);

        return view('site.area.receipt', ['r' => $receipt->load('payout.period'), 'disputeDays' => StatementLifecycle::disputeDays(), 'minLength' => StatementLifecycle::MIN_DISPUTE_LENGTH]);
    }

    public function receiptPdf(Request $request, CommissionReceipt $receipt, ReceiptService $svc)
    {
        $this->myReceipt($request, $receipt);

        return response($svc->pdf($receipt), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=ricevuta-{$receipt->id}.pdf"]);
    }

    public function disputeReceipt(Request $request, CommissionReceipt $receipt, ReceiptService $svc): RedirectResponse
    {
        $this->myReceipt($request, $receipt);
        $data = $request->validate(['text' => 'required|string|max:5000'], SiteController::MESSAGES);
        try {
            $ticket = $svc->dispute($request->user(), $receipt, $data['text']);
        } catch (SupportException $e) {
            return back()->withErrors(['text' => $e->getMessage()])->withInput();
        }

        return redirect("/area/assistenza/{$ticket->id}")->with('status', 'Segnalazione inviata. Ti risponderemo da questa pagina.');
    }
}
