<?php

namespace Tests\Feature;

use App\Livewire\Settings\InstallApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * "Install the app": the settings page, the header link and the layout banner,
 * all driven by resources/js/pwa-install.js. Whether the browser offers its
 * prompt is decided in the browser, so the script is checked for the contract
 * with partials/head.blade.php (window.__hopehrInstallPrompt and the
 * hopehr:installable / hopehr:installed events) rather than run here.
 */
class PwaInstallUiTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_the_page_renders_for_a_signed_in_user_with_every_platform(): void
    {
        $user = $this->makeUser($this->makeCity('ALP', '001'), 'employee');

        $html = $this->actingAs($user)->get(route('settings.app'))
            ->assertOk()
            ->assertSee(__('pwa.title'))
            ->assertSee('favicons/android-chrome-192x192.png', false)
            ->assertSee('x-data="pwaInstall"', false)
            ->getContent();

        // One step list per detectable platform, plus the four in the disclosure.
        foreach (InstallApp::PLATFORMS as $platform) {
            $this->assertStringContainsString('data-platform="'.$platform.'"', $html);
            $this->assertStringContainsString(e(__("pwa.platforms.{$platform}.title")), $html);
        }
        foreach (InstallApp::OTHER_DEVICES as $platform) {
            $this->assertStringContainsString('data-other-platform="'.$platform.'"', $html);
        }

        // The Install button only ever appears behind a real prompt.
        $this->assertMatchesRegularExpression('/data-testid="install-app-button"[^>]*x-show="canPrompt"/s', $html);
        // Plain about what installing does not do.
        $this->assertStringContainsString(e(__('pwa.about.points')[2]), $html);
        // The page explains it all, so the banner stays off here.
        $this->assertStringNotContainsString('data-testid="install-banner"', $html);
    }

    public function test_the_page_reads_in_arabic(): void
    {
        $user = $this->makeUser($this->makeCity('ALP', '001'), 'employee');
        app()->setLocale('ar');

        Livewire::actingAs($user)->test(InstallApp::class)
            ->assertSee('تثبيت التطبيق')
            ->assertSee('آيفون أو آيباد: سفاري');
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('settings.app'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_the_header_menu_links_to_the_page_and_the_layout_carries_the_banner(): void
    {
        $user = $this->makeUser($this->makeCity('ALP', '001'), 'employee');

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#href="'.preg_quote(route('settings.app'), '#').'"[^>]*data-install-app-link[^>]*x-data="pwaInstall"[^>]*x-show="!installed"#s',
            $html,
        );
        $this->assertStringContainsString(__('pwa.menu'), $html);

        // The banner: hidden until Alpine decides, above the phone bar's safe area.
        $this->assertMatchesRegularExpression('/x-data="pwaInstall" x-show="showBanner" x-cloak/', $html);
        $this->assertStringContainsString('app-install-banner', $html);
        $this->assertStringContainsString(e(__('pwa.banner.dismiss')), $html);
    }

    public function test_the_script_reads_the_head_contract_and_remembers_dismissal(): void
    {
        $js = file_get_contents(resource_path('js/pwa-install.js'));

        $this->assertStringContainsString("import './pwa-install.js';", file_get_contents(resource_path('js/app.js')));
        $this->assertStringContainsString("Alpine.data('pwaInstall'", $js);

        // The prompt is captured by the head script; this only reads and clears it.
        $this->assertStringContainsString('window.__hopehrInstallPrompt', $js);
        $this->assertStringContainsString("addEventListener('hopehr:installable'", $js);
        $this->assertStringContainsString("addEventListener('hopehr:installed'", $js);
        $this->assertStringContainsString('await event.prompt()', $js);
        $this->assertStringContainsString('await event.userChoice', $js);
        $this->assertStringContainsString('window.__hopehrInstallPrompt = null', $js);

        // Listeners are removed when wire:navigate tears the component down.
        $this->assertStringContainsString("removeEventListener('hopehr:installable'", $js);
        $this->assertStringContainsString("removeEventListener('hopehr:installed'", $js);

        // Installed: standalone display mode, or iOS's home-screen flag.
        $this->assertStringContainsString("'(display-mode: standalone)'", $js);
        $this->assertStringContainsString('navigator.standalone', $js);

        // Dismissal: a timestamp under this key, 30 days, storage errors swallowed.
        $this->assertStringContainsString("DISMISS_KEY = 'hopehr-install-dismissed'", $js);
        $this->assertStringContainsString('30 * 24 * 60 * 60 * 1000', $js);
        $this->assertSame(2, substr_count($js, 'window.localStorage.'));
        $this->assertSame(2, preg_match_all('/try \{\s*(?:const at = Number\()?window\.localStorage\./', $js));

        // Every platform the page has words for is one the script can name.
        foreach (InstallApp::PLATFORMS as $platform) {
            $this->assertStringContainsString("'{$platform}'", $js);
        }
    }
}
