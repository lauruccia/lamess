<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Compliance\ComplianceException;
use App\Domain\Compliance\ComplianceService;
use App\Domain\Compliance\MaterialService;
use App\Models\Consultant;
use App\Models\PromoMaterial;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Conformità per l’incaricato: materiali approvati, richieste di approvazione e sanzioni in corso. */
class ComplianceController extends Controller
{
    private function me(Request $request): Consultant
    {
        $c = $request->user()->consultant;
        abort_if($c === null || $c->status === 'terminated', 404);

        return $c;
    }

    public function index(Request $request, ComplianceService $svc)
    {
        $c = $this->me($request);

        return view('site.area.compliance', [
            'network' => PromoMaterial::usable()->where('shared', true)->orderBy('title')->get(),
            'mine' => PromoMaterial::where('submitted_by_consultant_id', $c->id)->orderByDesc('id')->get(),
            'sanctions' => $svc->active($c),
        ]);
    }

    public function submit(Request $request, MaterialService $svc): RedirectResponse
    {
        $c = $this->me($request);
        $data = $request->validate([
            'title' => 'required|string|max:190', 'description' => 'nullable|string|max:2000', 'claims' => 'nullable|string|max:8000', 'url' => 'nullable|url|max:300',
        ], SiteController::MESSAGES);
        try {
            $svc->submit($c, $data['title'], $data['description'] ?? null, $data['claims'] ?? null, $data['url'] ?? null);
        } catch (ComplianceException $e) {
            return back()->withErrors(['title' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Richiesta inviata. Riceverai l\'esito qui, con il codice da citare se è approvata.');
    }

    public function withdraw(Request $request, PromoMaterial $material, MaterialService $svc): RedirectResponse
    {
        try {
            $svc->withdraw($material, $this->me($request));
        } catch (ComplianceException $e) {
            return back()->withErrors(['title' => $e->getMessage()]);
        }

        return back()->with('status', 'Materiale ritirato.');
    }
}
