<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_added_to_responses(): void
    {
        config([
            'security-headers.hsts.enabled' => true,
            'security-headers.csp.report_only' => true,
        ]);

        $response = $this->get('/up');

        $response
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            // Framed by this site only (the dashboard's workspace), never by another.
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader(
                'Strict-Transport-Security',
                'max-age=63072000; includeSubDomains; preload',
            );

        $policy = $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertNotNull($policy);
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertMatchesRegularExpression("/script-src [^;]*'nonce-[^']+'/", $policy);
        $this->assertStringNotContainsString('upgrade-insecure-requests', $policy);
    }

    public function test_csp_can_be_enforced_after_report_only_rollout(): void
    {
        config([
            'security-headers.hsts.enabled' => false,
            'security-headers.csp.report_only' => false,
        ]);

        $response = $this->get('/up');

        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($policy);
        $this->assertStringContainsString('upgrade-insecure-requests', $policy);
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_every_inline_script_of_the_app_layout_carries_the_csp_nonce(): void
    {
        // Production enforces the policy: an inline script without the nonce never runs
        // there and only says so in the console (seen when opening the leave dialog).
        config(['security-headers.csp.report_only' => false]);

        $response = $this->actingAs($this->employeeUser())->get(route('dashboard'))->assertOk();

        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $nonce);
        $this->assertNotEmpty($nonce[1] ?? null);

        preg_match_all('/<script\b([^>]*)>/i', $response->getContent(), $tags);
        $inline = array_filter($tags[1], fn (string $attributes) => ! str_contains($attributes, 'src='));
        $this->assertNotEmpty($inline);

        foreach ($inline as $attributes) {
            $this->assertStringContainsString('nonce="'.$nonce[1].'"', $attributes, "An inline <script{$attributes}> has no nonce.");
        }

        // The workspace relay: a page in the dashboard's iframe tells the menu badge around it.
        $this->assertMatchesRegularExpression('/<script nonce="[^"]+">\s*document\.addEventListener\(\'livewire:init\', \(\) => \{\s*const relayed/', $response->getContent());
    }

    private function employeeUser(): User
    {
        $city = City::query()->forceCreate(['slug' => 'ALP', 'name_en' => 'Aleppo', 'name_ar' => 'حلب', 'code' => '001', 'is_active' => true]);
        $employee = Employee::query()->create([
            'city_id' => $city->id, 'first_name_en' => 'Csp', 'first_name_ar' => 'سياسة',
            'last_name_en' => 'Tester', 'last_name_ar' => 'اختبار', 'username' => 'csp.tester', 'is_active' => true,
        ]);
        $user = User::query()->create(['employee_id' => $employee->id, 'email' => 'csp@example.test', 'password' => 'password']);
        $role = Role::findOrCreate('employee', 'web');
        $user->assignRole($role);
        $user->setActiveRole($role->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
