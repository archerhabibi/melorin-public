<?php

namespace Tests\Feature\Website;

use App\Models\Account;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ServerPanel;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Core\Customer\CustomerDashboardService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B3.1 — Customer Dashboard (سرویس‌های فعال + کیف‌پول + اعلان‌ها).
 */
class CustomerDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected IdentityService $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identity = app(IdentityService::class);
    }

    private function product(): Product
    {
        $category = Category::factory()->create(['status' => 'active']);
        $panel = ServerPanel::factory()->create(['status' => 'active']);
        $category->serverPanels()->attach($panel->id);

        return Product::factory()->create(['category_id' => $category->id, 'status' => 'active', 'name' => 'پلن طلایی']);
    }

    /** @return array{0: User, 1: \App\Models\CustomerAccount} */
    private function customer(?StoreContext $store = null, ?User $user = null): array
    {
        $user ??= User::factory()->create();

        return [$user, $this->identity->resolveCustomerAccount($user, $store ?? StoreContext::main())];
    }

    private function account($customer, User $user, Product $product, array $attrs = []): Account
    {
        $order = Order::factory()->create([
            'customer_account_id' => $customer->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'reseller_id' => $customer->reseller_id,
        ]);

        return Account::factory()->create(array_merge([
            'customer_account_id' => $customer->id,
            'user_id' => $user->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
        ], $attrs));
    }

    private function snapshot(User $user, ?StoreContext $store = null)
    {
        $store ??= StoreContext::main();

        return app(CustomerDashboardService::class)
            ->snapshot($user, $store, $this->identity->findCustomerAccount($user, $store));
    }

    // --- دسترسی و مسیر ---

    #[Test]
    public function guest_is_redirected_to_login(): void
    {
        $this->get(route('website.dashboard'))->assertRedirect(route('website.login'));
    }

    #[Test]
    public function the_dashboard_renders_with_navigation_and_stats(): void
    {
        [$user] = $this->customer();

        $this->actingAs($user)
            ->get(route('website.dashboard'))
            ->assertOk()
            ->assertSee('داشبورد')
            ->assertSee('سرویس‌های فعال')
            ->assertSee('موجودی کیف‌پول')
            ->assertSee('aria-label="مسیر صفحه"', false)
            ->assertSee(route('website.accounts.index'), false);
    }

    #[Test]
    public function the_header_account_link_points_to_the_dashboard(): void
    {
        [$user] = $this->customer();

        $this->actingAs($user)
            ->get(route('website.home'))
            ->assertOk()
            ->assertSee(route('website.dashboard'), false);
    }

    #[Test]
    public function opening_the_dashboard_creates_neither_customer_account_nor_wallet(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('website.dashboard'))->assertOk();

        $this->assertSame(0, \App\Models\CustomerAccount::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, Wallet::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function a_user_without_membership_sees_an_empty_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('website.dashboard'))
            ->assertOk()
            ->assertSee('هنوز سرویسی ندارید')
            ->assertViewHas('dashboard', fn ($d) => $d->activeCount === 0 && $d->notices->isEmpty());
    }

    // --- سرویس‌ها ---

    #[Test]
    public function it_counts_active_expiring_and_lapsed_services(): void
    {
        [$user, $customer] = $this->customer();
        $product = $this->product();

        $this->account($customer, $user, $product, ['expires_at' => now()->addDays(20)]);
        $this->account($customer, $user, $product, ['expires_at' => now()->addDays(3)]);
        $this->account($customer, $user, $product, ['expires_at' => now()->subDay()]);
        $this->account($customer, $user, $product, ['status' => 'expired', 'expires_at' => now()->subDays(2)]);
        $this->account($customer, $user, $product, ['status' => 'disabled', 'expires_at' => now()->addDays(10)]);

        $d = $this->snapshot($user);

        $this->assertSame(2, $d->activeCount);
        $this->assertSame(1, $d->expiringSoonCount);
        $this->assertSame(2, $d->lapsedCount);
        $this->assertTrue($d->hasServices());
    }

    #[Test]
    public function featured_services_are_the_active_ones_closest_to_expiry(): void
    {
        [$user, $customer] = $this->customer();
        $product = $this->product();

        $late = $this->account($customer, $user, $product, ['expires_at' => now()->addDays(25)]);
        $soon = $this->account($customer, $user, $product, ['expires_at' => now()->addDays(2)]);
        $this->account($customer, $user, $product, ['expires_at' => now()->subDay()]);

        $ids = $this->snapshot($user)->featuredServices->pluck('id')->all();

        $this->assertSame([$soon->id, $late->id], $ids);
    }

    #[Test]
    public function the_page_shows_service_name_and_traffic_usage(): void
    {
        [$user, $customer] = $this->customer();
        $this->account($customer, $user, $this->product(), [
            'traffic_gb' => 100, 'traffic_used_gb' => 25, 'expires_at' => now()->addDays(20),
        ]);

        $this->actingAs($user)
            ->get(route('website.dashboard'))
            ->assertOk()
            ->assertSee('پلن طلایی')
            ->assertSee('role="progressbar"', false)
            ->assertSee('aria-valuenow="25"', false)
            ->assertSee('75.0');
    }

    #[Test]
    public function unlimited_traffic_shows_no_progress_bar(): void
    {
        [$user, $customer] = $this->customer();
        $this->account($customer, $user, $this->product(), ['traffic_gb' => null, 'expires_at' => now()->addDays(20)]);

        $this->actingAs($user)
            ->get(route('website.dashboard'))
            ->assertOk()
            ->assertSee('نامحدود')
            ->assertDontSee('role="progressbar"', false);
    }

    // --- Isolation ---

    #[Test]
    public function another_customers_services_wallet_and_orders_never_appear(): void
    {
        $product = $this->product();

        [$owner, $ownerCustomer] = $this->customer();
        $this->account($ownerCustomer, $owner, $product, ['expires_at' => now()->addDay()]);
        app(WalletService::class)->credit($ownerCustomer, 999000);

        [$viewer] = $this->customer();

        $d = $this->snapshot($viewer);

        $this->assertSame(0, $d->activeCount);
        $this->assertSame(0, $d->walletBalance);
        $this->assertTrue($d->notices->isEmpty());

        $this->actingAs($viewer)->get(route('website.dashboard'))->assertOk()->assertDontSee('999,000');
    }

    #[Test]
    public function services_and_wallet_never_leak_across_store_context(): void
    {
        $product = $this->product();
        $reseller = Reseller::factory()->create();
        $user = User::factory()->create();

        [, $mainCustomer] = $this->customer(StoreContext::main(), $user);
        [, $resellerCustomer] = $this->customer(StoreContext::reseller($reseller), $user);

        $this->account($mainCustomer, $user, $product, ['expires_at' => now()->addDays(2)]);
        app(WalletService::class)->credit($mainCustomer, 50000);
        app(WalletService::class)->credit($resellerCustomer, 7000);

        $main = $this->snapshot($user, StoreContext::main());
        $res = $this->snapshot($user, StoreContext::reseller($reseller));

        $this->assertSame(1, $main->activeCount);
        $this->assertSame(50000, $main->walletBalance);
        $this->assertSame(0, $res->activeCount);
        $this->assertSame(7000, $res->walletBalance);

        $this->actingAs($user)
            ->get(route('website.store.dashboard', $reseller->slug))
            ->assertOk()
            ->assertViewHas('dashboard', fn ($d) => $d->activeCount === 0 && $d->walletBalance === 7000);
    }

    // --- اعلان‌ها ---

    #[Test]
    public function an_expiring_service_raises_a_warning_that_links_to_the_account(): void
    {
        [$user, $customer] = $this->customer();
        $account = $this->account($customer, $user, $this->product(), ['expires_at' => now()->addDays(3)]);

        $notice = $this->snapshot($user)->notices->firstWhere('key', 'service.expiring.'.$account->id);

        $this->assertNotNull($notice);
        $this->assertSame('warning', $notice->tone);

        $this->actingAs($user)
            ->get(route('website.dashboard'))
            ->assertSee(route('website.accounts.show', $account->id), false);
    }

    #[Test]
    public function a_recently_lapsed_service_raises_a_danger_notice_but_an_old_one_does_not(): void
    {
        [$user, $customer] = $this->customer();
        $product = $this->product();

        $recent = $this->account($customer, $user, $product, ['expires_at' => now()->subDays(2)]);
        $old = $this->account($customer, $user, $product, ['expires_at' => now()->subDays(90)]);

        $d = $this->snapshot($user);

        $this->assertSame('danger', $d->notices->firstWhere('key', 'service.expired.'.$recent->id)?->tone);
        $this->assertNull($d->notices->firstWhere('key', 'service.expired.'.$old->id));
        $this->assertSame(2, $d->lapsedCount);
    }

    #[Test]
    public function low_and_exhausted_traffic_raise_warning_and_danger(): void
    {
        [$user, $customer] = $this->customer();
        $product = $this->product();

        $low = $this->account($customer, $user, $product, ['traffic_gb' => 100, 'traffic_used_gb' => 95, 'expires_at' => now()->addDays(20)]);
        $gone = $this->account($customer, $user, $product, ['traffic_gb' => 100, 'traffic_used_gb' => 100, 'expires_at' => now()->addDays(20)]);
        $fine = $this->account($customer, $user, $product, ['traffic_gb' => 100, 'traffic_used_gb' => 10, 'expires_at' => now()->addDays(20)]);

        $notices = $this->snapshot($user)->notices;

        $this->assertSame('warning', $notices->firstWhere('key', 'service.traffic.'.$low->id)?->tone);
        $this->assertSame('danger', $notices->firstWhere('key', 'service.traffic.'.$gone->id)?->tone);
        $this->assertNull($notices->firstWhere('key', 'service.traffic.'.$fine->id));
    }

    #[Test]
    public function an_empty_wallet_with_an_expiring_service_suggests_charging(): void
    {
        [$user, $customer] = $this->customer();
        $this->account($customer, $user, $this->product(), ['expires_at' => now()->addDays(2)]);

        $this->assertNotNull($this->snapshot($user)->notices->firstWhere('key', 'wallet.empty'));

        app(WalletService::class)->credit($customer, 100000);

        $this->assertNull($this->snapshot($user)->notices->firstWhere('key', 'wallet.empty'));
    }

    #[Test]
    public function a_provision_failed_order_is_announced_without_any_retry_or_refund_action(): void
    {
        [$user, $customer] = $this->customer();
        $order = Order::factory()->create([
            'customer_account_id' => $customer->id,
            'user_id' => $user->id,
            'product_id' => $this->product()->id,
            'reseller_id' => null,
            'status' => Order::STATUS_PROVISION_FAILED,
        ]);

        $this->assertNotNull($this->snapshot($user)->notices->firstWhere('key', 'order.attention.'.$order->id));

        $this->actingAs($user)
            ->get(route('website.dashboard'))
            ->assertOk()
            ->assertSee('در حال رسیدگی')
            ->assertDontSee('تلاش مجدد')
            ->assertDontSee('بازگشت وجه');
    }

    #[Test]
    public function a_pending_charge_is_announced_only_in_its_own_context(): void
    {
        $reseller = Reseller::factory()->create();
        [$user] = $this->customer();

        Payment::create([
            'user_id' => $user->id,
            'payment_method_id' => PaymentMethod::factory()->create()->id,
            'amount' => 50000,
            'purpose' => 'wallet_charge',
            'status' => 'pending',
            'wallet_owner_type' => 'user',
            'reseller_id' => null,
        ]);

        $this->assertNotNull($this->snapshot($user, StoreContext::main())->notices->firstWhere('key', 'payment.pending'));
        $this->assertNull($this->snapshot($user, StoreContext::reseller($reseller))->notices->firstWhere('key', 'payment.pending'));
    }

    #[Test]
    public function notices_are_sorted_by_severity_and_capped(): void
    {
        [$user, $customer] = $this->customer();
        $product = $this->product();

        foreach (range(1, 3) as $i) {
            $this->account($customer, $user, $product, ['expires_at' => now()->addDays($i)]);
            $this->account($customer, $user, $product, ['expires_at' => now()->subDays($i)]);
            $this->account($customer, $user, $product, ['traffic_gb' => 10, 'traffic_used_gb' => 9.5, 'expires_at' => now()->addDays(30 + $i)]);
        }

        $notices = $this->snapshot($user)->notices;

        $this->assertLessThanOrEqual(CustomerDashboardService::NOTICES_LIMIT, $notices->count());
        $this->assertSame('danger', $notices->first()->tone);
        $this->assertSame($notices->pluck('tone')->all(), $notices->sortBy->severity()->pluck('tone')->values()->all());
    }

    // --- کیف‌پول ---

    #[Test]
    public function the_wallet_card_shows_balance_and_the_latest_transactions_only(): void
    {
        [$user, $customer] = $this->customer();
        $wallet = app(WalletService::class);

        foreach (range(1, 7) as $i) {
            $wallet->credit($customer, 1000 * $i);
        }

        $d = $this->snapshot($user);

        $this->assertSame(28000, $d->walletBalance);
        $this->assertCount(CustomerDashboardService::TRANSACTIONS_LIMIT, $d->recentTransactions);
        $this->assertSame(7000, (int) $d->recentTransactions->first()->amount);

        $this->actingAs($user)
            ->get(route('website.dashboard'))
            ->assertOk()
            ->assertSee('28,000')
            ->assertSee(route('website.wallet.charge.show'), false);
    }

    // --- مدل Account ---

    #[Test]
    public function account_helpers_compute_days_and_usage_safely(): void
    {
        $a = new Account(['expires_at' => now()->addHours(30), 'traffic_gb' => 10, 'traffic_used_gb' => 9.99]);
        $this->assertSame(2, $a->remainingDays());
        $this->assertSame(99, $a->trafficUsagePercent());

        $a = new Account(['expires_at' => now()->subDay(), 'traffic_gb' => 10, 'traffic_used_gb' => 12]);
        $this->assertSame(0, $a->remainingDays());
        $this->assertSame(100, $a->trafficUsagePercent());

        $a = new Account(['expires_at' => null, 'traffic_gb' => null]);
        $this->assertNull($a->remainingDays());
        $this->assertNull($a->trafficUsagePercent());
    }
}
