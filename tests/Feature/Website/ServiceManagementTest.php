<?php

namespace Tests\Feature\Website;

use App\DataTransferObjects\PanelAccountResult;
use App\Models\Account;
use App\Models\Category;
use App\Models\CustomerAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerProductPrice;
use App\Models\ServerPanel;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Core\Customer\AccountManagementService;
use App\Services\Core\Customer\AccountUsageService;
use App\Services\Core\Customer\UsageRefreshResult;
use App\Services\Core\Panels\MarzbanDriver;
use App\Services\Core\Panels\PasarGuardDriver;
use App\Services\Core\Panels\SanaeiDriver;
use App\Services\Core\Purchase\PurchaseNotAllowedException;
use App\Services\Core\Renewal\RenewalQuote;
use App\Services\Core\Renewal\RenewalService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B3.2 — Service Management: مصرف زنده + تمدید (پیش‌فاکتور، قیمت هر Context، دروازه‌ی وضعیت).
 * Contract: docs/canonical/CUSTOMER-SERVICES-CONTRACT.md
 *
 * «ارتقا / تغییر پلن» به تصمیم صاحب پروژه در این فاز وجود ندارد و تستی برایش نیست؛ فقط یک تست
 * قفل می‌کند که هیچ route ارتقا ثبت نشده است.
 */
class ServiceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected IdentityService $identity;

    protected WalletService $wallet;

    /** panel_username روی accounts یکتاست */
    protected int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identity = app(IdentityService::class);
        $this->wallet = app(WalletService::class);
    }

    /** پنل Marzban: توکن + کاربر (با مصرف دلخواه به بایت؛ null = فیلد مصرف در پاسخ نیست). */
    protected function fakeMarzban(?int $usedBytes = 0, int $status = 200): void
    {
        $body = ['username' => 'melorin_existing'];

        if ($usedBytes !== null) {
            $body['used_traffic'] = $usedBytes;
        }

        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user/*' => Http::response($status === 200 ? $body : ['detail' => 'boom'], $status),
        ]);
    }

    protected function gb(float $gb): int
    {
        return (int) ($gb * 1024 ** 3);
    }

    protected function product(int $mainPrice = 100000, array $attrs = []): Product
    {
        $panel = ServerPanel::factory()->create(['panel_type' => 'marzban']);
        $category = Category::factory()->create(['status' => 'active']);
        $category->serverPanels()->attach($panel);

        return Product::factory()->create(array_merge([
            'category_id' => $category->id,
            'main_price' => $mainPrice,
            'status' => 'active',
            'name' => 'پلن آزمایشی',
            'traffic_gb' => 50,
            'duration_days' => 30,
        ], $attrs));
    }

    /** @return array{0: User, 1: CustomerAccount, 2: Account} */
    protected function service(?StoreContext $store = null, array $attrs = [], ?Product $product = null, ?User $user = null): array
    {
        $user ??= User::factory()->create();
        $customer = $this->identity->resolveCustomerAccount($user, $store ?? StoreContext::main());
        $product ??= $this->product();
        $panel = $product->category->serverPanels()->first();

        $account = Account::factory()->create(array_merge([
            'user_id' => $user->id,
            'customer_account_id' => $customer->id,
            'product_id' => $product->id,
            'server_panel_id' => $panel->id,
            'panel_username' => 'melorin_svc_'.++$this->seq,
            'status' => 'active',
            'expires_at' => now()->addDays(15),
            'traffic_gb' => 50,
            'traffic_used_gb' => 0,
        ], $attrs));

        return [$user, $customer, $account];
    }

    protected function showUrl(Account $account): string
    {
        return route('website.accounts.show', $account->id);
    }

    // ───────────────────────── Quote (Core) ─────────────────────────

    #[Test]
    public function the_quote_is_read_only_and_never_creates_a_wallet_or_an_order(): void
    {
        [, $customer, $account] = $this->service();

        $quote = app(RenewalService::class)->quote($account);

        $this->assertFalse($quote->canRenew);
        $this->assertSame(RenewalQuote::REASON_INSUFFICIENT_BALANCE, $quote->reason);
        $this->assertSame(100000, $quote->price);
        $this->assertSame(0, $quote->balance);
        $this->assertSame(100000, $quote->shortfall());
        $this->assertTrue($quote->needsTopUp());
        $this->assertSame(0, Wallet::query()->count());
        $this->assertSame(0, Order::query()->where('renews_account_id', $account->id)->count());
    }

    #[Test]
    public function a_funded_wallet_makes_the_quote_renewable(): void
    {
        [, $customer, $account] = $this->service();
        $this->wallet->credit($customer, 100000);

        $quote = app(RenewalService::class)->quote($account);

        $this->assertTrue($quote->canRenew);
        $this->assertNull($quote->reason);
        $this->assertSame(100000, $quote->price);
        $this->assertSame(100000, $quote->balance);
        $this->assertFalse($quote->needsTopUp());
    }

    #[Test]
    public function an_inactive_product_makes_the_quote_unavailable_not_a_crash(): void
    {
        $product = $this->product();
        [, $customer, $account] = $this->service(product: $product);
        $this->wallet->credit($customer, 500000);
        $product->update(['status' => 'inactive']);

        $quote = app(RenewalService::class)->quote($account->fresh());

        $this->assertFalse($quote->canRenew);
        $this->assertSame(RenewalQuote::REASON_NOT_AVAILABLE, $quote->reason);
    }

    #[Test]
    #[DataProvider('blockedStatuses')]
    public function disabled_suspended_and_other_non_renewable_statuses_are_blocked(string $status): void
    {
        [, $customer, $account] = $this->service(attrs: ['status' => $status]);
        $this->wallet->credit($customer, 500000);

        $quote = app(RenewalService::class)->quote($account);

        $this->assertFalse($quote->canRenew);
        $this->assertSame(RenewalQuote::REASON_ACCOUNT_STATE, $quote->reason);
    }

    public static function blockedStatuses(): array
    {
        return [['disabled'], ['suspended'], ['deleted']];
    }

    #[Test]
    public function an_expired_service_can_be_renewed(): void
    {
        [, $customer, $account] = $this->service(attrs: ['status' => 'expired', 'expires_at' => now()->subDays(3)]);
        $this->wallet->credit($customer, 100000);

        $this->assertTrue(app(RenewalService::class)->quote($account)->canRenew);
    }

    // ───────────────────── Renewal: price in the right context ─────────────────────

    #[Test]
    public function a_reseller_store_renewal_uses_customers_price_not_the_main_price(): void
    {
        $this->fakeMarzban();
        $product = $this->product(150000, ['reseller_price' => 100000]);
        $reseller = Reseller::factory()->create(['status' => 'active']);
        ResellerProductPrice::create([
            'reseller_id' => $reseller->id, 'product_id' => $product->id,
            'customers_price' => 130000, 'is_enabled' => true,
        ]);

        [$user, $customer, $account] = $this->service(StoreContext::reseller($reseller), product: $product);
        $this->wallet->credit($customer, 130000);   // کمتر از main_price (150000) ولی دقیقاً customers_price
        $this->wallet->credit($reseller, 500000);

        $quote = app(RenewalService::class)->quote($account);
        $this->assertTrue($quote->canRenew, (string) $quote->message);
        $this->assertSame(130000, $quote->price);

        $this->actingAs($user)
            ->post(route('website.store.accounts.renew', [$reseller->slug, $account->id]))
            ->assertRedirect(route('website.store.accounts.show', [$reseller->slug, $account->id]))
            ->assertSessionHas('renewal_success');

        $this->assertSame(0, $this->wallet->getBalance($customer));
        $this->assertSame(400000, $this->wallet->balance($reseller));
    }

    #[Test]
    public function the_reseller_store_page_shows_customers_price_and_never_the_main_price(): void
    {
        $product = $this->product(150000, ['reseller_price' => 100000]);
        $reseller = Reseller::factory()->create(['status' => 'active']);
        ResellerProductPrice::create([
            'reseller_id' => $reseller->id, 'product_id' => $product->id,
            'customers_price' => 130000, 'is_enabled' => true,
        ]);
        [$user, , $account] = $this->service(StoreContext::reseller($reseller), product: $product);

        $this->actingAs($user)
            ->get(route('website.store.accounts.show', [$reseller->slug, $account->id]))
            ->assertOk()
            ->assertSee(number_format(130000))
            ->assertDontSee(number_format(150000))
            ->assertDontSee(number_format(100000));
    }

    // ───────────────────── Renewal: Website flow ─────────────────────

    #[Test]
    public function renewal_resets_time_and_traffic_and_stamps_usage_sync(): void
    {
        $this->fakeMarzban();
        [$user, $customer, $account] = $this->service(attrs: ['traffic_used_gb' => 49.5, 'usage_synced_at' => now()->subDay()]);
        $this->wallet->credit($customer, 100000);

        $this->actingAs($user)->post(route('website.accounts.renew', $account->id))
            ->assertRedirect($this->showUrl($account))->assertSessionHas('renewal_success');

        $fresh = $account->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertEquals(0.0, (float) $fresh->traffic_used_gb);
        $this->assertTrue($fresh->usage_synced_at->isAfter(now()->subMinute()));
        // روزهای باقی‌مانده (۱۵) نمی‌سوزد: ۱۵ + ۳۰ روز
        $this->assertTrue($fresh->expires_at->isAfter(now()->addDays(44)));
    }

    #[Test]
    public function an_insufficient_balance_keeps_the_bot_message_with_the_real_price_and_never_calls_the_panel(): void
    {
        Http::fake();
        [$user, $customer, $account] = $this->service();
        $this->wallet->credit($customer, 50000);

        $this->actingAs($user)->post(route('website.accounts.renew', $account->id))
            ->assertSessionHas('renewal_error', 'برای تمدید، ابتدا کیف پول خود را شارژ کنید. هزینه‌ی تمدید: 100,000 تومان');

        $this->assertSame(50000, $this->wallet->balance($customer));
        Http::assertNothingSent();
    }

    #[Test]
    public function a_disabled_service_cannot_be_revived_by_paying(): void
    {
        Http::fake();
        [$user, $customer, $account] = $this->service(attrs: ['status' => 'disabled']);
        $this->wallet->credit($customer, 500000);

        $this->actingAs($user)->post(route('website.accounts.renew', $account->id))
            ->assertSessionHas('renewal_error');

        $this->assertSame('disabled', $account->fresh()->status);
        $this->assertSame(500000, $this->wallet->balance($customer));
        $this->assertSame(0, Order::query()->where('renews_account_id', $account->id)->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function the_core_gate_rejects_a_disabled_service_even_without_the_ui_precheck(): void
    {
        Http::fake();
        [, $customer, $account] = $this->service(attrs: ['status' => 'suspended']);
        $this->wallet->credit($customer, 500000);

        $this->expectException(PurchaseNotAllowedException::class);

        try {
            app(RenewalService::class)->renew($account, 'direct-1');
        } finally {
            $this->assertSame(500000, $this->wallet->balance($customer));
            $this->assertSame('suspended', $account->fresh()->status);
        }
    }

    #[Test]
    public function resubmitting_the_same_form_after_a_successful_renewal_succeeds_without_a_second_charge(): void
    {
        $this->fakeMarzban();
        [$user, $customer, $account] = $this->service();
        $this->wallet->credit($customer, 100000); // دقیقاً یک تمدید؛ بعدش کمبود

        $this->actingAs($user)->post(route('website.accounts.renew', $account->id), ['idempotency_token' => 'tok-a'])
            ->assertSessionHas('renewal_success');
        $this->assertSame(0, $this->wallet->balance($customer));

        // دوباره‌فرستادن همان توکن: خطای «موجودی کافی نیست» نباید جای موفقیت بنشیند
        $this->actingAs($user)->post(route('website.accounts.renew', $account->id), ['idempotency_token' => 'tok-a'])
            ->assertSessionHas('renewal_success')
            ->assertSessionMissing('renewal_error');

        $this->assertSame(0, $this->wallet->balance($customer));
        $this->assertSame(1, Order::query()->where('renews_account_id', $account->id)->count());
    }

    #[Test]
    public function a_different_token_after_the_balance_is_spent_is_a_real_shortfall(): void
    {
        $this->fakeMarzban();
        [$user, $customer, $account] = $this->service();
        $this->wallet->credit($customer, 100000);

        $this->actingAs($user)->post(route('website.accounts.renew', $account->id), ['idempotency_token' => 'tok-1']);
        $this->actingAs($user)->post(route('website.accounts.renew', $account->id), ['idempotency_token' => 'tok-2'])
            ->assertSessionHas('renewal_error');

        $this->assertSame(1, Order::query()->where('renews_account_id', $account->id)->count());
    }

    #[Test]
    public function the_renew_route_is_throttled(): void
    {
        Http::fake();
        [$user, , $account] = $this->service();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->post(route('website.accounts.renew', $account->id))->assertRedirect();
        }

        $this->actingAs($user)->post(route('website.accounts.renew', $account->id))->assertStatus(429);
    }

    #[Test]
    public function there_is_no_upgrade_route_by_product_decision(): void
    {
        $names = collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => (string) $r->getName());

        $this->assertFalse($names->contains(fn ($n) => str_contains($n, 'upgrade')));
        $this->assertFalse($names->contains(fn ($n) => str_contains($n, 'plan-change')));
    }

    // ───────────────────── Usage sync ─────────────────────

    #[Test]
    public function refreshing_usage_reads_the_panel_and_updates_the_account(): void
    {
        $this->fakeMarzban($this->gb(12.5));
        [$user, , $account] = $this->service();

        $this->actingAs($user)->post(route('website.accounts.usage.refresh', $account->id))
            ->assertRedirect($this->showUrl($account))
            ->assertSessionHas('usage_notice', fn ($n) => $n['tone'] === 'success');

        $fresh = $account->fresh();
        $this->assertEquals(12.5, (float) $fresh->traffic_used_gb);
        $this->assertNotNull($fresh->usage_synced_at);
    }

    #[Test]
    public function a_second_refresh_within_the_throttle_window_does_not_call_the_panel_again(): void
    {
        $this->fakeMarzban($this->gb(3));
        [$user, , $account] = $this->service();

        $this->actingAs($user)->post(route('website.accounts.usage.refresh', $account->id));
        Http::assertSentCount(2); // token + user

        $this->actingAs($user)->post(route('website.accounts.usage.refresh', $account->id))
            ->assertSessionHas('usage_notice', fn ($n) => $n['tone'] === 'info');

        Http::assertSentCount(2);
    }

    #[Test]
    public function a_panel_failure_keeps_the_previous_usage_value(): void
    {
        $this->fakeMarzban(null, 500);
        [$user, , $account] = $this->service(attrs: ['traffic_used_gb' => 7.25]);

        $this->actingAs($user)->post(route('website.accounts.usage.refresh', $account->id))
            ->assertSessionHas('usage_notice', fn ($n) => $n['tone'] === 'warning');

        $this->assertEquals(7.25, (float) $account->fresh()->traffic_used_gb);
        $this->assertNull($account->fresh()->usage_synced_at);
    }

    #[Test]
    public function a_response_without_usage_never_zeroes_the_stored_value(): void
    {
        $this->fakeMarzban(null);
        [, , $account] = $this->service(attrs: ['traffic_used_gb' => 7.25]);

        $result = app(AccountUsageService::class)->refresh($account, force: true);

        $this->assertSame(UsageRefreshResult::FAILED, $result->status);
        $this->assertEquals(7.25, (float) $account->fresh()->traffic_used_gb);
    }

    #[Test]
    public function usage_is_not_read_for_a_non_active_service(): void
    {
        Http::fake();
        [, , $account] = $this->service(attrs: ['status' => 'disabled']);

        $result = app(AccountUsageService::class)->refresh($account, force: true);

        $this->assertSame(UsageRefreshResult::SKIPPED, $result->status);
        Http::assertNothingSent();
    }

    #[Test]
    public function each_driver_extracts_usage_from_its_own_response_shape(): void
    {
        $marzban = new MarzbanDriver;
        $pasar = new PasarGuardDriver;
        $sanaei = new SanaeiDriver;

        $this->assertSame(5000, $marzban->usedTrafficBytes(PanelAccountResult::ok(['used_traffic' => 5000])));
        $this->assertSame(5000, $pasar->usedTrafficBytes(PanelAccountResult::ok(['used_traffic' => '5000'])));
        $this->assertNull($marzban->usedTrafficBytes(PanelAccountResult::ok(['username' => 'x'])));

        // 3x-ui: up + down؛ اول بلاکِ traffic بعد client
        $this->assertSame(300, $sanaei->usedTrafficBytes(PanelAccountResult::ok(['traffic' => ['up' => 100, 'down' => 200]])));
        $this->assertSame(70, $sanaei->usedTrafficBytes(PanelAccountResult::ok(['traffic' => null, 'client' => ['up' => 20, 'down' => 50]])));
        $this->assertNull($sanaei->usedTrafficBytes(PanelAccountResult::ok(['traffic' => null, 'client' => ['enable' => true]])));
    }

    #[Test]
    public function the_sync_command_refreshes_only_stale_active_services_oldest_first_within_the_limit(): void
    {
        $this->fakeMarzban($this->gb(4));
        $product = $this->product();
        $user = User::factory()->create();

        [, , $never] = $this->service(attrs: ['usage_synced_at' => null], product: $product, user: $user);
        [, , $old] = $this->service(attrs: ['usage_synced_at' => now()->subHours(3)], product: $product, user: $user);
        [, , $fresh] = $this->service(attrs: ['usage_synced_at' => now()->subMinutes(5), 'traffic_used_gb' => 1], product: $product, user: $user);
        [, , $disabled] = $this->service(attrs: ['status' => 'disabled'], product: $product, user: $user);
        [, , $lapsed] = $this->service(attrs: ['expires_at' => now()->subDay()], product: $product, user: $user);

        Artisan::call('accounts:sync-usage', ['--limit' => 10]);

        $this->assertEquals(4.0, (float) $never->fresh()->traffic_used_gb);
        $this->assertEquals(4.0, (float) $old->fresh()->traffic_used_gb);
        $this->assertEquals(1.0, (float) $fresh->fresh()->traffic_used_gb);      // تازه است
        $this->assertEquals(0.0, (float) $disabled->fresh()->traffic_used_gb);   // فعال نیست
        $this->assertEquals(0.0, (float) $lapsed->fresh()->traffic_used_gb);     // منقضی است
    }

    #[Test]
    public function the_sync_command_respects_the_per_run_cap(): void
    {
        $this->fakeMarzban($this->gb(2));
        $product = $this->product();
        $user = User::factory()->create();

        foreach (range(1, 3) as $i) {
            $this->service(attrs: ['usage_synced_at' => null], product: $product, user: $user);
        }

        Artisan::call('accounts:sync-usage', ['--limit' => 2]);

        $this->assertSame(2, Account::query()->where('traffic_used_gb', '>', 0)->count());
    }

    #[Test]
    public function the_sync_runs_on_the_schedule(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command, 'accounts:sync-usage'));

        $this->assertCount(1, $events);
        $this->assertSame('*/15 * * * *', $events->first()->expression);
    }

    // ───────────────────── Pages ─────────────────────

    #[Test]
    public function opening_the_pages_writes_nothing(): void
    {
        [$user, , $account] = $this->service();

        $this->actingAs($user)->get($this->showUrl($account))->assertOk();
        $this->actingAs($user)->get(route('website.accounts.index'))->assertOk();

        $this->assertSame(0, Wallet::query()->count());
        $this->assertSame(0, Order::query()->where('renews_account_id', $account->id)->count());
        $this->assertNull($account->fresh()->usage_synced_at);
    }

    #[Test]
    public function the_detail_page_shows_usage_with_a_warning_at_ninety_percent(): void
    {
        [$user, , $account] = $this->service(attrs: ['traffic_gb' => 100, 'traffic_used_gb' => 92, 'usage_synced_at' => now()]);

        $this->actingAs($user)->get($this->showUrl($account))->assertOk()
            ->assertSee('92٪')
            ->assertSee('role="progressbar"', false)
            ->assertSee('bg-warning', false)
            ->assertSee('حجم سرویس رو به پایان است')
            ->assertSee('به‌روزرسانی مصرف');
    }

    #[Test]
    public function an_exhausted_service_shows_danger_and_an_unlimited_one_shows_no_bar(): void
    {
        [$user, , $full] = $this->service(attrs: ['traffic_gb' => 10, 'traffic_used_gb' => 10]);
        [, , $unlimited] = $this->service(attrs: ['traffic_gb' => 0, 'traffic_used_gb' => 4], user: $user);

        $this->actingAs($user)->get($this->showUrl($full))
            ->assertSee('bg-danger', false)
            ->assertSee('حجم این سرویس تمام شده است');

        $this->actingAs($user)->get($this->showUrl($unlimited))
            ->assertSee('نامحدود')
            ->assertDontSee('role="progressbar"', false);
    }

    #[Test]
    public function the_detail_page_offers_renewal_with_price_and_balance_when_funded(): void
    {
        [$user, $customer, $account] = $this->service();
        $this->wallet->credit($customer, 250000);

        $this->actingAs($user)->get($this->showUrl($account))->assertOk()
            ->assertSee('name="idempotency_token"', false)
            ->assertSee('تمدید اکانت — 100,000 تومان')
            ->assertSee('250,000 تومان');
    }

    #[Test]
    public function the_detail_page_offers_top_up_with_the_shortfall_when_underfunded(): void
    {
        [$user, $customer, $account] = $this->service();
        $this->wallet->credit($customer, 30000);

        $this->actingAs($user)->get($this->showUrl($account))->assertOk()
            ->assertDontSee('name="idempotency_token"', false)
            ->assertSee('کمبود موجودی: 70,000 تومان')
            ->assertSee(route('website.wallet.charge.show'));
    }

    #[Test]
    public function a_disabled_service_shows_the_reason_and_no_renew_or_refresh_controls(): void
    {
        [$user, $customer, $account] = $this->service(attrs: ['status' => 'disabled']);
        $this->wallet->credit($customer, 500000);

        $this->actingAs($user)->get($this->showUrl($account))->assertOk()
            ->assertSee('غیرفعال')
            ->assertSee('تمدید آنلاین ندارد')
            ->assertDontSee('name="idempotency_token"', false)
            ->assertDontSee(route('website.accounts.usage.refresh', $account->id));
    }

    #[Test]
    public function an_expired_service_is_labelled_expired_and_offers_renewal_but_no_live_usage(): void
    {
        [$user, $customer, $account] = $this->service(attrs: ['status' => 'expired', 'expires_at' => now()->subDays(2)]);
        $this->wallet->credit($customer, 100000);

        $this->actingAs($user)->get($this->showUrl($account))->assertOk()
            ->assertSee('منقضی‌شده')
            ->assertSee('name="idempotency_token"', false)
            ->assertDontSee(route('website.accounts.usage.refresh', $account->id));
    }

    #[Test]
    public function the_index_shows_state_and_usage_per_service(): void
    {
        [$user, , $a] = $this->service(attrs: ['traffic_gb' => 100, 'traffic_used_gb' => 95]);
        $this->service(attrs: ['status' => 'expired', 'expires_at' => now()->subDay()], user: $user);
        $this->service(attrs: ['status' => 'disabled'], user: $user);

        $response = $this->actingAs($user)->get(route('website.accounts.index'))->assertOk();

        $response->assertSee('95٪')
            ->assertSee('منقضی‌شده')
            ->assertSee('غیرفعال')
            ->assertSee('مشاهده و تمدید');
        // فقط سرویس فعال نوار مصرف دارد
        $this->assertSame(1, substr_count($response->getContent(), 'role="progressbar"'));
    }

    #[Test]
    public function display_state_is_the_single_source_for_labels(): void
    {
        [, , $active] = $this->service(attrs: ['expires_at' => now()->addDays(30)]);
        [, , $soon] = $this->service(attrs: ['expires_at' => now()->addDays(3)]);
        [, , $lapsedActive] = $this->service(attrs: ['status' => 'active', 'expires_at' => now()->subHour()]);
        [, , $blocked] = $this->service(attrs: ['status' => 'suspended']);

        $this->assertSame('active', $active->displayState());
        $this->assertSame('expiring', $soon->displayState());
        $this->assertSame('expired', $lapsedActive->displayState());
        $this->assertSame('suspended', $blocked->displayState());
    }

    // ───────────────────── Isolation ─────────────────────

    #[Test]
    public function another_customer_cannot_refresh_or_view_a_service(): void
    {
        $this->fakeMarzban($this->gb(1));
        [, , $account] = $this->service();

        $stranger = User::factory()->create();
        $this->identity->resolveCustomerAccount($stranger, StoreContext::main());

        $this->actingAs($stranger)->post(route('website.accounts.usage.refresh', $account->id))->assertNotFound();
        $this->actingAs($stranger)->get($this->showUrl($account))->assertNotFound();
        Http::assertNothingSent();
    }

    #[Test]
    public function a_main_store_service_is_not_reachable_from_a_reseller_store(): void
    {
        [$user, , $account] = $this->service();
        $reseller = Reseller::factory()->create(['status' => 'active']);
        $this->identity->resolveCustomerAccount($user, StoreContext::reseller($reseller));

        $this->actingAs($user)->get(route('website.store.accounts.show', [$reseller->slug, $account->id]))->assertNotFound();
        $this->actingAs($user)->post(route('website.store.accounts.usage.refresh', [$reseller->slug, $account->id]))->assertNotFound();
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        [, , $account] = $this->service();

        $this->post(route('website.accounts.usage.refresh', $account->id))->assertRedirect(route('website.login'));
        $this->post(route('website.accounts.renew', $account->id))->assertRedirect(route('website.login'));
    }

    #[Test]
    public function the_management_service_overview_bundles_state_usage_and_quote(): void
    {
        [, $customer, $account] = $this->service(attrs: ['traffic_gb' => 100, 'traffic_used_gb' => 90, 'expires_at' => now()->addDays(2)]);
        $this->wallet->credit($customer, 100000);

        $overview = app(AccountManagementService::class)->overview($account);

        $this->assertSame('expiring', $overview->stateKey());
        $this->assertTrue($overview->isExpiringSoon());
        $this->assertSame(90, $overview->usagePercent);
        $this->assertSame('warning', $overview->trafficTone);
        $this->assertFalse($overview->isUnlimitedTraffic());
        $this->assertTrue($overview->renewal->canRenew);
    }
}
