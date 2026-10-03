<?php

namespace Tests\Feature\Website;

use App\Models\Reseller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B1.2 — قرارداد Layout مشترک Website (Customer Layout).
 */
class LayoutContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_main_layout_is_rtl_persian_and_has_an_accessible_skip_link_and_main_landmark(): void
    {
        $this->get(route('website.home'))
            ->assertOk()
            ->assertSee('lang="fa" dir="rtl"', false)
            ->assertSee('href="#main"', false)
            ->assertSee('id="main"', false);
    }

    #[Test]
    public function the_reseller_store_uses_the_same_layout_shell_and_keeps_the_brand_variable(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'layout-shop']);

        $this->get(route('website.store.home', $reseller->slug))
            ->assertOk()
            ->assertSee('id="main"', false)
            ->assertSee('--brand: #2563eb', false);
    }

    #[Test]
    public function the_layout_does_not_load_any_external_font_or_script_host(): void
    {
        $html = $this->get(route('website.home'))->getContent();

        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.bunny.net', $html);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
    }
}
