<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Payouts\BonusStatementService;
use App\Models\Consultant;
use Illuminate\Http\Request;

/** Statini settimanali dei Bonus di Struttura dell'incaricato. */
class BonusStatementController extends Controller
{
    private function me(Request $request): Consultant
    {
        $c = $request->user()->consultant;
        abort_if($c === null || $c->status === 'terminated', 404);

        return $c;
    }

    private function week(string $week): string
    {
        abort_unless(preg_match(BonusStatementService::WEEK_PATTERN, $week) === 1, 404);

        return $week;
    }

    public function index(Request $request, BonusStatementService $svc)
    {
        return view('site.area.bonus-statements', ['weeks' => $svc->weeks($this->me($request))]);
    }

    public function show(Request $request, string $week, BonusStatementService $svc)
    {
        return view('site.area.bonus-statement', ['s' => $svc->build($this->me($request), $this->week($week))]);
    }

    public function pdf(Request $request, string $week, BonusStatementService $svc)
    {
        $c = $this->me($request);

        return response($svc->pdf($c, $this->week($week)), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=statino-bonus-{$week}.pdf"]);
    }
}
