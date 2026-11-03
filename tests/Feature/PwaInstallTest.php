<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * D2-PWA (DEC-07: installable, without offline caching of private data).
 * The service worker runs in the browser, so these check what it is allowed
 * to do; the browser checks in d2-pwa-implementation.md show what it did.
 */
class PwaInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_is_valid_and_its_icons_exist(): void
    {
        $manifest = json_decode(file_get_contents(public_path('favicons/site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('HopeHR', $manifest['short_name']);
        $this->assertNotEmpty($manifest['name']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#BE1F24', $manifest['theme_color']);

        $sizes = [];
        foreach ($manifest['icons'] as $icon) {
            $path = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($path);
            [$width, $height] = getimagesize($path);
            $this->assertSame($icon['sizes'], "{$width}x{$height}");
            $sizes[] = $icon['sizes'];
        }
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
    }

    public function test_the_manifest_offers_maskable_icons_and_install_metadata(): void
    {
        $manifest = json_decode(file_get_contents(public_path('favicons/site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        // Android crops icons to its own shape; a maskable icon keeps the logo inside the 80% safe zone.
        $maskable = array_values(array_filter($manifest['icons'], fn ($icon) => $icon['purpose'] === 'maskable'));
        $this->assertSame(['192x192', '512x512'], array_column($maskable, 'sizes'));
        foreach ($maskable as $icon) {
            $path = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($path);
            [$width, $height] = getimagesize($path);
            $this->assertSame($icon['sizes'], "{$width}x{$height}");

            // Full bleed: the corners, which every mask cuts away, are opaque white, not transparent.
            $image = imagecreatefrompng($path);
            $corner = imagecolorsforindex($image, imagecolorat($image, 0, 0));
            $this->assertSame([255, 255, 255, 0], [$corner['red'], $corner['green'], $corner['blue'], $corner['alpha']]);
        }

        $this->assertSame('en', $manifest['lang']);
        $this->assertSame('auto', $manifest['dir']);
        $this->assertSame(['business', 'productivity'], $manifest['categories']);
        $this->assertFalse($manifest['prefer_related_applications']);
        $this->assertSame('navigate-existing', $manifest['launch_handler']['client_mode']);
        // Standalone only: the header does not draw around a title-bar overlay.
        $this->assertArrayNotHasKey('display_override', $manifest);
    }

    public function test_the_start_url_and_shortcuts_resolve_without_a_locale(): void
    {
        $manifest = json_decode(file_get_contents(public_path('favicons/site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        // Signed out, the start URL ends on the sign-in page, which is inside scope "/".
        $response = $this->followingRedirects()->get($manifest['start_url']);
        $response->assertOk();
        $this->assertStringContainsString('site.webmanifest', $response->getContent());
        $this->assertStringStartsWith($manifest['scope'], parse_url(url()->current(), PHP_URL_PATH) ?? '/');

        // Every shortcut opens for a signed-in user of the most limited role.
        $user = $this->userWithRole('employee');
        foreach ($manifest['shortcuts'] as $shortcut) {
            $this->assertStringStartsWith('/', $shortcut['url']);
            $this->actingAs($user)->followingRedirects()->get($shortcut['url'])->assertOk();
            foreach ($shortcut['icons'] as $icon) {
                $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
            }
        }
    }

    public function test_the_worker_stores_only_public_static_files_and_never_replays_writes(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        // Writes are never handled, so they can never be queued or replayed.
        $this->assertStringContainsString("if (request.method !== 'GET')", $sw);
        $this->assertStringNotContainsString("addEventListener('sync'", $sw);
        $this->assertStringNotContainsString('backgroundFetch', $sw);

        // Pages: network only, offline page as the fallback; a page is never stored.
        $this->assertMatchesRegularExpression("/request\\.mode === 'navigate'\\) \\{\\s*\\/\\/[^\\n]*\\n\\s*event\\.respondWith\\(fetch\\(request\\)\\.catch\\(\\(\\) => caches\\.match\\(OFFLINE_URL\\)\\)\\)/", $sw);
        $this->assertSame(1, substr_count($sw, 'cache.put('), 'only one place stores responses');
        $putAt = strpos($sw, 'cache.put(');
        $this->assertGreaterThan(strpos($sw, 'if (isBuildAsset(url))'), $putAt, 'responses are stored only for build assets');
        $this->assertStringContainsString("url.pathname.startsWith('/build/assets/')", $sw);

        // The precached list is public and static.
        preg_match('/const STATIC = \[(.*?)\];/s', $sw, $m);
        foreach (['employee-photo', 'employee-files', 'livewire', '/ar', '/en', 'ID/'] as $private) {
            $this->assertStringNotContainsString($private, $m[1]);
        }

        // The cache name moved past v1 when the precached files changed, so devices drop the old copy.
        preg_match("/const VERSION = 'hopehr-static-v(\\d+)';/", $sw, $v);
        $this->assertGreaterThanOrEqual(2, (int) ($v[1] ?? 0));
        $this->assertStringContainsString('self.skipWaiting()', $sw);
        $this->assertStringContainsString('self.clients.claim()', $sw);
    }

    public function test_the_web_server_never_long_caches_the_worker_or_the_manifest(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));

        // The year-long immutable rule matches *.js; this override must come after it to win.
        $immutable = strpos($htaccess, 'immutable');
        $override = strpos($htaccess, '<FilesMatch "^(sw\.js|site\.webmanifest)$">');
        $this->assertNotFalse($override);
        $this->assertGreaterThan($immutable, $override);
        $this->assertStringContainsString('Header set Cache-Control "no-cache"', substr($htaccess, $override));
        $this->assertStringContainsString('AddType application/manifest+json .webmanifest', $htaccess);
    }

    public function test_the_offline_page_is_static_bilingual_and_promises_no_replay(): void
    {
        $html = file_get_contents(public_path('offline.html'));

        $this->assertStringContainsString('lang="ar"', $html);
        $this->assertStringContainsString('lang="en"', $html);
        $this->assertStringContainsString('ولا يُرسل لاحقًا', $html);
        $this->assertStringContainsString('nothing is sent later', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('{{', $html);

        // The Hope Center identity, light and dark, clear of the notch and home bar.
        $this->assertStringContainsString('<meta name="theme-color" content="#BE1F24">', $html);
        $this->assertStringContainsString('--primary: #BE1F24', $html);
        foreach (['#c2151c', '#1f2937', '#4b5563', '#e5e7eb', '#fafafa'] as $offBrand) {
            $this->assertStringNotContainsStringIgnoringCase($offBrand, $html);
        }
        $this->assertStringContainsString('prefers-color-scheme: dark', $html);
        $this->assertStringContainsString('env(safe-area-inset-top', $html);
        $this->assertStringContainsString('env(safe-area-inset-bottom', $html);

        // Its icon is one the worker precaches, so it shows with no connection.
        preg_match('/<img src="([^"]+)"/', $html, $img);
        $this->assertStringContainsString("'{$img[1]}'", file_get_contents(public_path('sw.js')));
    }

    public function test_pages_link_the_manifest_and_register_the_worker_with_a_session_fingerprint(): void
    {
        $login = $this->get(route('login'))->getContent();
        $this->assertStringContainsString('site.webmanifest', $login);
        // The browser chrome follows the identity's page colour in each scheme.
        $this->assertStringContainsString('<meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">', $login);
        $this->assertStringContainsString('<meta name="theme-color" content="#161617" media="(prefers-color-scheme: dark)">', $login);
        // Past the HTTP cache, with an update check on load, so a new worker arrives promptly.
        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' })", $login);
        $this->assertStringContainsString('registration.update()', $login);
        // With the CSP nonce, or the browser refuses to run it (found in the browser check).
        $this->assertMatchesRegularExpression('/<script nonce="[^"]+">\s*if \(\'serviceWorker\' in navigator/', $login);
        // Signed out: the worker is told to drop anything it holds.
        $this->assertStringContainsString("const session = 'signed-out'", $login);

        // A role switch gives a different fingerprint, so the device starts clean again.
        $user = $this->userWithRole('employee');
        $first = $this->fingerprint($this->actingAs($user)->get(route('dashboard'))->getContent());
        $hr = Role::findOrCreate('HR-ALP', 'web');
        $user->assignRole($hr);
        $user->fresh()->setActiveRole($hr->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $second = $this->fingerprint($this->actingAs($user->fresh())->get(route('dashboard'))->getContent());

        $this->assertNotSame('signed-out', $first);
        $this->assertNotSame($first, $second);
        // An opaque hash: no id, email or role name reaches the browser's storage.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
    }

    public function test_the_install_prompt_is_captured_early_for_the_install_button(): void
    {
        $login = $this->get(route('login'))->getContent();

        // The contract resources/js/pwa-install.js reads: one nonce'd script, in <head>,
        // before the bundle, that keeps the event and announces each change.
        $this->assertMatchesRegularExpression('/<script nonce="[^"]+">\s*\(function \(\) \{\s*if \(window\.__hopehrInstallCapture\) return;/', $login);
        $capture = strpos($login, "addEventListener('beforeinstallprompt'");
        $this->assertNotFalse($capture);
        $this->assertLessThan(strpos($login, '</head>'), $capture);
        $this->assertLessThan(strpos($login, '/build/'), $capture, 'caught before any module script loads');

        $script = substr($login, $capture, 600);
        $this->assertStringContainsString('e.preventDefault()', $script);
        $this->assertStringContainsString('window.__hopehrInstallPrompt = e', $script);
        $this->assertStringContainsString("new Event('hopehr:installable')", $script);
        $this->assertStringContainsString("addEventListener('appinstalled'", $script);
        $this->assertStringContainsString('window.__hopehrInstallPrompt = null', $script);
        $this->assertStringContainsString("new Event('hopehr:installed')", $script);
        $this->assertSame(1, substr_count($login, "addEventListener('beforeinstallprompt'"));
    }

    public function test_pages_carry_the_ios_home_screen_metas(): void
    {
        $login = $this->get(route('login'))->getContent();

        $this->assertStringContainsString('<meta name="apple-mobile-web-app-capable" content="yes">', $login);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-status-bar-style" content="default">', $login);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-title" content="HopeHR">', $login);
        $this->assertMatchesRegularExpression('/<link rel="apple-touch-icon" sizes="180x180" href="[^"]*apple-touch-icon\.png">/', $login);
    }

    private function fingerprint(string $html): string
    {
        preg_match("/const session = '([^']+)'/", $html, $m);

        return $m[1] ?? '';
    }

    private function userWithRole(string $roleName): User
    {
        $city = City::query()->forceCreate(['slug' => 'ALP', 'name_en' => 'Aleppo', 'name_ar' => 'حلب', 'code' => '001', 'is_active' => true]);
        $employee = Employee::query()->create([
            'city_id' => $city->id, 'first_name_en' => 'Pwa', 'first_name_ar' => 'تطبيق',
            'last_name_en' => 'Tester', 'last_name_ar' => 'اختبار', 'username' => 'pwa.tester', 'is_active' => true,
        ]);
        $user = User::query()->create(['employee_id' => $employee->id, 'email' => 'pwa@example.test', 'password' => 'password']);
        $role = Role::findOrCreate($roleName, 'web');
        $user->assignRole($role);
        $user->setActiveRole($role->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
