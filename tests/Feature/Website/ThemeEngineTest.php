<?php

namespace Tests\Feature\Website;

use App\Models\Reseller;
use App\Models\ResellerWebsiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B1.4 — Theme Engine: Light/Dark + Branding نماینده.
 */
class ThemeEngineTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_layout_loads_the_theme_init_script_from_self_and_has_a_toggle(): void
    {
        $this->get(route('website.home'))
            ->assertOk()
            ->assertSee('js/theme-init.js', false)
            ->assertSee('data-theme-toggle', false);
    }

    #[Test]
    public function the_layout_has_no_inline_script_so_the_csp_stays_strict(): void
    {
        $html = $this->get(route('website.home'))->getContent();

        $this->assertSame(0, preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $html), 'inline <script> found');
    }

    #[Test]
    public function a_light_reseller_brand_gets_dark_text_on_brand_buttons(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'yellow-shop']);
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'brand_color' => '#ffff00']);

        $this->get(route('website.store.home', $reseller->slug))
            ->assertOk()
            ->assertSee('--brand: #ffff00', false)
            ->assertSee('--brand-contrast: #111827', false);
    }

    #[Test]
    public function the_default_brand_keeps_white_text(): void
    {
        $this->get(route('website.home'))
            ->assertSee('--brand-contrast: #ffffff', false);
    }

    #[Test]
    public function website_views_no_longer_use_raw_gray_or_white_utilities_or_inline_brand_styles(): void
    {
        $offenders = [];
        foreach (glob(resource_path('views/website/{,*/,*/*/}*.blade.php'), GLOB_BRACE) as $file) {
            $src = file_get_contents($file);
            if (preg_match('/\b(bg-white|text-gray-\d+|border-gray-\d+|bg-gray-\d+|bg-red-50|bg-green-50)\b|style="(?:background|color): var\(--brand\)"/', $src)) {
                $offenders[] = str_replace(resource_path('views/'), '', $file);
            }
        }

        $this->assertSame([], $offenders, 'Dark mode needs tokens only (see docs/design-system).');
    }
}
