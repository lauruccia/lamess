<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Succession\SuccessionException;
use App\Domain\Succession\TransferService;
use App\Models\AccountTransfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Cessione del proprio codice attività e account (Reg. 9.1). */
class TransferController extends Controller
{
    private function mine(Request $request)
    {
        $c = $request->user()->consultant;
        abort_if($c === null || $c->status !== 'active', 404);

        return $c;
    }

    public function show(Request $request)
    {
        $c = $this->mine($request);

        return view('site.area.transfer', [
            'transfers' => AccountTransfer::where('consultant_id', $c->id)->with('assignee.profile')->orderByDesc('id')->get(), 'fee' => (string) config('lamess.transfer_fee'),
        ]);
    }

    public function store(Request $request, TransferService $svc): RedirectResponse
    {
        $c = $this->mine($request);
        $data = $request->validate(['email' => 'required|email', 'confirm' => 'accepted'], ['confirm.accepted' => 'Conferma di aver letto le condizioni.'] + SiteController::MESSAGES);
        try {
            $svc->request($c, $request->user(), $data['email']);
        } catch (SuccessionException $e) {
            return back()->withErrors(['email' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Richiesta registrata. Ti scriveremo con le istruzioni per pagare la fee: l\'esame comincia quando il pagamento risulta.');
    }

    public function cancel(Request $request, AccountTransfer $transfer, TransferService $svc): RedirectResponse
    {
        $this->mine($request);
        try {
            $svc->cancel($transfer, $request->user());
        } catch (SuccessionException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        return back()->with('status', 'Richiesta annullata.');
    }
}
