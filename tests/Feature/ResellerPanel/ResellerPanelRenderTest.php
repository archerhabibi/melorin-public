<?php

namespace Tests\Feature\ResellerPanel;

use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Admin;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function the_reseller_dashboard_renders_fully_without_error(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $owner = $this->ownerOf($reseller);

        $response = $this->actingAs($owner, 'reseller')->get('/arial');

        $response->assertOk();
    }

    #[Test]
    public function the_reseller_products_page_renders_fully_without_error(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $owner = $this->ownerOf($reseller);

        $response = $this->actingAs($owner, 'reseller')->get('/arial/products');

        $response->assertOk();
    }

    #[Test]
    public function a_user_with_no_full_name_still_renders_the_panel_correctly(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'noname']);
        $owner = $reseller->user;

        $owner->update(['full_name' => null]);
        $this->ownerOf($reseller);

        $response = $this->actingAs($owner, 'reseller')->get('/noname');

        $response->assertOk();
    }

    #[Test]
    public function admin_can_set_a_products_reseller_price_from_the_main_admin_panel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        $product = Product::factory()->create([
            'main_price' => 150000,
            'reseller_price' => null,
            'status' => 'active',
        ]);

        Livewire::test(EditProduct::class, [
            'record' => $product->getRouteKey(),
        ])
            ->fillForm([
                'reseller_price' => 100000,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(
            100000,
            (int) $product->fresh()->reseller_price
        );
    }
}
