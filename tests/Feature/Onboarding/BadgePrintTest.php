<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Onboarding\BadgeService;
use App\Domain\Onboarding\IvdService;
use App\Models\AuditEntry;
use App\Models\Consultant;
use App\Models\IvdContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

final class BadgePrintTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    private function activeConsultant(): Consultant
    {
        $fan = $this->fan($this->activeSponsor());

        return app(IvdService::class)->accept($this->signedContract($fan), 'admin')->consultant->refresh();
    }

    public function test_consultant_prints_own_badge_even_without_a_stored_file(): void
    {
        Storage::fake();
        $c = $this->activeConsultant();
        $c->update(['badge_path' => null]); // incaricato importato, senza tesserino già generato

        $r = $this->actingAs($c->user)->get('/area/file/tesserino')->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertStringContainsString('inline', (string) $r->headers->get('Content-Disposition'));
    }

    public function test_badge_is_refused_when_the_assignment_is_not_in_force(): void
    {
        Storage::fake();
        $c = $this->activeConsultant();
        $c->update(['status' => 'suspended']);

        $this->actingAs($c->user)->get('/area/file/tesserino')->assertNotFound();
        $this->actingAs(User::factory()->role('super_admin')->create())->get("/admin-files/badge/{$c->id}")->assertNotFound();
    }

    public function test_admin_and_superadmin_print_any_active_badge_and_it_is_logged(): void
    {
        Storage::fake();
        $c = $this->activeConsultant();

        foreach (['super_admin', 'admin_network'] as $role) {
            $r = $this->actingAs(User::factory()->role($role)->create())->get("/admin-files/badge/{$c->id}")->assertOk();
            $this->assertStringStartsWith('%PDF', $r->getContent());
        }
        $this->assertSame(2, AuditEntry::where('action', 'badge.printed')->count());
    }

    public function test_other_users_cannot_print_a_badge_from_the_admin_route(): void
    {
        Storage::fake();
        $c = $this->activeConsultant();
        $this->get("/admin-files/badge/{$c->id}")->assertRedirect('/accedi');
        $this->actingAs($c->user)->get("/admin-files/badge/{$c->id}")->assertForbidden();
        $this->actingAs(User::factory()->role('board')->create())->get("/admin-files/badge/{$c->id}")->assertForbidden();
    }

    public function test_public_verification_page_confirms_a_valid_badge_only_with_the_signed_link(): void
    {
        Storage::fake();
        $c = $this->activeConsultant();
        $path = parse_url(app(BadgeService::class)->verifyUrl($c), PHP_URL_PATH);

        $this->get($path)->assertOk()->assertSee('Tesserino valido')->assertSee($c->name)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get("/verifica-tesserino/{$c->code}/0123456789abcdef0123")->assertNotFound()->assertSee('Tesserino non valido')->assertDontSee($c->name);
    }

    public function test_verification_page_rejects_suspended_and_expired_badges(): void
    {
        Storage::fake();
        $c = $this->activeConsultant();
        $path = parse_url(app(BadgeService::class)->verifyUrl($c), PHP_URL_PATH);

        IvdContract::where('consultant_id', $c->id)->update(['expires_at' => now()->subDay()]);
        $this->get($path)->assertNotFound()->assertSee('Tesserino non valido')->assertDontSee($c->name);

        IvdContract::where('consultant_id', $c->id)->update(['expires_at' => now()->addYear()]);
        $c->update(['status' => 'suspended']);
        $this->get($path)->assertNotFound()->assertSee('Tesserino non valido');
    }
}
