<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
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
            ->assertHeader('X-Frame-Options', 'DENY')
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
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertMatchesRegularExpression("/script-src [^;]*'nonce-[^']+'/", $policy);
    }

    public function test_csp_can_be_enforced_after_report_only_rollout(): void
    {
        config([
            'security-headers.hsts.enabled' => false,
            'security-headers.csp.report_only' => false,
        ]);

        $response = $this->get('/up');

        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
        $response->assertHeaderMissing('Strict-Transport-Security');
    }
}
