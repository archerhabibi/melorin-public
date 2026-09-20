<?php

namespace Tests\Feature\Store;

use App\Models\CustomerAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بند ۶۶ بلوپرینت (Migration Tests).
 *
 * تست‌های بالا ثابت می‌کنند معماری جدید درست کار می‌کند. این یکی چیز
 * دیگری را ثابت می‌کند که به‌مراتب خطرناک‌تر است اگر غلط باشد: این‌که
 * داده‌ی واقعیِ امروزِ سرور، بعد از مهاجرت به مالک درست وصل می‌شود.
 *
 * روش کار: داده را دقیقاً به شکل «معماری قدیم» می‌سازیم (بدون
 * customer_account_id)، بعد همان منطق backfill را اجرا می‌کنیم و نتیجه
 * را می‌سنجیم — چون RefreshDatabase قبلاً migration را روی دیتابیس
 * خالی اجرا کرده و خودش چیزی برای backfill پیدا نمی‌کند.
 */
class CustomerAccountBackfillTest extends TestCase
{
    use RefreshDatabase;

    /**
     * migration مربوط به backfill را دوباره روی داده‌ی تستی اجرا می‌کند.
     */
    protected function runBackfill(): void
    {
        $seed = require database_path('migrations/2026_09_18_000003_backfill_customer_accounts_from_users.php');
        $seed->up();

        $ownership = require database_path('migrations/2026_09_18_000004_backfill_customer_account_ownership.php');
        $ownership->up();
    }

    #[Test]
    public function every_legacy_user_receives_a_customer_account_in_the_right_store(): void
    {
        $reseller = Reseller::factory()->create();

        $mainUser = User::factory()->create(['reseller_id' => null]);
        $resellerUser = User::factory()->create(['reseller_id' => $reseller->id]);

        // شبیه‌سازی وضعیت قبل از مهاجرت
        CustomerAccount::query()->forceDelete();

        $this->runBackfill();

        $mainAccount = CustomerAccount::where('user_id', $mainUser->id)->firstOrFail();
        $resellerAccount = CustomerAccount::where('user_id', $resellerUser->id)->firstOrFail();

        $this->assertEquals('main', $mainAccount->store_type);
        $this->assertNull($mainAccount->reseller_id);

        $this->assertEquals('reseller', $resellerAccount->store_type);
        $this->assertEquals($reseller->id, $resellerAccount->reseller_id);
    }

    // فاز ۱۵: دو تست backfill کیف‌پول (legacy owner_type/customer_account_id) حذف شدند چون
    // آن ستون‌ها دیگر وجود ندارند. منطق نگاشت/ادغام Walletهای قدیمی در
    // WalletContextStructureTest (با بازسازیِ موقتِ ستون‌های legacy) تست می‌شود.

    #[Test]
    public function orders_are_assigned_to_the_store_they_were_placed_in(): void
    {
        $reseller = Reseller::factory()->create();
        $user = User::factory()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->create();

        $mainOrder = Order::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'reseller_id' => null,
            'sales_channel' => 'main_bot',
            'main_price' => 100000,
            'status' => 'account_created',
        ]);

        $resellerOrder = Order::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'reseller_id' => $reseller->id,
            'sales_channel' => 'reseller_bot',
            'reseller_price' => 70000,
            'customers_price' => 90000,
            'status' => 'account_created',
        ]);

        CustomerAccount::query()->forceDelete();
        DB::table('orders')->update(['customer_account_id' => null]);

        $this->runBackfill();

        $mainOrder->refresh();
        $resellerOrder->refresh();

        // همان کاربر، دو سفارش، دو فروشگاه، دو مالک متفاوت — دقیقاً
        // چیزی که معماری قدیم قادر به نمایشش نبود.
        $this->assertNotEquals($mainOrder->customer_account_id, $resellerOrder->customer_account_id);

        $this->assertEquals('main', $mainOrder->customerAccount->store_type);
        $this->assertEquals($reseller->id, $resellerOrder->customerAccount->reseller_id);
    }

    #[Test]
    public function running_the_backfill_twice_changes_nothing(): void
    {
        $user = User::factory()->create(['reseller_id' => null]);

        CustomerAccount::query()->forceDelete();

        $this->runBackfill();
        $countAfterFirst = CustomerAccount::count();

        $this->runBackfill();

        $this->assertEquals($countAfterFirst, CustomerAccount::count());
        $this->assertEquals(1, CustomerAccount::where('user_id', $user->id)->count());
    }
}
