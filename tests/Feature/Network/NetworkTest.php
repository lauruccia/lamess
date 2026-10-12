<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Domain\Network\NetworkException;
use App\Domain\Network\NetworkService;
use App\Domain\Periods\PeriodService;
use App\Models\CommissionLine;
use App\Models\Consultant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\NetworkDb;
use Tests\TestCase;

final class NetworkTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private function net(): NetworkService
    {
        return app(NetworkService::class);
    }

    private function enroll(string $code, ?Consultant $sponsor = null, ?string $key = null, string $since = '2026-10-01 08:00'): Consultant
    {
        return $this->net()->enroll(['code' => $code, 'name' => "Incaricato {$code}", 'person_key' => $key], $sponsor, $this->at($since));
    }

    public function test_closure_table_tracks_depth_and_leg_roots(): void
    {
        $a = $this->enroll('A');
        $b = $this->enroll('B', $a);
        $c = $this->enroll('C', $b);
        $d = $this->enroll('D', $a);

        $row = DB::table('network_paths')->where('ancestor_id', $a->id)->where('descendant_id', $c->id)->first();
        $this->assertSame(2, $row->depth);
        $this->assertSame($b->id, $row->leg_root_id);
        $this->assertSame($d->id, DB::table('network_paths')->where('ancestor_id', $a->id)->where('descendant_id', $d->id)->value('leg_root_id'));
        $this->assertSame(4, DB::table('network_paths')->where('ancestor_id', $a->id)->count());

        $before = DB::table('network_paths')->count();
        $this->net()->rebuildClosure();
        $this->assertSame($before, DB::table('network_paths')->count());
    }

    public function test_sponsor_cannot_be_changed_directly(): void
    {
        $a = $this->enroll('A');
        $b = $this->enroll('B', $a);
        $c = $this->enroll('C', $a);
        $this->expectException(NetworkException::class);
        $b->update(['sponsor_id' => $c->id]);
    }

    public function test_g20_terminated_with_three_directs_and_terminated_sponsor(): void
    {
        $g = $this->enroll('G');
        $s = $this->enroll('S', $g);
        $x = $this->enroll('X', $s);
        $kids = [$this->enroll('K1', $x), $this->enroll('K2', $x), $this->enroll('K3', $x)];

        $this->net()->terminate($s, 'dimissioni');   // lo sponsor cessa prima
        $this->assertSame($g->id, $x->refresh()->sponsor_id);
        $this->net()->terminate($x, 'dimissioni');

        foreach ($kids as $k) {
            $this->assertSame($g->id, $k->refresh()->sponsor_id);
            $this->assertSame($x->id, $k->original_sponsor_id);
        }
        $this->assertSame(3, DB::table('sponsor_history')->where('from_sponsor_id', $x->id)->where('to_sponsor_id', $g->id)->where('reason', 'compression')->count());
        $this->assertSame(1, (int) DB::table('network_paths')->where('ancestor_id', $g->id)->where('descendant_id', $kids[0]->id)->value('depth'));
    }

    public function test_terminated_sponsor_with_orphan_chain_ends_at_root(): void
    {
        $a = $this->enroll('A');
        $b = $this->enroll('B', $a);
        $this->net()->terminate($a, 'x');
        $this->assertNull($b->refresh()->sponsor_id);
    }

    public function test_commissions_flow_to_first_active_upline_after_termination(): void
    {
        $p = $this->openPeriod('2026-10');
        $a = $this->consultant('A', null, 'DIAMOND');
        $mid = $this->consultant('M', $a, 'EMERALD');
        $c = $this->consultant('C', $mid);
        $this->net()->terminate($mid, 'x', $this->at('2026-10-02 10:00'));
        $this->sale($p, $c, '100');

        $run = app(PeriodService::class)->calculate($p);
        $this->assertSame('15.00', number_format((float) CommissionLine::where('run_id', $run->id)->where('beneficiary_id', $a->id)->where('bonus_type', 'team')->sum('amount'), 2, '.', ''));
        $this->assertSame(0, CommissionLine::where('run_id', $run->id)->where('beneficiary_id', $mid->id)->where('engine_status', 'accrued')->count());
    }

    public function test_status_transitions_are_validated_and_logged(): void
    {
        $a = $this->enroll('A');
        $this->net()->changeStatus($a, 'suspended', 'verifica');
        $this->net()->changeStatus($a, 'active');
        $this->net()->changeStatus($a, 'terminated', 'recesso');
        $this->assertSame(4, DB::table('consultant_status_changes')->where('consultant_id', $a->id)->count());
        $this->expectException(NetworkException::class);
        $this->net()->changeStatus($a, 'active');
    }

    public function test_terminated_consultant_cannot_sponsor(): void
    {
        $a = $this->enroll('A');
        $this->net()->terminate($a, 'x');
        $this->expectException(NetworkException::class);
        $this->enroll('B', $a);
    }

    public function test_reenrollment_only_after_180_days(): void
    {
        $a = $this->enroll('A', null, 'RSSMRA80A01H501U');
        $this->net()->terminate($a, 'x', $this->at('2026-10-10 10:00'));

        try {
            $this->enroll('A2', null, 'RSSMRA80A01H501U', '2027-03-01 10:00'); // 142 giorni
            $this->fail('atteso rifiuto');
        } catch (NetworkException $e) {
            $this->assertStringContainsString('180 giorni', $e->getMessage());
        }
        $again = $this->enroll('A2', null, 'RSSMRA80A01H501U', '2027-04-09 10:00'); // 181 giorni
        $this->assertSame('active', $again->status);
    }

    public function test_reenrollment_days_is_configurable(): void
    {
        config(['lamess.reenrollment_days' => 30]);
        $a = $this->enroll('A', null, 'KEY1');
        $this->net()->terminate($a, 'x', $this->at('2026-10-10 10:00'));
        $this->assertSame('active', $this->enroll('A2', null, 'KEY1', '2026-11-15 10:00')->status);
    }

    public function test_person_with_active_assignment_cannot_enroll_twice(): void
    {
        $this->enroll('A', null, 'KEY1');
        $this->expectException(NetworkException::class);
        $this->enroll('A2', null, 'KEY1');
    }
}
