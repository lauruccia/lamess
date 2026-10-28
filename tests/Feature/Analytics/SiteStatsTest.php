<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Access\Roles;
use App\Domain\Analytics\SiteStats;
use App\Domain\Analytics\Tracker;
use App\Domain\Analytics\TrafficSource;
use App\Domain\Analytics\UserAgent;
use App\Filament\Pages\SiteStatsPage;
use App\Models\AnalyticsHit;
use App\Models\AnalyticsVisit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Statistiche del sito: rilevamento delle visite, classificazione della provenienza, numeri e pagina del back-office. */
final class SiteStatsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 330.0';

    protected function setUp(): void
    {
        parent::setUp();
        config(['site.open' => true]);
        Tracker::ready();
    }

    public function test_a_page_view_creates_a_visit_and_a_second_one_in_the_same_session_adds_to_it(): void
    {
        $this->withHeaders(['User-Agent' => self::CHROME, 'Referer' => 'https://www.google.com/', 'CF-IPCountry' => 'IT'])->get('/negozio?utm_campaign=autunno')->assertOk();
        $this->withHeaders(['User-Agent' => self::CHROME])->get('/privacy')->assertOk();

        $this->assertSame(1, AnalyticsVisit::count());
        $v = AnalyticsVisit::first();
        $this->assertSame(2, $v->hits);
        $this->assertSame('Google', $v->source);
        $this->assertSame('organico', $v->medium);
        $this->assertSame('autunno', $v->campaign);
        $this->assertSame('IT', $v->country);
        $this->assertSame('desktop', $v->device);
        $this->assertSame('Chrome', $v->browser);
        $this->assertSame('/negozio', $v->landing_path);
        $this->assertSame('/privacy', $v->current_path);
        $this->assertSame('front', $v->landing_area);
        $this->assertSame(2, AnalyticsHit::count());
    }

    public function test_bots_posts_and_missing_pages_are_not_counted(): void
    {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])->get('/negozio')->assertOk();
        $this->withHeaders(['User-Agent' => self::CHROME])->post('/carrello/aggiungi', [])->assertStatus(302);
        $this->withHeaders(['User-Agent' => self::CHROME])->get('/pagina-che-non-esiste')->assertNotFound();
        $this->withHeaders(['User-Agent' => self::CHROME, 'X-Requested-With' => 'XMLHttpRequest'])->get('/carrello/mini');

        $this->assertSame(0, AnalyticsVisit::count());
        $this->assertSame(0, AnalyticsHit::count());
    }

    public function test_shop_searches_are_recorded_with_the_number_of_results(): void
    {
        $this->withHeaders(['User-Agent' => self::CHROME])->get('/negozio?q=Olio+Calabrese')->assertOk();

        $hit = AnalyticsHit::first();
        $this->assertSame('olio calabrese', $hit->search_term);
        $this->assertSame(0, $hit->search_results);
    }

    public function test_a_logged_in_user_is_attached_to_the_visit_and_staff_is_flagged(): void
    {
        Roles::sync();
        $admin = User::factory()->role(Roles::SUPER_ADMIN)->create();

        $this->actingAs($admin)->withHeaders(['User-Agent' => self::CHROME])->get('/negozio')->assertOk();

        $v = AnalyticsVisit::first();
        $this->assertSame($admin->id, $v->user_id);
        $this->assertTrue($v->is_staff);
    }

    public function test_back_office_pages_are_counted_in_the_back_area_but_not_the_stats_page_itself(): void
    {
        Roles::sync();
        $admin = User::factory()->role(Roles::SUPER_ADMIN)->create();

        $this->actingAs($admin)->withHeaders(['User-Agent' => self::CHROME])->get('/admin/statistiche-sito');
        $this->assertSame(0, AnalyticsHit::count());

        $this->actingAs($admin)->withHeaders(['User-Agent' => self::CHROME])->get('/admin/cruscotto-vendite')->assertOk();
        $this->assertSame('back', AnalyticsHit::first()->area);
        $this->assertSame('back', AnalyticsVisit::first()->landing_area);
    }

    public function test_a_new_visit_starts_after_the_pause(): void
    {
        $this->withHeaders(['User-Agent' => self::CHROME])->get('/negozio')->assertOk();
        $this->withSession(['an.last' => time() - Tracker::SESSION_GAP - 5])->withHeaders(['User-Agent' => self::CHROME])->get('/negozio')->assertOk();

        $this->assertSame(2, AnalyticsVisit::count());
    }

    public function test_the_site_keeps_working_when_the_tables_are_missing(): void
    {
        \Illuminate\Support\Facades\Schema::drop('analytics_hits');
        \Illuminate\Support\Facades\Schema::drop('analytics_visits');
        \Illuminate\Support\Facades\Cache::flush();

        $this->withHeaders(['User-Agent' => self::CHROME])->get('/negozio')->assertOk();
        $this->assertFalse(Tracker::ready());
    }

    public function test_sources_are_classified(): void
    {
        $own = 'lamess.test';
        $c = fn (?string $ref, array $q = [], string $ua = self::CHROME) => TrafficSource::classify($ref, $q, $ua, $own);

        $this->assertSame('Google', $c('https://www.google.it/search?q=x')['source']);
        $this->assertSame('Facebook', $c('https://l.facebook.com/l.php?u=1')['source']);
        $this->assertSame('social', $c('https://l.instagram.com/')['medium']);
        $this->assertSame('Altri siti', $c('https://blog.example.org/post')['source']);
        $this->assertSame('blog.example.org', $c('https://blog.example.org/post')['referrer_host']);
        $this->assertSame('Diretto', $c(null)['source']);
        $this->assertSame('Diretto', $c('https://www.lamess.test/negozio')['source']);
        $this->assertSame('Instagram', $c(null, [], self::IPHONE)['source']);
        $this->assertSame('Facebook', $c(null, ['fbclid' => 'abc'])['source']);
        $this->assertSame('Google', $c(null, ['gclid' => 'abc'])['source']);
        $this->assertSame('a pagamento', $c(null, ['gclid' => 'abc'])['medium']);
        $u = $c('https://www.google.com/', ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'ottobre']);
        $this->assertSame(['Email', 'email', 'ottobre'], [$u['source'], $u['medium'], $u['campaign']]);
    }

    public function test_user_agents_paths_and_ips_are_normalised(): void
    {
        $this->assertTrue(UserAgent::isBot(''));
        $this->assertTrue(UserAgent::isBot('curl/8.0'));
        $this->assertFalse(UserAgent::isBot(self::CHROME));
        $this->assertSame('mobile', UserAgent::device(self::IPHONE));
        $this->assertSame('Instagram (app)', UserAgent::browser(self::IPHONE));
        $this->assertSame('iOS', UserAgent::os(self::IPHONE));
        $this->assertSame('tablet', UserAgent::device('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit Safari'));

        $this->assertSame('/firma/*/conferma', Tracker::normalizePath('/firma/AbCdEfGhIjKlMnOpQrStUv/conferma'));
        $this->assertSame('/', Tracker::normalizePath(''));
        $this->assertSame('/negozio/olio-extra', Tracker::normalizePath('negozio/olio-extra'));
        $this->assertSame('203.0.113.0', Tracker::maskIp('203.0.113.77'));
        $this->assertSame('2001:db8:abcd::', Tracker::maskIp('2001:db8:abcd:12:ffff::1'));
        $this->assertNull(Tracker::maskIp('non-un-ip'));
    }

    public function test_stats_count_visits_sources_pages_and_leave_staff_out_by_default(): void
    {
        $now = CarbonImmutable::parse('2026-10-28 12:00:00');
        $this->visit('v1', $now->subDay(), ['source' => 'Google', 'country' => 'IT', 'city' => 'Reggio Calabria', 'hits' => 3, 'landing_path' => '/negozio']);
        $this->visit('v2', $now->subDays(2), ['source' => 'Instagram', 'medium' => 'social', 'device' => 'mobile', 'hits' => 1, 'referral_code' => 'ABC123']);
        $this->visit('v3', $now->subDays(2), ['source' => 'Diretto', 'is_staff' => true, 'hits' => 1]);
        $this->visit('v4', $now->subDays(40), ['source' => 'Google', 'hits' => 1]);   // fuori periodo
        $this->hit('v1', $now->subDay(), '/negozio', 'front', 'olio', 0);
        $this->hit('v1', $now->subDay(), '/negozio', 'front', 'miele', 4);
        $this->hit('v1', $now->subDay(), '/carrello', 'front');
        $this->hit('v2', $now->subDays(2), '/negozio', 'front');
        $this->hit('v3', $now->subDays(2), '/negozio', 'front');

        $s = (new SiteStats($now))->build('front', 30, false);

        $this->assertSame(2, $s['tiles']['visits']['value']);
        $this->assertSame(4, $s['tiles']['hits']['value']);
        $this->assertSame(50.0, $s['tiles']['bounce']['value']);
        $this->assertSame(['Google', 'Instagram'], array_column($s['sources'], 'label'));
        $this->assertSame('/negozio', $s['pages'][0]['label']);
        $this->assertSame(3, $s['pages'][0]['count']);
        $this->assertSame(['ABC123'], array_column($s['referral_codes'], 'label'));
        $this->assertSame(['miele', 'olio'], array_column($s['searches']['top'], 'label'));
        $this->assertSame(['olio'], array_column($s['searches']['empty'], 'label'));
        $this->assertSame('Italia', $s['countries'][0]['label'] === 'IT' ? 'Italia' : $s['countries'][0]['label']);
        $this->assertTrue($s['has_geo']);
        $this->assertCount(30, $s['daily']);

        $withStaff = (new SiteStats($now))->build('front', 30, true);
        $this->assertSame(3, $withStaff['tiles']['visits']['value']);
    }

    public function test_online_lists_only_recent_activity_with_who_and_where(): void
    {
        $now = CarbonImmutable::parse('2026-10-28 12:00:00');
        $user = User::factory()->create(['name' => 'Maria Rossi']);
        $this->visit('on1', $now->subMinutes(2), ['user_id' => $user->id, 'current_path' => '/area/ordini', 'last_seen_at' => $now->subMinute()]);
        $this->visit('off', $now->subMinutes(30), ['last_seen_at' => $now->subMinutes(20)]);

        $online = (new SiteStats($now))->online();

        $this->assertCount(1, $online);
        $this->assertSame('Maria Rossi', $online[0]['who']);
        $this->assertSame('Cliente', $online[0]['kind']);
        $this->assertSame('/area/ordini', $online[0]['path']);
    }

    public function test_the_stats_page_is_for_people_with_the_kpi_permission_and_renders(): void
    {
        Roles::sync();
        $this->actingAs(User::factory()->role('support')->create());
        $this->assertFalse(SiteStatsPage::canAccess());

        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $this->assertTrue(SiteStatsPage::canAccess());

        $this->visit('p1', CarbonImmutable::now()->subHour(), ['source' => 'Google', 'last_seen_at' => CarbonImmutable::now()]);
        $this->hit('p1', CarbonImmutable::now()->subHour(), '/negozio', 'front');

        Livewire::test(SiteStatsPage::class)
            ->assertSuccessful()->assertSee('Statistiche sito')->assertSee('Online adesso')->assertSee('Come arrivano')->assertSee('Ricerche nel negozio')
            ->set('area', 'back')->assertSuccessful()->assertSee('Chi ha lavorato nel back-office')->assertDontSee('Come arrivano')
            ->set('days', 7)->set('area', 'front')->set('includeStaff', true)->assertSuccessful()
            ->set('days', 3)->assertSet('days', 30);
    }

    public function test_the_stats_page_renders_on_an_empty_database(): void
    {
        Roles::sync();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());

        Livewire::test(SiteStatsPage::class)->assertSuccessful()->assertSee('Nessuno in questo momento')->assertSee('Nessuna visita nel periodo');
        $this->get('/admin/statistiche-sito')->assertOk();
    }

    public function test_cleanup_removes_only_old_rows(): void
    {
        $this->visit('old', now()->subDays(400), ['last_seen_at' => now()->subDays(399)]);
        $this->visit('new', now()->subDays(2), ['last_seen_at' => now()->subDay()]);
        $this->hit('old', now()->subDays(399), '/x', 'front');
        $this->hit('new', now()->subDay(), '/y', 'front');

        $this->artisan('lamess:statistiche-pulizia')->assertSuccessful();

        $this->assertSame(['new'], AnalyticsVisit::pluck('vid')->all());
        $this->assertSame(['/y'], AnalyticsHit::pluck('path')->all());
    }

    /** @param array<string, mixed> $o */
    private function visit(string $vid, \DateTimeInterface $at, array $o = []): AnalyticsVisit
    {
        return AnalyticsVisit::create($o + [
            'vid' => $vid, 'started_at' => $at, 'last_seen_at' => $at, 'landing_area' => 'front', 'landing_path' => '/', 'current_area' => 'front', 'current_path' => '/',
            'source' => 'Diretto', 'medium' => 'diretto', 'device' => 'desktop', 'browser' => 'Chrome', 'os' => 'Windows', 'hits' => 1,
        ]);
    }

    private function hit(string $vid, \DateTimeInterface $at, string $path, string $area, ?string $term = null, ?int $results = null): void
    {
        AnalyticsHit::create(['visit_id' => AnalyticsVisit::where('vid', $vid)->value('id'), 'created_at' => $at, 'area' => $area, 'path' => $path, 'search_term' => $term, 'search_results' => $results]);
    }
}
