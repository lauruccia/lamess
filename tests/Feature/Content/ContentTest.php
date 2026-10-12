<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Domain\Access\Roles;
use App\Domain\Content\ContentException;
use App\Domain\Content\DownloadService;
use App\Domain\Content\EventService;
use App\Domain\Content\RankLookup;
use App\Domain\Content\ReferralQr;
use App\Filament\Resources\CorporateEvents\Pages\ListCorporateEvents;
use App\Filament\Resources\DownloadLogs\Pages\ListDownloadLogs;
use App\Filament\Resources\Downloads\Pages\ListDownloads;
use App\Filament\Resources\NewsPosts\Pages\ListNewsPosts;
use App\Models\CorporateEvent;
use App\Models\Download;
use App\Models\DownloadLog;
use App\Models\NewsPost;
use App\Models\RankAchievement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Novità, materiali con filigrana, eventi con lista d'attesa e QR del referral. */
final class ContentTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->plan();
    }

    private function consultantUser(string $code = 'A', ?string $rank = null): User
    {
        $u = User::factory()->create(['name' => "Maria {$code}"]);
        $this->consultant($code, null, $rank)->update(['user_id' => $u->id, 'referral_code' => "REF{$code}"]);

        return $u->refresh();
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(400, 300);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function pdf(): string
    {
        $p = new \FPDF;
        $p->AddPage();
        $p->SetFont('Helvetica', '', 14);
        $p->Cell(0, 10, 'Listino riservato');
        $p->AddPage();
        $p->Cell(0, 10, 'Pagina due');

        return $p->Output('S');
    }

    private function download(string $path, string $name, string $mime, array $over = []): Download
    {
        return Download::create($over + ['title' => 'Listino', 'file_path' => $path, 'original_name' => $name, 'mime' => $mime, 'size' => 10, 'audience' => 'consultants']);
    }

    public function test_news_follow_the_audience_and_the_publication_date(): void
    {
        $c = $this->consultantUser();
        $fan = User::factory()->create();
        NewsPost::create(['title' => 'Per tutti', 'slug' => 'tutti', 'body' => 'x', 'audience' => 'all', 'status' => 'published']);
        NewsPost::create(['title' => 'Solo rete', 'slug' => 'rete', 'body' => 'x', 'audience' => 'consultants', 'status' => 'published']);
        NewsPost::create(['title' => 'Bozza', 'slug' => 'bozza', 'body' => 'x', 'status' => 'draft']);
        NewsPost::create(['title' => 'Futura', 'slug' => 'futura', 'body' => 'x', 'status' => 'published', 'published_at' => now()->addDay()]);

        $this->actingAs($c)->get('/area/novita')->assertOk()->assertSee('Per tutti')->assertSee('Solo rete')->assertDontSee('Bozza')->assertDontSee('Futura');
        $this->actingAs($fan)->get('/area/novita')->assertSee('Per tutti')->assertDontSee('Solo rete');
        $this->actingAs($fan)->get('/area/novita/rete')->assertNotFound();
        $this->actingAs($c)->get('/area/novita/bozza')->assertNotFound();
    }

    public function test_an_image_is_watermarked_and_the_download_is_logged(): void
    {
        $u = $this->consultantUser('A');
        Storage::disk('local')->put('downloads/logo.png', $this->png());
        $d = $this->download('downloads/logo.png', 'logo.png', 'image/png');

        $res = $this->actingAs($u)->get("/area/materiali/{$d->id}/scarica");

        $res->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertNotSame($this->png(), $res->getContent());
        $this->assertNotFalse(imagecreatefromstring($res->getContent()));
        $log = DownloadLog::firstOrFail();
        $this->assertTrue($log->watermarked);
        $this->assertSame($u->id, $log->user_id);
    }

    public function test_a_pdf_is_watermarked_page_by_page(): void
    {
        $u = $this->consultantUser('A');
        Storage::disk('local')->put('downloads/l.pdf', $this->pdf());
        $d = $this->download('downloads/l.pdf', 'l.pdf', 'application/pdf');

        $out = app(DownloadService::class)->prepare($d, $u);

        $this->assertTrue($out['watermarked']);
        $this->assertStringStartsWith('%PDF', $out['content']);
        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page\b/', $out['content']));
        $this->assertGreaterThan(strlen($this->pdf()), strlen($out['content']));
    }

    public function test_an_unreadable_pdf_goes_out_untouched_and_the_log_says_so(): void
    {
        $u = $this->consultantUser('A');
        Storage::disk('local')->put('downloads/rotto.pdf', '%PDF-1.7 non è davvero un pdf');
        $d = $this->download('downloads/rotto.pdf', 'rotto.pdf', 'application/pdf');

        $out = app(DownloadService::class)->prepare($d, $u);

        $this->assertFalse($out['watermarked']);
        $this->assertStringContainsString('non è davvero', $out['content']);
        $this->assertNotNull(DownloadLog::firstOrFail()->note);
    }

    public function test_fail_closed_refuses_the_file_when_the_watermark_fails(): void
    {
        config(['lamess.content.watermark_fail_open' => false]);
        $u = $this->consultantUser('A');
        Storage::disk('local')->put('downloads/rotto.pdf', 'xx');
        $d = $this->download('downloads/rotto.pdf', 'rotto.pdf', 'application/pdf');

        $this->expectException(ContentException::class);
        try {
            app(DownloadService::class)->prepare($d, $u);
        } finally {
            $this->assertSame(0, DownloadLog::count());
        }
    }

    public function test_other_formats_are_not_watermarked_and_a_switched_off_watermark_is_respected(): void
    {
        $u = $this->consultantUser('A');
        Storage::disk('local')->put('downloads/a.zip', 'PK');
        Storage::disk('local')->put('downloads/b.png', $this->png());
        $zip = $this->download('downloads/a.zip', 'a.zip', 'application/zip');
        $png = $this->download('downloads/b.png', 'b.png', 'image/png', ['watermark' => false]);

        $this->assertFalse(app(DownloadService::class)->prepare($zip, $u)['watermarked']);
        $this->assertSame($this->png(), app(DownloadService::class)->prepare($png, $u)['content']);
    }

    public function test_access_depends_on_audience_active_flag_and_minimum_rank(): void
    {
        $diamond = $this->consultantUser('A', 'DIAMOND');
        $pearl = $this->consultantUser('B', 'PEARL');
        $fan = User::factory()->create();
        Storage::disk('local')->put('downloads/x.zip', 'PK');
        $open = $this->download('downloads/x.zip', 'x.zip', 'application/zip', ['audience' => 'all']);
        $team = $this->download('downloads/x.zip', 'x.zip', 'application/zip');
        $top = $this->download('downloads/x.zip', 'x.zip', 'application/zip', ['min_rank_code' => 'EMERALD']);
        $off = $this->download('downloads/x.zip', 'x.zip', 'application/zip', ['active' => false, 'audience' => 'all']);
        $svc = app(DownloadService::class);

        $this->assertTrue($svc->canDownload($open, $fan));
        $this->assertFalse($svc->canDownload($team, $fan));
        $this->assertTrue($svc->canDownload($team, $pearl));
        $this->assertTrue($svc->canDownload($top, $diamond));
        $this->assertFalse($svc->canDownload($top, $pearl));
        $this->assertFalse($svc->canDownload($off, $diamond));

        $this->actingAs($pearl)->get("/area/materiali/{$top->id}/scarica")->assertRedirect('/area/materiali');
        $this->assertSame(0, DownloadLog::count());

        $diamond->consultant->update(['status' => 'suspended']);
        $this->assertFalse($svc->canDownload($team, $diamond->refresh()));
    }

    public function test_rank_lookup_takes_the_highest_effective_rank(): void
    {
        $u = $this->consultantUser('A', 'CRYSTAL');
        RankAchievement::create(['consultant_id' => $u->consultant->id, 'rank_code' => 'RUBY', 'achieved_at' => now(), 'effective_from' => now()->addMonth()->toDateString(), 'source' => 'manual']);

        $this->assertSame('CRYSTAL', RankLookup::current($u->consultant)['code']);
        $this->assertTrue(RankLookup::atLeast($u->consultant, 'PEARL'));
        $this->assertFalse(RankLookup::atLeast($u->consultant, 'RUBY'));
        $this->assertFalse(RankLookup::atLeast($u->consultant, 'NON_ESISTE'));
    }

    private function event(array $over = []): CorporateEvent
    {
        return CorporateEvent::create($over + ['title' => 'Corso base', 'starts_at' => now()->addDays(10), 'status' => 'published', 'capacity' => 2]);
    }

    public function test_registrations_fill_the_seats_then_the_waitlist_and_a_cancellation_promotes_the_first(): void
    {
        $e = $this->event();
        [$a, $b, $c, $d] = [User::factory()->create(), User::factory()->create(), User::factory()->create(), User::factory()->create()];
        $svc = app(EventService::class);

        $this->assertSame('registered', $svc->register($e, $a)->status);
        $this->assertSame('registered', $svc->register($e, $b)->status);
        $this->assertSame('waitlist', $svc->register($e, $c)->status);
        $this->assertSame('waitlist', $svc->register($e, $d)->status);

        $promoted = $svc->cancel($e, $a);

        $this->assertSame($c->id, $promoted->user_id);
        $this->assertSame('registered', $svc->mine($e, $c)->status);
        $this->assertSame('waitlist', $svc->mine($e, $d)->status);
        $this->assertSame(2, $e->registeredCount());
    }

    public function test_registration_rules(): void
    {
        $u = User::factory()->create();
        $svc = app(EventService::class);

        $e = $this->event(['capacity' => null]);
        $svc->register($e, $u);
        try {
            $svc->register($e, $u);
            $this->fail('doppia iscrizione');
        } catch (ContentException $x) {
            $this->assertStringContainsString('già iscritto', $x->getMessage());
        }
        $svc->cancel($e, $u);
        $this->assertSame('registered', $svc->register($e, $u)->status, 'ci si può reiscrivere dopo aver annullato');

        foreach ([['status' => 'draft'], ['status' => 'cancelled'], ['starts_at' => now()->subDay()], ['registration_deadline' => now()->subHour()], ['audience' => 'consultants']] as $over) {
            try {
                $svc->register($this->event($over), $u);
                $this->fail('doveva rifiutare '.json_encode($over));
            } catch (ContentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_events_page_registers_and_cancels(): void
    {
        $u = User::factory()->create();
        $e = $this->event(['title' => 'Webinar di ottobre', 'capacity' => null]);

        $this->actingAs($u)->get('/area/eventi')->assertOk()->assertSee('Webinar di ottobre');
        $this->actingAs($u)->post("/area/eventi/{$e->id}/iscrizione")->assertSessionHas('status');
        $this->assertNotNull(app(EventService::class)->mine($e, $u));
        $this->actingAs($u)->post("/area/eventi/{$e->id}/annulla")->assertSessionHas('status');
        $this->assertNull(app(EventService::class)->mine($e, $u));
    }

    public function test_referral_page_and_qr_are_for_active_consultants_only(): void
    {
        $u = $this->consultantUser('A');
        $svg = $this->actingAs($u)->get('/area/referral/qr.svg');

        $svg->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $svg->getContent());
        $this->actingAs($u)->get('/area/referral')->assertOk()->assertSee(url('/r/REFA'), false);
        $this->actingAs($u)->get('/area/referral/qr.svg?scarica=1')->assertHeader('Content-Disposition', 'attachment; filename="qr-referral-REFA.svg"');
        $this->assertSame(url('/r/REFA'), ReferralQr::url($u->consultant));

        $this->actingAs(User::factory()->create())->get('/area/referral')->assertNotFound();
        $u->consultant->update(['status' => 'terminated']);
        $this->actingAs($u)->get('/area/referral/qr.svg')->assertNotFound();
    }

    public function test_backoffice_screens_are_gated_by_the_content_permission(): void
    {
        $this->actingAs(User::factory()->role('support')->create());
        foreach ([ListNewsPosts::class, ListDownloads::class, ListCorporateEvents::class, ListDownloadLogs::class] as $page) {
            Livewire::test($page)->assertSuccessful();
        }

        $this->actingAs(User::factory()->role('board')->create());
        $this->get('/admin/news-posts')->assertForbidden();
        $this->get('/admin/downloads')->assertForbidden();

        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $this->get('/admin/corporate-events')->assertOk();
    }

    public function test_backoffice_tables_render_with_records(): void
    {
        $u = $this->consultantUser('A');
        $n = NewsPost::create(['title' => 'Novità', 'slug' => 'n', 'body' => 'x', 'status' => 'published']);
        Storage::disk('local')->put('downloads/a.zip', 'PK');
        $d = $this->download('downloads/a.zip', 'a.zip', 'application/zip');
        $e = $this->event();
        app(EventService::class)->register($e, $u);
        app(DownloadService::class)->prepare($d, $u);
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());

        Livewire::test(ListNewsPosts::class)->assertCanSeeTableRecords([$n]);
        Livewire::test(ListDownloads::class)->assertCanSeeTableRecords([$d]);
        Livewire::test(ListCorporateEvents::class)->assertCanSeeTableRecords([$e])->callTableAction('registrations', $e);
        Livewire::test(ListDownloadLogs::class)->assertCanSeeTableRecords(DownloadLog::all());
    }
}
