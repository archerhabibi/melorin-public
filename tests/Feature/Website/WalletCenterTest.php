<?php

namespace Tests\Feature\Website;

use App\Models\CustomerAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Core\Customer\CustomerDashboardService;
use App\Services\Core\Customer\WalletCenterFilter;
use App\Services\Core\Customer\WalletCenterService;
use App\Services\Core\Customer\WalletChargeEntry;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B3.3 — Wallet Center (خلاصه، گردش حساب فیلتر‌پذیر، شارژهای اخیر، صفحه‌ی شارژ).
 * Contract: docs/canonical/CUSTOMER-WALLET-CONTRACT.md
 */
class WalletCenterTest extends TestCase
{
    use RefreshDatabase;

    protected IdentityService $identity;

    protected WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identity = app(IdentityService::class);
        $this->wallets = app(WalletService::class);

        config([
            'melorin.currency.topup.min' => '10000',
            'melorin.currency.topup.presets' => '5000,50000,200000',
        ]);
    }

    /** @return array{0: User, 1: CustomerAccount} */
    private function customer(?StoreContext $store = null, ?User $user = null): array
    {
        $user ??= User::factory()->create();

        return [$user, $this->identity->resolveCustomerAccount($user, $store ?? StoreContext::main())];
    }

    /** تراکنش با زمان دلخواه (created_at فقط برای تست جابه‌جا می‌شود) */
    private function tx(CustomerAccount $c, string $kind, int $amount, ?string $at = null, ?object $ref = null, ?string $desc = null): WalletTransaction
    {
        $tx = match ($kind) {
            'charge' => $this->wallets->charge($c, $amount, null, $desc),
            'purchase' => $this->wallets->purchase($c, $amount, $ref, $desc),
            'refund' => $this->wallets->refund($c, $amount, $ref, $desc),
            'referral_bonus' => $this->wallets->addReferralBonus($c, $amount, null, $desc),
        };

        if ($at) {
            $tx->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }

        return $tx;
    }

    private function payment(User $user, array $attrs = []): Payment
    {
        $payment = Payment::create(array_merge([
            'user_id' => $user->id,
            'payment_method_id' => PaymentMethod::factory()->create()->id,
            'amount' => 50000,
            'purpose' => 'wallet_charge',
            'status' => 'pending',
            'wallet_owner_type' => 'user',
            'reseller_id' => null,
        ], $attrs));

        return $payment;
    }

    private function backdate(Payment $payment, string $at): void
    {
        Payment::query()->whereKey($payment->id)->update(['created_at' => $at]);
    }

    private function order(User $user, CustomerAccount $c): Order
    {
        return Order::factory()->create([
            'customer_account_id' => $c->id,
            'user_id' => $user->id,
            'reseller_id' => $c->reseller_id,
        ]);
    }

    // --- دسترسی و بدون نوشتن ---

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->get(route('website.wallet.show'))->assertRedirect(route('website.login'));
    }

    #[Test]
    public function opening_the_wallet_center_or_charge_page_creates_no_wallet_and_no_membership(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('website.wallet.show'))->assertOk()->assertSee('کیف‌پول');
        $this->actingAs($user)->get(route('website.wallet.charge.show'))->assertOk();

        $this->assertSame(0, Wallet::query()->count());
        $this->assertSame(0, CustomerAccount::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }

    // --- خلاصه ---

    #[Test]
    public function the_overview_sums_the_last_30_days_only(): void
    {
        [$user, $c] = $this->customer();

        $this->tx($c, 'charge', 100000, now()->subDays(45)->toDateTimeString()); // خارج از بازه
        $this->tx($c, 'charge', 60000, now()->subDays(3)->toDateTimeString());
        $this->tx($c, 'purchase', 25000, now()->subDays(2)->toDateTimeString());
        $this->tx($c, 'refund', 5000, now()->subDay()->toDateTimeString());

        $o = app(WalletCenterService::class)->overview($user, StoreContext::main());

        $this->assertSame(140000, $o->balance);   // 100000 + 60000 - 25000 + 5000
        $this->assertSame(65000, $o->credited);   // 60000 + 5000 (شارژ ۴۵ روز پیش حساب نمی‌شود)
        $this->assertSame(25000, $o->debited);
        $this->assertTrue($o->hasWallet);

        $this->actingAs($user)->get(route('website.wallet.show'))
            ->assertOk()
            ->assertSee('140,000')
            ->assertSee('65,000')
            ->assertSee('25,000');
    }

    #[Test]
    public function a_user_without_a_wallet_gets_a_zero_overview_and_empty_states(): void
    {
        $user = User::factory()->create();

        $o = app(WalletCenterService::class)->overview($user, StoreContext::main());

        $this->assertSame(0, $o->balance);
        $this->assertFalse($o->hasWallet);
        $this->assertSame(0, $o->pendingCount);

        $this->actingAs($user)->get(route('website.wallet.show'))
            ->assertOk()
            ->assertSee('هنوز شارژی انجام نداده‌اید')
            ->assertSee('هنوز تراکنشی ثبت نشده است');
    }

    // --- فیلتر گردش حساب ---

    #[Test]
    public function the_direction_filter_splits_credits_and_debits(): void
    {
        [$user, $c] = $this->customer();
        $this->tx($c, 'charge', 90000, null, null, 'شارژ تستی یک');
        $this->tx($c, 'purchase', 30000, null, null, 'خرید تستی یک');

        $this->actingAs($user)->get(route('website.wallet.show', ['direction' => 'in']))
            ->assertOk()->assertSee('شارژ تستی یک')->assertDontSee('خرید تستی یک');

        $this->actingAs($user)->get(route('website.wallet.show', ['direction' => 'out']))
            ->assertOk()->assertSee('خرید تستی یک')->assertDontSee('شارژ تستی یک');
    }

    #[Test]
    public function the_type_filter_matches_exactly_one_type(): void
    {
        [$user, $c] = $this->customer();
        $this->tx($c, 'charge', 90000, null, null, 'شارژ تستی دو');
        $this->tx($c, 'purchase', 30000, null, null, 'خرید تستی دو');
        $this->tx($c, 'refund', 10000, null, null, 'بازگشت تستی دو');

        $this->actingAs($user)->get(route('website.wallet.show', ['type' => 'refund']))
            ->assertOk()->assertSee('بازگشت تستی دو')
            ->assertDontSee('شارژ تستی دو')->assertDontSee('خرید تستی دو');
    }

    #[Test]
    public function the_date_range_is_inclusive_and_swapped_dates_are_repaired(): void
    {
        [$user, $c] = $this->customer();
        $this->tx($c, 'charge', 10000, '2026-03-09 23:59:59', null, 'قبل از بازه');
        $this->tx($c, 'charge', 20000, '2026-03-10 00:00:00', null, 'اول بازه');
        $this->tx($c, 'charge', 30000, '2026-03-12 23:59:59', null, 'آخر بازه');
        $this->tx($c, 'charge', 40000, '2026-03-13 00:00:00', null, 'بعد از بازه');

        $this->actingAs($user)->get(route('website.wallet.show', ['from' => '2026-03-10', 'to' => '2026-03-12']))
            ->assertOk()->assertSee('اول بازه')->assertSee('آخر بازه')
            ->assertDontSee('قبل از بازه')->assertDontSee('بعد از بازه');

        // «از» بعد از «تا» ⇒ همان بازه، نه نتیجه‌ی خالی
        $this->actingAs($user)->get(route('website.wallet.show', ['from' => '2026-03-12', 'to' => '2026-03-10']))
            ->assertOk()->assertSee('اول بازه')->assertSee('آخر بازه')->assertDontSee('بعد از بازه');
    }

    #[Test]
    public function invalid_filter_values_are_ignored_not_rejected(): void
    {
        [$user, $c] = $this->customer();
        $this->tx($c, 'charge', 90000, null, null, 'شارژ تستی سه');

        $this->actingAs($user)->get(route('website.wallet.show', [
            'direction' => 'sideways',
            'type' => 'drop table',
            'from' => '2026-02-31', // تاریخ غیرواقعی
            'to' => ['x'],          // آرایه
        ]))->assertOk()->assertSee('شارژ تستی سه');

        $f = WalletCenterFilter::fromInput(['direction' => ['in'], 'type' => 5, 'from' => 'yesterday']);
        $this->assertFalse($f->isActive());
        $this->assertSame([], $f->toQuery());
    }

    #[Test]
    public function an_empty_filtered_result_says_so_and_offers_to_clear_the_filter(): void
    {
        [$user, $c] = $this->customer();
        $this->tx($c, 'charge', 90000);

        $this->actingAs($user)->get(route('website.wallet.show', ['type' => 'refund']))
            ->assertOk()
            ->assertSee('تراکنشی با این فیلتر پیدا نشد')
            ->assertSee('حذف فیلتر');
    }

    #[Test]
    public function pagination_links_carry_only_the_sanitized_filter(): void
    {
        [$user, $c] = $this->customer();

        for ($i = 0; $i < WalletCenterService::PER_PAGE + 3; $i++) {
            $this->tx($c, 'charge', 1000 + $i);
        }

        $html = $this->actingAs($user)
            ->get(route('website.wallet.show', ['type' => 'charge', 'evil' => '<x>', 'direction' => 'bogus']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('type=charge', $html);
        $this->assertStringContainsString('page=2', $html);
        $this->assertStringNotContainsString('evil', $html);
        $this->assertStringNotContainsString('bogus', $html);
    }

    // --- Isolation ---

    #[Test]
    public function wallets_never_leak_across_store_contexts_or_users(): void
    {
        $reseller = Reseller::factory()->create();
        [$user, $main] = $this->customer();
        [, $inShop] = $this->customer(StoreContext::reseller($reseller), $user);
        [, $other] = $this->customer();

        $this->tx($main, 'charge', 11000, null, null, 'تراکنش اصلی من');
        $this->tx($inShop, 'charge', 22000, null, null, 'تراکنش فروشگاه من');
        $this->tx($other, 'charge', 33000, null, null, 'تراکنش دیگری');

        $this->actingAs($user)->get(route('website.wallet.show'))
            ->assertOk()->assertSee('تراکنش اصلی من')
            ->assertDontSee('تراکنش فروشگاه من')->assertDontSee('تراکنش دیگری');

        $this->actingAs($user)->get(route('website.store.wallet.show', $reseller->slug))
            ->assertOk()->assertSee('تراکنش فروشگاه من')
            ->assertDontSee('تراکنش اصلی من')->assertDontSee('تراکنش دیگری');
    }

    // --- توضیح و لینک سفارش ---

    #[Test]
    public function a_referral_bonus_never_shows_the_invited_persons_name(): void
    {
        [$user, $c] = $this->customer();
        $this->tx($c, 'referral_bonus', 5000, null, null, 'پاداش دعوت: عضویت نام محرمانه');
        $this->tx($c, 'charge', 7000, null, null, 'شارژ کیف پول — پرداخت #7');

        $this->actingAs($user)->get(route('website.wallet.show'))
            ->assertOk()
            ->assertSee('پاداش معرفی')
            ->assertSee('پرداخت #7')
            ->assertDontSee('نام محرمانه');
    }

    #[Test]
    public function an_order_link_appears_only_for_the_owners_own_order_in_this_context(): void
    {
        [$user, $c] = $this->customer();
        [$stranger, $strangerAccount] = $this->customer();
        $this->wallets->charge($c, 100000);

        $mine = $this->order($user, $c);
        $theirs = $this->order($stranger, $strangerAccount);

        $this->tx($c, 'purchase', 10000, null, $mine, "خرید من — سفارش #{$mine->id}");
        $this->tx($c, 'purchase', 20000, null, $theirs, "ارجاع به سفارش دیگری #{$theirs->id}");

        $html = $this->actingAs($user)->get(route('website.wallet.show'))->assertOk()->getContent();

        $this->assertStringContainsString(route('website.orders.show', $mine->id), $html);
        $this->assertStringNotContainsString(route('website.orders.show', $theirs->id), $html);

        // و لینک واقعاً کار می‌کند
        $this->actingAs($user)->get(route('website.orders.show', $mine->id))->assertOk();
    }

    #[Test]
    public function an_order_link_in_a_reseller_store_points_to_the_store_route(): void
    {
        $reseller = Reseller::factory()->create();
        [$user, $c] = $this->customer(StoreContext::reseller($reseller));
        $this->wallets->charge($c, 100000);
        $order = $this->order($user, $c);
        $this->tx($c, 'purchase', 10000, null, $order, 'خرید در فروشگاه');

        $this->actingAs($user)->get(route('website.store.wallet.show', $reseller->slug))
            ->assertOk()
            ->assertSee(route('website.store.orders.show', [$reseller->slug, $order->id]), false);
    }

    // --- شارژهای اخیر ---

    #[Test]
    public function each_pending_charge_says_what_it_is_waiting_for(): void
    {
        [$user] = $this->customer();
        $zarinpal = PaymentMethod::factory()->zarinpal()->create();

        $awaitingReceipt = $this->payment($user, ['amount' => 11000]);
        $underReview = $this->payment($user, ['amount' => 12000, 'receipt_image' => 'website:r/1.jpg', 'depositor_name' => 'علی']);
        $gateway = $this->payment($user, ['amount' => 13000, 'payment_method_id' => $zarinpal->id]);
        $abandoned = $this->payment($user, ['amount' => 14000, 'payment_method_id' => $zarinpal->id]);
        $this->backdate($abandoned, now()->subHours(WalletCenterService::GATEWAY_STALE_HOURS + 1)->toDateTimeString());

        $byId = app(WalletCenterService::class)->charges($user, StoreContext::main())->keyBy('paymentId');

        $this->assertSame(WalletChargeEntry::AWAITING_RECEIPT, $byId[$awaitingReceipt->id]->state);
        $this->assertSame(WalletChargeEntry::UNDER_REVIEW, $byId[$underReview->id]->state);
        $this->assertSame(WalletChargeEntry::AWAITING_GATEWAY, $byId[$gateway->id]->state);
        $this->assertSame(WalletChargeEntry::ABANDONED, $byId[$abandoned->id]->state);

        // فقط سه مورد اول «در انتظار»اند؛ درگاه رهاشده شمرده نمی‌شود.
        $o = app(WalletCenterService::class)->overview($user, StoreContext::main());
        $this->assertSame(3, $o->pendingCount);
        $this->assertSame(11000 + 12000 + 13000, $o->pendingAmount);

        $this->actingAs($user)->get(route('website.wallet.show'))
            ->assertOk()
            ->assertSee('در انتظار ثبت رسید')
            ->assertSee('در حال بررسی')
            ->assertSee('در انتظار نتیجه‌ی درگاه')
            ->assertSee('ناتمام');
    }

    #[Test]
    public function the_receipt_link_is_offered_only_while_a_receipt_is_missing(): void
    {
        [$user] = $this->customer();

        $needs = $this->payment($user);
        $done = $this->payment($user, ['receipt_image' => 'website:r/2.jpg']);
        $confirmed = $this->payment($user, ['status' => 'confirmed']);

        $html = $this->actingAs($user)->get(route('website.wallet.show'))->assertOk()->getContent();

        $this->assertStringContainsString(route('website.wallet.receipt.show', $needs->id), $html);
        $this->assertStringNotContainsString(route('website.wallet.receipt.show', $done->id), $html);
        $this->assertStringNotContainsString(route('website.wallet.receipt.show', $confirmed->id), $html);

        // لینک ارائه‌شده واقعاً به صفحه‌ی ثبت رسید می‌رسد
        $this->actingAs($user)->get(route('website.wallet.receipt.show', $needs->id))->assertOk();
    }

    #[Test]
    public function final_charge_states_are_labelled_and_not_counted_as_pending(): void
    {
        [$user] = $this->customer();
        $this->payment($user, ['status' => 'confirmed']);
        $this->payment($user, ['status' => 'rejected']);
        $this->payment($user, ['status' => 'refunded']);

        $states = app(WalletCenterService::class)->charges($user, StoreContext::main())->pluck('state')->sort()->values()->all();
        $this->assertSame([WalletChargeEntry::CONFIRMED, WalletChargeEntry::REFUNDED, WalletChargeEntry::REJECTED], $states);
        $this->assertSame(0, app(WalletCenterService::class)->overview($user, StoreContext::main())->pendingCount);
    }

    #[Test]
    public function charges_of_other_contexts_other_users_and_other_purposes_never_appear(): void
    {
        $reseller = Reseller::factory()->create();
        [$user] = $this->customer();
        [$stranger] = $this->customer();

        $mine = $this->payment($user, ['amount' => 41000]);
        $this->payment($user, ['amount' => 42000, 'reseller_id' => $reseller->id]);          // فروشگاه دیگر
        $this->payment($stranger, ['amount' => 43000]);                                       // کاربر دیگر
        $this->payment($user, ['amount' => 44000, 'purpose' => 'order']);                      // مقصد دیگر
        $this->payment($user, ['amount' => 45000, 'wallet_owner_type' => 'reseller', 'reseller_id' => $reseller->id]); // اعتبار نماینده

        $ids = app(WalletCenterService::class)->charges($user, StoreContext::main())->pluck('paymentId')->all();
        $this->assertSame([$mine->id], $ids);

        $shop = app(WalletCenterService::class)->charges($user, StoreContext::reseller($reseller))->pluck('amount')->all();
        $this->assertSame([42000], $shop);
    }

    #[Test]
    public function the_receipt_link_in_a_reseller_store_uses_the_store_route(): void
    {
        $reseller = Reseller::factory()->create();
        [$user] = $this->customer(StoreContext::reseller($reseller));
        $payment = $this->payment($user, ['reseller_id' => $reseller->id]);

        $this->actingAs($user)->get(route('website.store.wallet.show', $reseller->slug))
            ->assertOk()
            ->assertSee(route('website.store.wallet.receipt.show', [$reseller->slug, $payment->id]), false);
    }

    #[Test]
    public function the_dashboard_and_the_wallet_center_agree_on_pending_charges(): void
    {
        [$user] = $this->customer();
        $zarinpal = PaymentMethod::factory()->zarinpal()->create();

        $abandoned = $this->payment($user, ['payment_method_id' => $zarinpal->id]);
        $this->backdate($abandoned, now()->subDays(3)->toDateTimeString());

        $snapshot = fn () => app(CustomerDashboardService::class)->snapshot(
            $user, StoreContext::main(), $this->identity->findCustomerAccount($user, StoreContext::main())
        );

        // درگاه رهاشده ⇒ نه اعلان داشبورد، نه شمارنده‌ی کیف‌پول
        $this->assertNull($snapshot()->notices->firstWhere('key', 'payment.pending'));
        $this->assertSame(0, app(WalletCenterService::class)->pendingChargeCount($user, StoreContext::main()));

        $this->payment($user); // کارت‌به‌کارت منتظر رسید

        $this->assertNotNull($snapshot()->notices->firstWhere('key', 'payment.pending'));
        $this->assertSame(1, app(WalletCenterService::class)->pendingChargeCount($user, StoreContext::main()));
    }

    // --- صفحه‌ی شارژ ---

    #[Test]
    public function the_charge_page_shows_balance_minimum_and_presets_above_the_minimum(): void
    {
        [$user, $c] = $this->customer();
        $this->wallets->charge($c, 123000);
        PaymentMethod::factory()->create();

        $html = $this->actingAs($user)->get(route('website.wallet.charge.show'))->assertOk()
            ->assertSee('123,000')
            ->assertSee('حداقل مبلغ شارژ')
            ->getContent();

        // پیش‌فرض‌ها ۵٬۰۰۰ (کمتر از حداقل ۱۰٬۰۰۰ ⇒ حذف)، ۵۰٬۰۰۰ و ۲۰۰٬۰۰۰
        $this->assertStringContainsString('amount=50000', $html);
        $this->assertStringContainsString('amount=200000', $html);
        $this->assertStringNotContainsString('amount=5000"', $html);
    }

    #[Test]
    public function a_preset_prefills_the_amount_and_keeps_the_return_product(): void
    {
        $user = User::factory()->create();
        PaymentMethod::factory()->create();

        $this->actingAs($user)->get(route('website.wallet.charge.show', ['amount' => '50000', 'product' => 77]))
            ->assertOk()
            ->assertSee('value="50000"', false)
            ->assertSee('product=77', false);
    }

    #[Test]
    public function an_invalid_or_too_small_prefill_is_ignored(): void
    {
        $user = User::factory()->create();
        PaymentMethod::factory()->create();

        foreach (['abc', '5000', '-1', '1.5', ''] as $bad) {
            $this->actingAs($user)->get(route('website.wallet.charge.show', ['amount' => $bad]))
                ->assertOk()
                ->assertDontSee('value="'.$bad.'"', false);
        }

        $this->actingAs($user)->get(route('website.wallet.charge.show', ['amount' => ['50000']]))->assertOk();
    }

    #[Test]
    public function the_charge_form_still_posts_the_same_contract(): void
    {
        $user = User::factory()->create();
        $method = PaymentMethod::factory()->create();

        $html = $this->actingAs($user)->get(route('website.wallet.charge.show', ['product' => 5]))->assertOk()->getContent();

        $this->assertStringContainsString('name="amount"', $html);
        $this->assertStringContainsString('name="payment_method_id"', $html);
        $this->assertStringContainsString('name="return_product" value="5"', $html);
        // یک روش فعال ⇒ از پیش انتخاب‌شده
        $this->assertMatchesRegularExpression('/value="'.$method->id.'"[^>]*checked/s', $html);
    }

    // --- فیلتر (واحد) ---

    #[Test]
    public function the_filter_normalizes_dates_to_whole_days(): void
    {
        $f = WalletCenterFilter::fromInput(['from' => '2026-03-10', 'to' => '2026-03-12', 'direction' => 'in', 'type' => 'charge']);

        $this->assertSame('2026-03-10 00:00:00', $f->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-12 23:59:59', $f->to->format('Y-m-d H:i:s'));
        $this->assertSame(['direction' => 'in', 'type' => 'charge', 'from' => '2026-03-10', 'to' => '2026-03-12'], $f->toQuery());
        $this->assertTrue($f->isActive());
    }

    // --- تقویم شمسی + میلادی ---

    #[Test]
    public function jalali_conversion_matches_known_dates(): void
    {
        $cases = [
            '2025-03-21' => '1404/01/01', '2026-03-21' => '1405/01/01', '2024-03-20' => '1403/01/01',
            '2026-10-04' => '1405/07/12', '2026-03-20' => '1404/12/29', '2025-03-20' => '1403/12/30', // 1403 کبیسه
            '2000-01-01' => '1378/10/11', '2026-12-31' => '1405/10/10',
        ];

        foreach ($cases as $gregorian => $jalali) {
            $this->assertSame($jalali, JalaliDate::format(CarbonImmutable::parse($gregorian)), $gregorian);
        }
    }

    #[Test]
    public function the_wallet_center_shows_both_calendars_for_transactions_charges_and_filter_dates(): void
    {
        [$user, $c] = $this->customer();
        $this->tx($c, 'charge', 20000, '2026-10-04 14:30:00', null, 'شارژ دو تقویمی');
        $payment = $this->payment($user);
        $this->backdate($payment, '2026-10-04 09:05:00');

        $this->actingAs($user)->get(route('website.wallet.show', ['from' => '2026-10-04', 'to' => '2026-10-04']))
            ->assertOk()
            ->assertSee('1405/07/12 14:30')   // شمسی تراکنش
            ->assertSee('2026-10-04 14:30')   // میلادی تراکنش
            ->assertSee('1405/07/12 شمسی');   // راهنمای فیلتر

        $this->actingAs($user)->get(route('website.wallet.show'))
            ->assertSee('1405/07/12 09:05')   // شمسی شارژ
            ->assertSee('2026-10-04 09:05');  // میلادی شارژ
    }
}
