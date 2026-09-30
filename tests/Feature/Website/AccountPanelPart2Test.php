<?php

namespace Tests\Feature\Website;

use App\Models\Account;
use App\Models\Category;
use App\Models\Commission;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پچ 3.2.5 — فاز W4 بخش دوم (نفر ۲: پنل کاربری). مرجع:
 * docs/PHASE-W4-PART2-RENEWAL-REFERRAL.md
 *
 * طبق تصمیم صریح («همه دقیقاً همانند Core و ربات تلگرام باید باشند؛
 * Refund/Retry کاملاً Admin-only می‌مانند»):
 *   - Renewal اینجا دقیقاً هم‌رفتار با
 *     Tests\Feature\TelegramBot\RenewAccountFlowTest است (همان
 *     Http::fake، همان کیف‌پول، همان پیام‌های خطا).
 *   - Refund/Retry به‌عمد تستی اینجا ندارند چون هیچ Route/دکمه‌ای
 *     برایشان اضافه نشده — رفتار «بدون دکمه» را همان تست‌های فهرست
 *     سفارش‌ها (AccountPanelTest، فاز ۱) که برچسب وضعیت را بررسی
 *     می‌کنند از قبل پوشش می‌دهند.
 */
class AccountPanelPart2Test extends TestCase
{
    use RefreshDatabase;

    protected function makeAccount(User $user, float $price = 100000): Account
    {
        $panel = ServerPanel::factory()->create(['panel_type' => 'marzban']);
        $category = Category::factory()->create();
        $category->serverPanels()->attach($panel);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'main_price' => $price,
        ]);

        $customer = app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());

        return Account::factory()->create([
            'user_id' => $user->id,
            'customer_account_id' => $customer->id,
            'product_id' => $product->id,
            'server_panel_id' => $panel->id,
            'panel_username' => 'melorin_existing',
            'status' => 'active',
            'expires_at' => now()->addDays(5),
        ]);
    }

    protected function mainCustomer(User $user)
    {
        return app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());
    }

    // --- Renewal (بند ۴) ---

    #[Test]
    public function renewing_with_sufficient_balance_deducts_wallet_and_extends_expiry(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user/*' => Http::response(['username' => 'melorin_existing'], 200),
        ]);

        $user = User::factory()->create();
        $account = $this->makeAccount($user, 100000);
        app(WalletService::class)->charge($this->mainCustomer($user), 150000);

        $response = $this->actingAs($user)->post(route('website.accounts.renew', $account->id));

        $response->assertRedirect(route('website.accounts.show', $account->id));
        $response->assertSessionHas('renewal_success');

        $this->assertEquals(50000, app(WalletService::class)->balance($this->mainCustomer($user)));
        $this->assertTrue($account->fresh()->expires_at->isAfter(now()->addDays(30)));
    }

    #[Test]
    public function renewing_without_enough_balance_shows_the_same_message_as_the_bot_and_never_touches_the_panel(): void
    {
        Http::fake(); // اگر هر تماسی به پنل بزند، این تست باید مشخص کند — پس بدون واکنشِ موفق تعریف شده

        $user = User::factory()->create();
        $account = $this->makeAccount($user, 100000);
        app(WalletService::class)->charge($this->mainCustomer($user), 50000);

        $response = $this->actingAs($user)->post(route('website.accounts.renew', $account->id));

        $response->assertRedirect(route('website.accounts.show', $account->id));
        $response->assertSessionHas('renewal_error', 'برای تمدید، ابتدا کیف پول خود را شارژ کنید. هزینه‌ی تمدید: 100,000 تومان');

        // پیش‌بررسیِ UX باید قبل از هر Debit جلوی درخواست را بگیرد.
        $this->assertEquals(50000, app(WalletService::class)->balance($this->mainCustomer($user)));
        Http::assertNothingSent();
    }

    #[Test]
    public function a_customer_cannot_renew_another_customers_account(): void
    {
        $owner = User::factory()->create();
        $account = $this->makeAccount($owner);

        $stranger = User::factory()->create();
        $this->mainCustomer($stranger);

        $this->actingAs($stranger)
            ->post(route('website.accounts.renew', $account->id))
            ->assertNotFound();
    }

    // --- Referral/Commission (بند ۵) ---

    #[Test]
    public function referral_page_shows_link_and_referred_count(): void
    {
        $user = User::factory()->create();
        User::factory()->count(3)->create(['referrer_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('website.referral.show'));

        $response->assertOk()
            ->assertSee('ref='.$user->id)
            ->assertSee('3');
    }

    #[Test]
    public function referral_page_only_shows_commissions_earned_in_the_current_store_context(): void
    {
        $referrer = User::factory()->create();
        $mainCustomer = $this->mainCustomer($referrer);

        $referred = User::factory()->create(['referrer_id' => $referrer->id]);
        $product = Product::factory()->create(['category_id' => Category::factory()->create()->id]);
        $order = Order::factory()->create(['product_id' => $product->id]);

        Commission::create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'referrer_customer_account_id' => $mainCustomer->id,
            'order_id' => $order->id,
            'type' => 'ongoing_commission',
            'amount' => 12000,
            'status' => 'paid',
        ]);

        // کمیسیونی که در یک Context دیگر (customer_account_id متفاوت) ثبت شده — نباید اینجا دیده شود.
        // باید یک CustomerAccount واقعی باشد (FK در SQLite اعمال می‌شود، عدد ثابت 999999 نمی‌شود).
        $otherReseller = Reseller::factory()->create();
        $otherContextCustomer = app(IdentityService::class)
            ->resolveCustomerAccount($referrer, StoreContext::reseller($otherReseller));

        Commission::create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'referrer_customer_account_id' => $otherContextCustomer->id,
            'order_id' => Order::factory()->create(['product_id' => $product->id])->id,
            'type' => 'ongoing_commission',
            'amount' => 99000,
            'status' => 'paid',
        ]);

        $response = $this->actingAs($referrer)->get(route('website.referral.show'));

        $response->assertOk()->assertSee('12,000')->assertDontSee('99,000');
    }
}
