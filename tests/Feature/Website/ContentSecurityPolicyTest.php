<?php

namespace Tests\Feature\Website;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CSP header.
 * مرجع: docs/history/PHASE-W6-PART1-CSP.md
 */
class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function website_pages_send_a_content_security_policy_header(): void
    {
        $response = $this->get(route('website.home'));

        $response->assertHeader('Content-Security-Policy');
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString('https://telegram.org', $csp);
    }

    #[Test]
    public function reseller_website_pages_also_get_the_csp_header(): void
    {
        $reseller = \App\Models\Reseller::factory()->create();

        $this->get(route('website.store.home', $reseller->slug))
            ->assertHeader('Content-Security-Policy');
    }
}
