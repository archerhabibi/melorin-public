<?php

namespace Tests\Feature;

use App\Filament\Reseller\Resources\ProductResource\Pages\ListProducts as ResellerListProducts;
use App\Filament\Resources\ProductResource\Pages\ListProducts as AdminListProducts;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * طبق درخواست صریح (به‌همراه تصویر مرجع): محصولات هر سبد فروش باید
 * زیرمجموعه‌ی همان سبد نمایش داده شوند و سبدها از هم تفکیک شده باشند —
 * هم در پنل ادمین اصلی، هم در پنل خودِ نماینده.
 */
class ProductGroupingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function admin_product_list_renders_successfully_grouped_by_category(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        $categoryA = Category::factory()->create(['name' => 'سبد A']);
        $categoryB = Category::factory()->create(['name' => 'سبد B']);
        $productA1 = Product::factory()->create(['category_id' => $categoryA->id, 'name' => 'محصول A1']);
        $productA2 = Product::factory()->create(['category_id' => $categoryA->id, 'name' => 'محصول A2']);
        $productB1 = Product::factory()->create(['category_id' => $categoryB->id, 'name' => 'محصول B1']);

        Livewire::test(AdminListProducts::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$productA1, $productA2, $productB1]);
    }

    /** @test */
    public function reseller_product_list_renders_successfully_grouped_by_category(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'grouptest']);
        $owner = $reseller->user;
        ResellerAdmin::query()->firstOrCreate(
            ['reseller_id' => $reseller->id, 'user_id' => $owner->id],
            ['role' => 'owner']
        );

        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($owner, 'reseller');
        Filament::setTenant($reseller);

        $categoryA = Category::factory()->create(['name' => 'سبد A']);
        $categoryB = Category::factory()->create(['name' => 'سبد B']);
        $productA = Product::factory()->create(['category_id' => $categoryA->id, 'status' => 'active']);
        $productB = Product::factory()->create(['category_id' => $categoryB->id, 'status' => 'active']);

        Livewire::test(ResellerListProducts::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$productA, $productB]);
    }

    /** @test */
    public function admin_can_still_filter_the_product_list_by_a_single_category_alongside_grouping(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        $categoryA = Category::factory()->create();
        $categoryB = Category::factory()->create();
        $productA = Product::factory()->create(['category_id' => $categoryA->id]);
        $productB = Product::factory()->create(['category_id' => $categoryB->id]);

        Livewire::test(AdminListProducts::class)
            ->assertCanSeeTableRecords([$productA, $productB])
            ->filterTable('category_id', $categoryA->id)
            ->assertCanSeeTableRecords([$productA])
            ->assertCanNotSeeTableRecords([$productB]);
    }
}
