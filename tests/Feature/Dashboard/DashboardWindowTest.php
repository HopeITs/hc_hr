<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\Index;
use App\Models\City;
use App\Support\Dashboard\DashboardLayout;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * A card taken out of the browser (2026-11-03): one card on a page of its own
 * (Dashboard\Window), opened as a window or floating on top in a frame of the
 * site's own Picture-in-Picture window. Same rule as on the grid, and that
 * one page alone may be framed, by the site itself only.
 */
class DashboardWindowTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $alp;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
        $this->alp = $this->makeCity('ALP', '001');
    }

    public function test_a_card_opens_alone_and_only_its_page_may_be_framed_by_the_site(): void
    {
        $user = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);

        $response = $this->actingAs($user)->get(route('dashboard.window', ['tile' => 'my-requests']));

        $response->assertOk()
            ->assertSee('data-testid="dashboard-window"', false)
            ->assertSee(__('dashboard.tiles.my-requests'))
            ->assertDontSee('id="app-navbar"', false)
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString("frame-ancestors 'self'", $this->csp($response));

        // Every other page too, framed by this site only: the dashboard's workspace opens pages in a frame.
        $home = $this->actingAs($user)->get(route('dashboard'));
        $home->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString("frame-ancestors 'self'", $this->csp($home));
    }

    public function test_a_card_the_person_may_not_have_has_no_window(): void
    {
        $user = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        $colleague = $this->makeEmployee($this->alp, 'colleague');

        foreach (['employees', 'nonsense', DashboardLayout::recordKey('contracts', $colleague->id), DashboardLayout::APP_PREFIX.'nav.my-space.leaves'] as $tile) {
            $this->actingAs($user)->get(route('dashboard.window', ['tile' => $tile]))->assertNotFound();
        }

        // Their own record card does open.
        $this->actingAs($user)->get(route('dashboard.window', ['tile' => DashboardLayout::recordKey('contact', $user->employee_id)]))->assertOk();

        auth()->logout();
        $this->get(route('dashboard.window', ['tile' => 'my-requests']))->assertRedirect();
    }

    public function test_each_card_offers_to_leave_the_browser(): void
    {
        $user = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        $html = Livewire::actingAs($user)->test(Index::class)->html();

        $this->assertStringContainsString('x-on:click="popOut(', $html);
        $this->assertStringContainsString('x-on:click="floatOnTop(', $html);
        $this->assertStringContainsString('x-on:click="openWindow(', $html);

        preg_match('/data-home="([^"]*)"/', $html, $m);
        $config = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
        $this->assertSame(route('dashboard.window', ['tile' => '__TILE__']), $config['windowUrl']);

        foreach (['window.out', 'window.float', 'window.new', 'window.signed-out', 'window.sign-in'] as $key) {
            foreach (['en', 'ar'] as $locale) {
                $this->assertNotSame('dashboard.'.$key, __('dashboard.'.$key, [], $locale), "{$key} {$locale}");
            }
        }
    }

    private function csp($response): string
    {
        return (string) ($response->headers->get('Content-Security-Policy') ?? $response->headers->get('Content-Security-Policy-Report-Only'));
    }
}
