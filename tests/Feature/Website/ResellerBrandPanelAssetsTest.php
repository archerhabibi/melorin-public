<?php

namespace Tests\Feature\Website;

use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\ResellerWebsiteSetting;
use App\Services\Resellers\Branding\StoreBrandResolver;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** B6.2 — پنل نماینده همان لوگوی تیره و Favicon وب را می‌گیرد (و هیچ‌کدام بین فروشگاه‌ها نشت نمی‌کند). */
class ResellerBrandPanelAssetsTest extends TestCase
{
    use RefreshDatabase;

    private function panelFor(Reseller $reseller): Panel
    {
        $panel = Filament::getPanel('reseller');
        Filament::setCurrentPanel($panel);

        ResellerAdmin::query()->firstOrCreate(['reseller_id' => $reseller->id, 'user_id' => $reseller->user->id], ['role' => 'owner']);
        $this->actingAs($reseller->user, 'reseller');
        Filament::setTenant($reseller->fresh());

        return $panel;
    }

    #[Test]
    public function without_assets_the_panel_has_no_dark_logo_and_no_favicon(): void
    {
        $panel = $this->panelFor(Reseller::factory()->create());

        $this->assertNull($panel->getDarkModeBrandLogo());
        $this->assertNull($panel->getFavicon());
    }

    #[Test]
    public function the_panel_uses_the_dark_logo_and_favicon_of_the_current_tenant(): void
    {
        $a = Reseller::factory()->create();
        $b = Reseller::factory()->create();
        ResellerWebsiteSetting::create([
            'reseller_id' => $a->id, 'logo_path' => 'reseller-logos/a/l.png',
            'logo_dark_path' => 'reseller-logos/a/d.png', 'favicon_path' => 'reseller-favicons/a/f.png',
        ]);
        app(StoreBrandResolver::class)->forget();

        $panelA = $this->panelFor($a);
        $this->assertStringContainsString('reseller-logos/a/d.png', (string) $panelA->getDarkModeBrandLogo());
        $this->assertStringContainsString('reseller-favicons/a/f.png', (string) $panelA->getFavicon());

        $panelB = $this->panelFor($b);
        $this->assertNull($panelB->getDarkModeBrandLogo());
        $this->assertNull($panelB->getFavicon());
    }

    #[Test]
    public function an_orphan_dark_logo_without_a_main_logo_is_not_given_to_the_panel(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'logo_dark_path' => 'reseller-logos/x/d.png']);

        $this->assertNull($this->panelFor($reseller)->getDarkModeBrandLogo());
    }
}
