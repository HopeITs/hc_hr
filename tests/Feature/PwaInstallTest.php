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
        $this->assertSame('#c2151c', $manifest['theme_color']);

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
    }

    public function test_pages_link_the_manifest_and_register_the_worker_with_a_session_fingerprint(): void
    {
        $login = $this->get(route('login'))->getContent();
        $this->assertStringContainsString('site.webmanifest', $login);
        $this->assertStringContainsString('<meta name="theme-color" content="#c2151c">', $login);
        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js')", $login);
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
