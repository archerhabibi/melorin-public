<?php

namespace Tests\Feature\ResellerPanel;

use App\Filament\Resources\ResellerResource\Pages\EditReseller;
use App\Filament\Resources\ResellerResource\RelationManagers\ProductPricesRelationManager;
use App\Models\Admin;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\User;
use App\Services\Resellers\ResellerPricingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * باگ گزارش‌شده («Filament\FilamentManager::getUserName(): Return
 * value must be of type string, null returned») فقط هنگام رندر کامل
 * صفحه (layout → topbar → user-menu → avatar) رخ می‌داد — نه در تست‌های
 * قبلیِ Livewire::test() که فقط خودِ Table/Resource را جدا از
 * Layout رندر می‌کنند. برای همین این‌بار یک درخواست HTTP واقعی و کامل
 * به آدرس پنل زده می‌شود؛ این دقیقاً همان چیزی است که آن باگ قبلاً از
 * دستمان در رفته بود.
 */
class ResellerPanelRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function ownerOf(Reseller $reseller): User
    {
        $owner = $reseller->user;
        ResellerAdmin::query()->firstOrCreate(
            ['reseller_id' => $reseller->id, 'user_id' => $owner->id],
            ['role' => 'owner']
        );

        return $owner;
    }

    /** @test */
    public function the_reseller_dashboard_renders_fully_without_error(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $owner = $this->ownerOf($reseller);

        $response = $this->actingAs($owner, 'reseller')->get('/arial');

        $response->assertOk();
    }

    /** @test */
    public function the_reseller_products_page_renders_fully_without_error(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $owner = $this->ownerOf($reseller);

        $response = $this->actingAs($owner, 'reseller')->get('/arial/products');

        $response->assertOk();
    }

    /** @test */
    public function a_user_with_no_full_name_still_renders_the_panel_correctly(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'noname']);
        $owner = $reseller->user;
        $owner->update(['full_name' => null]);
        $this->ownerOf($reseller);

        $response = $this->actingAs($owner, 'reseller')->get('/noname');

        $response->assertOk();
    }

    /** @test */
    public function admin_can_set_a_resellers_product_price_from_the_main_admin_panel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        $reseller = Reseller::factory()->create();
        $product = Product::factory()->create(['price' => 100000, 'status' => 'active']);

        Livewire::test(ProductPricesRelationManager::class, ['ownerRecord' => $reseller, 'pageClass' => EditReseller::class])
            ->callTableAction('set_price', $product, data: ['selling_price' => 135000])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(135000, $product->fresh()->sellingPriceForReseller($reseller));
        $this->assertTrue(app(ResellerPricingService::class)->isSellable($reseller, $product));
    }
}
