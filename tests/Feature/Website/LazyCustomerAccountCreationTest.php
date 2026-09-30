<?php

namespace Tests\Feature\Website;

use App\Models\CustomerAccount;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * پچ 3.2.17 - تصمیم صریح صاحب پروژه، override تحلیل قبلی نفر 3
 * (docs/PHASE-W5-PART2-COMPLETION-AND-SECURITY-NOTE.md): «تا خرید
 * انجام نشود نباید CustomerAccount جدید بسازد».
 * مرجع: docs/PHASE-W5-PART3-LAZY-CUSTOMER-ACCOUNT.md
 */
class LazyCustomerAccountCreationTest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    #[Test]
    public function visiting_orders_wallet_and_accounts_pages_never_creates_a_customer_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('website.orders.index'))->assertOk();
        $this->actingAs($user)->get(route('website.wallet.show'))->assertOk();
        $this->actingAs($user)->get(route('website.accounts.index'))->assertOk();
        $this->actingAs($user)->get(route('website.referral.show'))->assertOk();

        $this->assertEquals(0, CustomerAccount::query()->count());
    }

    #[Test]
    public function visiting_a_reseller_store_as_a_logged_in_customer_never_creates_a_customer_account(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active']);
        $user = User::factory()->create();

        // این دقیقا همان سناریوی «بازدید بدون قصد خرید» است که سؤال
        // امنیتی نفر 3 را ایجاد کرد - یک GET ساده به یک صفحه‌ی
        // auth-only زیر فروشگاه یک نماینده که کاربر هیچ ارتباط قبلی‌ای
        // با آن ندارد.
        $this->actingAs($user)
            ->get(route('website.store.orders.index', $reseller->slug))
            ->assertOk();

        $this->assertEquals(0, CustomerAccount::query()->count());
        $this->assertNull(
            app(IdentityService::class)->findCustomerAccount($user, StoreContext::reseller($reseller))
        );
    }

    #[Test]
    public function a_customer_account_is_created_only_at_the_moment_of_an_actual_checkout(): void
    {
        $this->fakeSanaeiPanel();
        $product = $this->makeSellableProduct(mainPrice: 50000);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('website.checkout.show', $product->id))->assertOk();
        $this->assertEquals(0, CustomerAccount::query()->count(), 'GET checkout نباید چیزی بسازد.');

        // شارژ کیف‌پول مستقل از CustomerAccount است (Wallet با
        // user_id+scope_key کلید می‌خورد، نه customer_account_id) - پس
        // می‌توانیم بدون ساختن دائمی یک CustomerAccount، موجودی را
        // برای تست آماده کنیم.
        app(WalletService::class)->credit(
            app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main()),
            100000
        );
        // SoftDeletes: delete() فقط علامت می‌زند و ردیف هنوز در unique index
        // (user_id + scope) می‌ماند؛ برای پاک‌سازی واقعی forceDelete لازم است.
        CustomerAccount::withTrashed()->forceDelete();

        $this->actingAs($user)->post(route('website.checkout.store', $product->id), [
            'idempotency_token' => 'lazy-test-1',
        ]);

        $this->assertEquals(1, CustomerAccount::query()->count(), 'POST خرید باید دقیقا یکی بسازد.');
    }
}
