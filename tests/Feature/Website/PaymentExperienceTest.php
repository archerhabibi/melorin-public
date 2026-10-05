<?php

namespace Tests\Feature\Website;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\ResellerProductPrice;
use App\Models\User;
use App\Services\Core\Payments\CheckoutQuote;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FakesTelegram;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * B4.4 — Payment Experience. قرارداد مالی (Wallet-only Checkout، شارژ جدا، بدون خرید خودکار D-3) دست‌نخورده است؛
 * فقط تجربه: کمبود دقیق + شارژ یک‌کلیکی همان مبلغ، خلاصه‌ی خرید در صفحه‌ی شارژ، صفحه‌ی رسید راهنما، صفحه‌ی نتیجه‌ی درگاه.
 */
class PaymentExperienceTest extends TestCase
{
    use FakesTelegram, InteractsWithWebsiteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();
    }

    protected function customer(int $balance = 0): User
    {
        $user = User::factory()->create(['telegram_id' => null, 'email_verified_at' => now()]);

        if ($balance > 0) {
            $customer = app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());
            app(WalletService::class)->credit($customer, $balance);
        }

        return $user;
    }

    // ─── CheckoutQuote (Core، خالص) ───────────────────────────────

    #[Test]
    public function the_quote_derives_shortfall_balance_after_and_suggested_topup(): void
    {
        $short = new CheckoutQuote(price: 120000, balance: 45000);
        $this->assertFalse($short->isAffordable());
        $this->assertSame(75000, $short->shortfall());
        $this->assertNull($short->balanceAfter());
        // کمبود از حداقل شارژ بیشتر ⇒ دقیقاً کمبود؛ کمتر ⇒ حداقل
        $this->assertSame(75000, $short->suggestedTopup(minimum: 10000));
        $this->assertSame(100000, $short->suggestedTopup(minimum: 100000));

        $enough = new CheckoutQuote(price: 120000, balance: 200000);
        $this->assertTrue($enough->isAffordable());
        $this->assertSame(0, $enough->shortfall());
        $this->assertSame(80000, $enough->balanceAfter());
        $this->assertNull($enough->suggestedTopup());

        $exact = new CheckoutQuote(price: 100, balance: 100);
        $this->assertTrue($exact->isAffordable());
        $this->assertSame(0, $exact->balanceAfter());
    }

    // ─── Checkout ─────────────────────────────────────────────────

    #[Test]
    public function checkout_with_insufficient_balance_shows_the_exact_shortfall_and_a_one_click_topup(): void
    {
        $product = $this->makeSellableProduct(mainPrice: 120000);
        $this->makeCardToCardMethod();
        $user = $this->customer(balance: 45000);

        $this->actingAs($user)->get(route('website.checkout.show', $product->id))
            ->assertOk()
            ->assertSee('کم است')
            ->assertSee(Money::format(75000))
            ->assertSee(e(route('website.wallet.charge.show', ['product' => $product->id, 'amount' => Money::toMajorString(75000)])), false)
            ->assertDontSee('idempotency_token', false);
    }

    #[Test]
    public function a_tiny_shortfall_is_raised_to_the_minimum_topup(): void
    {
        $product = $this->makeSellableProduct(mainPrice: 120000);
        $this->makeCardToCardMethod();
        $user = $this->customer(balance: 119000); // کمبود ۱۰۰۰ < حداقل شارژ

        $this->actingAs($user)->get(route('website.checkout.show', $product->id))
            ->assertOk()
            ->assertSee(e(route('website.wallet.charge.show', ['product' => $product->id, 'amount' => Money::toMajorString(Money::minTopup())])), false);
    }

    #[Test]
    public function checkout_without_any_payment_method_says_so_instead_of_offering_a_dead_link(): void
    {
        $product = $this->makeSellableProduct(mainPrice: 120000);
        $user = $this->customer(balance: 1000);

        $this->actingAs($user)->get(route('website.checkout.show', $product->id))
            ->assertOk()
            ->assertSee('هیچ روش پرداختی')
            ->assertDontSee(route('website.wallet.charge.show', ['product' => $product->id]), false);
    }

    #[Test]
    public function checkout_with_enough_balance_shows_balance_after_and_a_lockable_pay_form(): void
    {
        $product = $this->makeSellableProduct(mainPrice: 120000);
        $user = $this->customer(balance: 200000);

        $this->actingAs($user)->get(route('website.checkout.show', $product->id))
            ->assertOk()
            ->assertSee('موجودی پس از خرید')
            ->assertSee(Money::format(80000))
            ->assertSee('name="idempotency_token"', false)
            ->assertSee('data-submit-lock', false)
            ->assertSee('data-busy-text', false)
            ->assertDontSee('کم است');
    }

    #[Test]
    public function checkout_for_a_sold_out_product_offers_no_payment_form(): void
    {
        $product = $this->makeSellableProduct(overrides: ['sale_limit' => 2, 'units_sold' => 2]);
        $user = $this->customer(balance: 999999);

        $this->actingAs($user)->get(route('website.checkout.show', $product->id))
            ->assertOk()
            ->assertSee('ظرفیت فروش این تعرفه تکمیل شده است')
            ->assertDontSee('idempotency_token', false);
    }

    #[Test]
    public function the_checkout_after_a_guest_session_shows_step_three(): void
    {
        $product = $this->makeSellableProduct();
        $user = $this->customer(balance: 500000);

        $this->post(route('website.guest-checkout.store', $product->id), ['guest_email' => 'g@example.test']);
        $token = \App\Models\GuestCheckout::query()->latest('id')->firstOrFail()->token;

        $this->actingAs($user)->withCookie('guest_checkout_token', $token)
            ->get(route('website.checkout.show', $product->id))
            ->assertOk()
            ->assertSee('مرحله‌ی 3 از 3');

        // کاربر عادی (بدون نشست مهمان) مراحل ندارد.
        $this->flushSession();
        $this->actingAs($user)->withCookie('guest_checkout_token', '')
            ->get(route('website.checkout.show', $product->id))
            ->assertOk()
            ->assertDontSee('مرحله‌ی 3 از 3');
    }

    #[Test]
    public function a_guest_session_for_another_product_does_not_add_steps_to_this_checkout(): void
    {
        $started = $this->makeSellableProduct();
        $other = $this->makeSellableProduct(mainPrice: 90000);
        $user = $this->customer(balance: 500000);

        $this->post(route('website.guest-checkout.store', $started->id), ['guest_email' => 'g2@example.test']);
        $token = \App\Models\GuestCheckout::query()->latest('id')->firstOrFail()->token;

        $this->actingAs($user)->withCookie('guest_checkout_token', $token)
            ->get(route('website.checkout.show', $other->id))
            ->assertOk()
            ->assertDontSee('مرحله‌ی 3 از 3');
    }

    #[Test]
    public function the_purchase_itself_is_unchanged_and_still_wallet_only(): void
    {
        $this->fakeSanaeiPanel(); // Http::fake از اولین stub تطبیق‌دهنده استفاده می‌کند؛ پس فقط در همین تست.
        $product = $this->makeSellableProduct(mainPrice: 120000);
        $user = $this->customer(balance: 200000);

        $this->actingAs($user)->post(route('website.checkout.store', $product->id), ['idempotency_token' => 'tok-1'])
            ->assertRedirect();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    // ─── صفحه‌ی شارژ ──────────────────────────────────────────────

    #[Test]
    public function the_charge_page_from_checkout_shows_the_pending_purchase_and_prefills_the_shortfall(): void
    {
        $product = $this->makeSellableProduct(mainPrice: 300000);
        $this->makeCardToCardMethod();
        $user = $this->customer(balance: 100000);

        $this->actingAs($user)->get(route('website.wallet.charge.show', ['product' => $product->id]))
            ->assertOk()
            ->assertSee('خرید در انتظار')
            ->assertSee($product->name)
            ->assertSee('کمبود')
            ->assertSee(Money::format(200000))
            ->assertSee('value="'.Money::toMajorString(200000).'"', false)
            ->assertSee('name="return_product"', false);
    }

    #[Test]
    public function an_explicit_amount_wins_over_the_suggested_shortfall(): void
    {
        $product = $this->makeSellableProduct(mainPrice: 300000);
        $this->makeCardToCardMethod();
        $user = $this->customer();

        $this->actingAs($user)->get(route('website.wallet.charge.show', ['product' => $product->id, 'amount' => '500000']))
            ->assertOk()
            ->assertSee('value="500000"', false);
    }

    #[Test]
    public function the_charge_page_ignores_an_invisible_product_for_the_summary(): void
    {
        $product = $this->makeSellableProduct(mainPrice: 300000, overrides: ['status' => 'inactive']);
        $this->makeCardToCardMethod();
        $user = $this->customer();

        $this->actingAs($user)->get(route('website.wallet.charge.show', ['product' => $product->id]))
            ->assertOk()
            ->assertDontSee('خرید در انتظار')
            ->assertDontSee($product->name);
    }

    #[Test]
    public function the_plain_charge_page_has_no_pending_purchase_block(): void
    {
        $this->makeCardToCardMethod();

        $this->actingAs($this->customer())->get(route('website.wallet.charge.show'))
            ->assertOk()
            ->assertDontSee('خرید در انتظار')
            ->assertSee('data-submit-lock', false);
    }

    // ─── رسید کارت‌به‌کارت ────────────────────────────────────────

    protected function pendingCardPayment(User $user, ?int $returnProduct = null): Payment
    {
        Storage::fake('local');
        $method = PaymentMethod::factory()->create();

        $this->actingAs($user)->post(route('website.wallet.charge.store'), array_filter([
            'amount' => 150000,
            'payment_method_id' => $method->id,
            'return_product' => $returnProduct,
        ]));

        return Payment::query()->where('user_id', $user->id)->firstOrFail();
    }

    #[Test]
    public function the_receipt_page_guides_the_deposit_with_copy_helpers_and_file_rules(): void
    {
        $user = $this->customer();
        $payment = $this->pendingCardPayment($user);

        $this->actingAs($user)->get(route('website.wallet.receipt.show', $payment->id))
            ->assertOk()
            ->assertSee('مرحله‌ی 2 از 3')
            ->assertSee('در انتظار ثبت رسید')
            ->assertSee('مبلغ دقیق واریز')
            ->assertSee('data-copy-value="150000"', false)
            ->assertSee('data-copy-value="6037997512345678"', false)
            ->assertSee('حداکثر ۵ مگابایت')
            ->assertSee('for="f-receipt"', false)
            ->assertSee('data-submit-lock', false);
    }

    #[Test]
    public function after_uploading_the_receipt_the_page_shows_next_steps_and_the_way_back_to_the_purchase(): void
    {
        $product = $this->makeSellableProduct(mainPrice: 150000);
        $user = $this->customer();
        $payment = $this->pendingCardPayment($user, returnProduct: $product->id);

        $this->actingAs($user)->followingRedirects()
            ->from(route('website.wallet.receipt.show', $payment->id))
            ->post(route('website.wallet.receipt.store', $payment->id), [
                'depositor_name' => 'علی رضایی',
                'receipt' => UploadedFile::fake()->image('r.jpg'),
            ])
            ->assertOk()
            ->assertSee('مرحله‌ی 3 از 3')
            ->assertSee('در انتظار بررسی')
            ->assertSee('بازگشت به تکمیل خرید')
            ->assertSee(route('website.checkout.show', $product->id), false)
            ->assertSee('وضعیت شارژ در کیف‌پول')
            ->assertDontSee('data-submit-lock', false);
    }

    #[Test]
    public function without_a_pending_purchase_the_receipt_page_has_no_return_to_purchase_link(): void
    {
        $user = $this->customer();
        $payment = $this->pendingCardPayment($user);
        $payment->update(['receipt_image' => 'website:x/y.jpg']);

        $this->actingAs($user)->get(route('website.wallet.receipt.show', $payment->id))
            ->assertOk()
            ->assertDontSee('بازگشت به تکمیل خرید');
    }

    #[Test]
    public function the_receipt_page_still_hides_other_peoples_payments(): void
    {
        $owner = $this->customer();
        $payment = $this->pendingCardPayment($owner);

        $this->actingAs($this->customer())->get(route('website.wallet.receipt.show', $payment->id))->assertNotFound();
    }

    // ─── نتیجه‌ی درگاه ────────────────────────────────────────────

    protected function startGatewayPayment(User $user, array $extra = []): Payment
    {
        Http::fake([
            '*/payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'AUTH123']], 200),
            '*/payment/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 998877]], 200),
        ]);

        $this->actingAs($user)->post(route('website.wallet.charge.store'), [
            'amount' => 150000, 'payment_method_id' => $this->makeZarinpalMethod()->id,
        ] + $extra);

        return Payment::query()->where('user_id', $user->id)->firstOrFail();
    }

    #[Test]
    public function a_confirmed_gateway_result_shows_the_amount_and_next_steps(): void
    {
        $user = $this->customer();
        $payment = $this->startGatewayPayment($user);

        $this->get('/payment/zarinpal/callback?payment_id='.$payment->id.'&Authority=AUTH123&Status=OK')
            ->assertOk()
            ->assertSee('پرداخت موفق')
            ->assertSee(Money::format(150000))
            ->assertSee('مشاهده‌ی کیف‌پول')
            ->assertSee(route('website.wallet.show'), false)
            ->assertDontSee('تلاش دوباره برای شارژ');
    }

    #[Test]
    public function a_cancelled_gateway_result_offers_retry_without_crediting(): void
    {
        $user = $this->customer();
        $payment = $this->startGatewayPayment($user);

        $this->get('/payment/zarinpal/callback?payment_id='.$payment->id.'&Authority=AUTH123&Status=NOK')
            ->assertOk()
            ->assertSee('پرداخت ناموفق')
            ->assertSee('تلاش دوباره برای شارژ')
            ->assertSee(route('website.wallet.charge.show'), false);

        $this->assertNotEquals('confirmed', $payment->fresh()->status);
    }

    #[Test]
    public function a_reseller_store_payment_keeps_the_result_links_inside_the_store(): void
    {
        $product = $this->makeSellableProduct();
        $reseller = Reseller::factory()->create(['status' => 'active']);
        ResellerProductPrice::create(['reseller_id' => $reseller->id, 'product_id' => $product->id, 'customers_price' => 130000, 'is_enabled' => true]);
        Http::fake([
            '*/payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'AUTH123']], 200),
            '*/payment/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 998877]], 200),
        ]);
        $user = $this->customer();

        $this->actingAs($user)->post(route('website.store.wallet.charge.store', $reseller->slug), [
            'amount' => 150000, 'payment_method_id' => $this->makeZarinpalMethod()->id,
        ]);
        $payment = Payment::query()->where('user_id', $user->id)->firstOrFail();

        $this->get('/payment/zarinpal/callback?payment_id='.$payment->id.'&Authority=AUTH123&Status=OK')
            ->assertOk()
            ->assertSee(route('website.store.wallet.show', $reseller->slug), false)
            ->assertDontSee(route('website.wallet.show'), false);
    }

    #[Test]
    public function error_results_for_unknown_payments_show_only_the_generic_message(): void
    {
        $this->get('/payment/zarinpal/callback?payment_id=999999&Authority=X&Status=OK')
            ->assertNotFound()
            ->assertSee('پرداخت ناموفق')
            ->assertDontSee('مشاهده‌ی کیف‌پول')
            ->assertDontSee('تلاش دوباره برای شارژ');
    }
}
