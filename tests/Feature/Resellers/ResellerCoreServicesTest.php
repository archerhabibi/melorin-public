<?php

namespace Tests\Feature\Resellers;

use App\Exceptions\ResellerScopeViolationException;
use App\Models\Account;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Resellers\ResellerCustomerService;
use App\Services\Resellers\ResellerPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\StoreMembers;
use Tests\TestCase;

/**
 * پوشش «Tenant Isolation» (بخش ۲۱ سند معماری) در سطح سرویس‌های Core،
 * جدا از مسیر مالی خرید که در ResellerPurchaseFinancialTest پوشش داده شده.
 */
class ResellerCoreServicesTest extends TestCase
{
    use RefreshDatabase;
    use StoreMembers;

    protected ResellerCustomerService $customers;

    protected ResellerPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customers = app(ResellerCustomerService::class);
        $this->pricing = app(ResellerPricingService::class);
    }

    #[Test]
    public function a_customer_of_one_reseller_can_also_be_assigned_to_another(): void
    {
        // Rule 12: قبلاً «مشتریِ نماینده‌ی دیگر» رد می‌شد؛ حالا هر User می‌تواند مشتری چند نماینده باشد
        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();
        $customer = $this->memberOf($resellerA);

        $this->customers->assign($resellerB, $customer);

        $this->assertTrue($this->customers->ownsCustomer($resellerA, $customer));
        $this->assertTrue($this->customers->ownsCustomer($resellerB, $customer));
        $this->assertEquals(2, $customer->customerAccounts()->count());
    }

    #[Test]
    public function assigning_the_same_customer_to_the_same_reseller_twice_is_idempotent(): void
    {
        $reseller = Reseller::factory()->create();
        $customer = User::factory()->create();

        $this->customers->assign($reseller, $customer);
        $this->customers->assign($reseller, $customer);

        $this->assertEquals(1, $customer->customerAccounts()->count());
        $this->assertTrue($this->customers->ownsCustomer($reseller, $customer));
    }

    #[Test]
    public function a_disabled_membership_cannot_be_silently_reactivated_by_assign(): void
    {
        $reseller = Reseller::factory()->create();
        $customer = $this->memberOf($reseller);

        $this->customers->remove($reseller, $customer);

        $this->assertFalse($this->customers->ownsCustomer($reseller, $customer));

        $this->expectException(ResellerScopeViolationException::class);
        $this->customers->assign($reseller, $customer);
    }

    #[Test]
    public function reseller_a_cannot_remove_a_customer_belonging_to_reseller_b(): void
    {
        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();
        $customerOfB = $this->memberOf($resellerB);

        try {
            $this->customers->remove($resellerA, $customerOfB);
            $this->fail('باید نقض Scope اعلام می‌شد');
        } catch (ResellerScopeViolationException) {
        }

        $this->assertTrue($this->customers->ownsCustomer($resellerB, $customerOfB), 'عضویت B دست‌نخورده می‌ماند');
    }

    #[Test]
    public function removing_a_customer_disables_only_this_stores_membership_and_keeps_the_wallet(): void
    {
        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();
        $customer = $this->memberOf($resellerA);
        $this->accountIn($customer, $resellerB);

        $wallets = app(\App\Services\Core\WalletService::class);
        $wallets->credit($this->accountIn($customer, $resellerA), 75);

        $this->customers->remove($resellerA, $customer);

        $this->assertFalse($this->customers->ownsCustomer($resellerA, $customer));
        $this->assertTrue($this->customers->ownsCustomer($resellerB, $customer));
        $this->assertEquals(75, $wallets->balanceIn($customer, \App\Services\Core\Store\StoreContext::reseller($resellerA)));
    }

    #[Test]
    public function customers_query_never_leaks_across_resellers(): void
    {
        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();
        foreach (range(1, 3) as $_) {
            $this->memberOf($resellerA);
        }
        foreach (range(1, 2) as $_) {
            $this->memberOf($resellerB);
        }

        $this->assertCount(3, $this->customers->customersQuery($resellerA)->get());
        $this->assertCount(2, $this->customers->customersQuery($resellerB)->get());
        $this->assertEquals(3, $resellerA->customers()->count());
        $this->assertEquals(2, $resellerB->customers()->count());
    }

    #[Test]
    public function order_and_account_scopes_never_leak_across_resellers(): void
    {
        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();

        $orderA = Order::factory()->create(['reseller_id' => $resellerA->id]);
        $orderB = Order::factory()->create(['reseller_id' => $resellerB->id]);

        $accountA = Account::factory()->create(['order_id' => $orderA->id, 'user_id' => $orderA->user_id]);
        Account::factory()->create(['order_id' => $orderB->id, 'user_id' => $orderB->user_id]);

        $this->assertTrue(Order::query()->ofReseller($resellerA->id)->pluck('id')->contains($orderA->id));
        $this->assertFalse(Order::query()->ofReseller($resellerA->id)->pluck('id')->contains($orderB->id));

        $scopedAccounts = Account::query()->ofReseller($resellerA->id)->pluck('id');
        $this->assertTrue($scopedAccounts->contains($accountA->id));
        $this->assertCount(1, $scopedAccounts);
    }

    #[Test]
    public function customers_price_below_reseller_price_is_rejected(): void
    {
        $reseller = Reseller::factory()->create();
        $product = Product::factory()->create(['main_price' => 100000]);

        $this->expectException(InvalidArgumentException::class);
        $this->pricing->setCustomersPrice($reseller, $product, 90000);
    }

    #[Test]
    public function central_min_max_price_and_profit_rules_are_enforced(): void
    {
        $reseller = Reseller::factory()->create([
            'min_sale_price_rule' => ['min_price' => 110000, 'max_price' => 200000, 'max_profit' => 50000],
        ]);
        $product = Product::factory()->create(['main_price' => 100000]);

        // زیر حداقل قیمت
        $this->assertThrows(fn () => $this->pricing->setCustomersPrice($reseller, $product, 105000), InvalidArgumentException::class);
        // بالای سقف سود (100000 پایه + 50000 سقف سود = 150000)
        $this->assertThrows(fn () => $this->pricing->setCustomersPrice($reseller, $product, 160000), InvalidArgumentException::class);
        // مقدار مجاز
        $setting = $this->pricing->setCustomersPrice($reseller, $product, 130000);
        $this->assertEquals(130000, $setting->customers_price);
        $this->assertTrue($setting->is_enabled);
    }

    #[Test]
    public function disabling_a_product_makes_it_unsellable_again(): void
    {
        $reseller = Reseller::factory()->create();
        $product = Product::factory()->create(['main_price' => 100000, 'status' => 'active']);

        $this->pricing->setCustomersPrice($reseller, $product, 130000);
        $this->assertTrue($this->pricing->isSellable($reseller, $product));

        $this->pricing->disable($reseller, $product);
        $this->assertFalse($this->pricing->isSellable($reseller, $product));
    }

    #[Test]
    public function sellable_products_excludes_globally_inactive_products_even_if_reseller_enabled_them(): void
    {
        $reseller = Reseller::factory()->create();
        $activeProduct = Product::factory()->create(['main_price' => 100000, 'status' => 'active']);
        $inactiveProduct = Product::factory()->create(['main_price' => 100000, 'status' => 'inactive']);

        $this->pricing->setCustomersPrice($reseller, $activeProduct, 120000);
        $this->pricing->setCustomersPrice($reseller, $inactiveProduct, 120000);

        $ids = $this->pricing->sellableProducts($reseller)->pluck('id');
        $this->assertTrue($ids->contains($activeProduct->id));
        $this->assertFalse($ids->contains($inactiveProduct->id));
    }
}
