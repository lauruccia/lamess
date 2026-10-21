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

/** Tasca (ex wallet), prelievi e ricevute dell’incaricato. */
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
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,9}([.,]\d{1,2})?$/'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], ['amount.regex' => 'Indica un importo valido, per esempio 120,50.', 'required' => 'Indica l\'importo.', 'document.mimes' => 'Il documento deve essere un PDF o un\'immagine JPG o PNG.', 'document.max' => 'Il documento può pesare al massimo 5 MB.']);
        try {
            $r = $svc->request($c, str_replace(',', '.', $data['amount']));
        } catch (WalletException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }
        if ($request->hasFile('document')) {
            $this->storeDocument($r, $request->file('document'));
        }

        return back()->with('status', 'Richiesta di prelievo inviata. Il pagamento avviene entro 5 giorni lavorativi dall\'approvazione.'.($request->hasFile('document') ? '' : ' Il documento fiscale puoi aggiungerlo dopo, dalla richiesta.'));
    }

    private function storeDocument(WithdrawalRequest $r, \Illuminate\Http\UploadedFile $file): void
    {
        $path = $file->store('withdrawal-documents/'.$r->consultant_id, 'local');
        $r->update(['document_path' => $path, 'document_name' => mb_substr($file->getClientOriginalName(), 0, 150), 'document_added_at' => now()]);
    }

    public function addDocument(Request $request, WithdrawalRequest $withdrawal): RedirectResponse
    {
        $c = $this->me($request);
        abort_unless((int) $withdrawal->consultant_id === (int) $c->id, 404);
        $request->validate(['document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']], ['document.required' => 'Scegli il file da allegare.', 'document.mimes' => 'Il documento deve essere un PDF o un\'immagine JPG o PNG.', 'document.max' => 'Il documento può pesare al massimo 5 MB.']);
        if (! in_array($withdrawal->status, [WithdrawalRequest::REQUESTED, WithdrawalRequest::APPROVED, WithdrawalRequest::PAID], true)) {
            return back()->withErrors(['amount' => 'A questa richiesta non si può più aggiungere un documento.']);
        }
        $this->storeDocument($withdrawal, $request->file('document'));

        return back()->with('status', 'Documento aggiunto alla richiesta.');
    }

    public function document(Request $request, WithdrawalRequest $withdrawal)
    {
        $c = $this->me($request);
        abort_unless((int) $withdrawal->consultant_id === (int) $c->id && $withdrawal->document_path !== null, 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($withdrawal->document_path, $withdrawal->document_name ?: 'documento');
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
