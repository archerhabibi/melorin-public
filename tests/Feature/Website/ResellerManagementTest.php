<?php

namespace Tests\Feature\Website;

use App\Models\Category;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\ResellerProductPrice;
use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reseller Management سبک روی وب.
 * Website فقط UI است؛ هر قاعده (سقف/کفِ قیمت، سودِ مجاز، Scope) از
 * Core می‌آید و این تست‌ها فقط ثابت می‌کنند که UI آن را دور نمی‌زند.
 */
class ResellerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function adminOf(Reseller $reseller): User
    {
        $user = User::factory()->create();
        ResellerAdmin::create(['reseller_id' => $reseller->id, 'user_id' => $user->id, 'role' => 'owner']);

        return $user;
    }

    protected function product(int $resellerPrice = 100000): Product
    {
        $category = Category::factory()->create(['status' => 'active']);

        return Product::factory()->create([
            'category_id' => $category->id,
            'main_price' => 150000,
            'reseller_price' => $resellerPrice,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function an_admin_sees_only_their_own_stores_customers_with_the_store_scoped_wallet(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $admin = $this->adminOf($reseller);
        $identity = app(IdentityService::class);
        $wallet = app(WalletService::class);

        $mine = User::factory()->create(['full_name' => 'مشتری-من']);
        $identity->resolveCustomerAccount($mine, StoreContext::reseller($reseller));
        $wallet->credit($identity->resolveCustomerAccount($mine, StoreContext::reseller($reseller)), 55000);
        // همان مشتری در Main موجودی دیگری دارد که نباید نمایش داده شود
        $wallet->credit($identity->resolveCustomerAccount($mine, StoreContext::main()), 999999);

        $theirs = User::factory()->create(['full_name' => 'مشتری-دیگری']);
        $identity->resolveCustomerAccount($theirs, StoreContext::reseller($other));

        $this->actingAs($admin)
            ->get(route('website.store.manage.customers', $reseller->slug))
            ->assertOk()
            ->assertSee('مشتری-من')
            ->assertSee(number_format(55000))
            ->assertDontSee(number_format(999999))
            ->assertDontSee('مشتری-دیگری');
    }

    #[Test]
    public function an_admin_can_set_a_valid_price_which_enables_the_product(): void
    {
        $reseller = Reseller::factory()->create();
        $admin = $this->adminOf($reseller);
        $product = $this->product(resellerPrice: 100000);

        $this->actingAs($admin)
            ->post(route('website.store.manage.products.price', [$reseller->slug, $product->id]), ['customers_price' => 130000])
            ->assertSessionHas('status');

        $this->assertEquals(130000, $product->fresh()->customersPrice($reseller->fresh()));
    }

    #[Test]
    public function a_price_below_the_wholesale_price_is_rejected_by_core_and_not_saved(): void
    {
        $reseller = Reseller::factory()->create();
        $admin = $this->adminOf($reseller);
        $product = $this->product(resellerPrice: 100000);

        $this->actingAs($admin)
            ->post(route('website.store.manage.products.price', [$reseller->slug, $product->id]), ['customers_price' => 90000])
            ->assertSessionHasErrors('customers_price');

        $this->assertNull(ResellerProductPrice::query()->where('reseller_id', $reseller->id)->first());
    }

    #[Test]
    public function an_admin_can_disable_and_re_enable_a_product_keeping_the_previous_price(): void
    {
        $reseller = Reseller::factory()->create();
        $admin = $this->adminOf($reseller);
        $product = $this->product(resellerPrice: 100000);

        $this->actingAs($admin)->post(route('website.store.manage.products.price', [$reseller->slug, $product->id]), ['customers_price' => 130000]);
        $this->actingAs($admin)->post(route('website.store.manage.products.disable', [$reseller->slug, $product->id]))->assertSessionHas('status');

        $this->assertFalse(ResellerProductPrice::query()->where('reseller_id', $reseller->id)->firstOrFail()->is_enabled);

        $this->actingAs($admin)->post(route('website.store.manage.products.enable', [$reseller->slug, $product->id]))->assertSessionHas('status');

        $row = ResellerProductPrice::query()->where('reseller_id', $reseller->id)->firstOrFail();
        $this->assertTrue($row->is_enabled);
        $this->assertEquals(130000, (int) $row->customers_price);
    }

    #[Test]
    public function a_disabled_product_still_shows_its_stored_price_and_an_enable_button(): void
    {
        $reseller = Reseller::factory()->create();
        $admin = $this->adminOf($reseller);
        $product = $this->product(resellerPrice: 100000);

        $this->actingAs($admin)->post(route('website.store.manage.products.price', [$reseller->slug, $product->id]), ['customers_price' => 130000]);
        $this->actingAs($admin)->post(route('website.store.manage.products.disable', [$reseller->slug, $product->id]));

        $this->actingAs($admin)
            ->get(route('website.store.manage.products', $reseller->slug))
            ->assertOk()
            ->assertSee('value="130000"', false)
            ->assertSee('فعال کردن')
            ->assertDontSee('غیرفعال کردن');
    }

    #[Test]
    public function enabling_a_product_that_never_had_a_price_asks_for_one(): void
    {
        $reseller = Reseller::factory()->create();
        $admin = $this->adminOf($reseller);
        $product = $this->product();

        $this->actingAs($admin)
            ->post(route('website.store.manage.products.enable', [$reseller->slug, $product->id]))
            ->assertSessionHasErrors('product');
    }

    #[Test]
    public function an_admin_of_another_reseller_cannot_manage_this_store(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $foreignAdmin = $this->adminOf($other);
        $product = $this->product();

        $this->actingAs($foreignAdmin)->get(route('website.store.manage.customers', $reseller->slug))->assertForbidden();
        $this->actingAs($foreignAdmin)->get(route('website.store.manage.products', $reseller->slug))->assertForbidden();
        $this->actingAs($foreignAdmin)
            ->post(route('website.store.manage.products.price', [$reseller->slug, $product->id]), ['customers_price' => 130000])
            ->assertForbidden();

        $this->assertNull(ResellerProductPrice::query()->where('reseller_id', $reseller->id)->first());
    }

    #[Test]
    public function a_guest_is_redirected_to_login_from_management_pages(): void
    {
        $reseller = Reseller::factory()->create();

        $this->get(route('website.store.manage.customers', $reseller->slug))->assertRedirect();
        $this->get(route('website.store.manage.products', $reseller->slug))->assertRedirect();
    }

    #[Test]
    public function a_plain_customer_cannot_reach_any_management_page(): void
    {
        $reseller = Reseller::factory()->create();
        $customer = User::factory()->create();
        $product = $this->product();

        foreach (['customers', 'products', 'branding'] as $page) {
            $this->actingAs($customer)->get(route("website.store.manage.{$page}", $reseller->slug))->assertForbidden();
        }

        $this->actingAs($customer)->post(route('website.store.manage.products.disable', [$reseller->slug, $product->id]))->assertForbidden();
    }

    #[Test]
    public function the_products_page_lists_wholesale_and_retail_prices_of_eligible_products_only(): void
    {
        $reseller = Reseller::factory()->create();
        $admin = $this->adminOf($reseller);

        $eligible = $this->product(resellerPrice: 100000);
        $eligible->update(['name' => 'محصول-قابل-مدیریت']);

        $closedCategory = Category::factory()->create(['status' => 'active', 'available_to_resellers' => false]);
        Product::factory()->create(['category_id' => $closedCategory->id, 'name' => 'محصول-دسته-بسته', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('website.store.manage.products', $reseller->slug))
            ->assertOk()
            ->assertSee('محصول-قابل-مدیریت')
            ->assertSee(number_format(100000))
            ->assertDontSee('محصول-دسته-بسته');
    }
}
