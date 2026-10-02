<?php

namespace Tests\Feature\Website;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FakesTelegram;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * D-3 / Master 7.2 (شکاف C13): بعد از شارژ موفق، صفحه‌ی callback
 * لینک بازگشت به Checkout دارد و خرید خودکار انجام نمی‌شود.
 */
class ChargeReturnToCheckoutTest extends TestCase
{
    use FakesTelegram, InteractsWithWebsiteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // listener تأیید پرداخت Telegram Api را از Container می‌سازد؛ بدون موک، Api واقعی بدون توکن خطا می‌دهد.
        $this->fakeTelegram();
    }

    protected function verifiedUser(): User
    {
        return User::factory()->create([
            'telegram_id' => null, 'email' => 'charger@example.test', 'password' => 'a-strong-password',
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function the_checkout_page_links_to_charge_with_the_product_to_return_to(): void
    {
        $product = $this->makeSellableProduct(mainPrice: 500000);
        $this->makeCardToCardMethod(); // فرم شارژ فقط با حداقل یک روش پرداخت فعال نمایش داده می‌شود
        $user = $this->verifiedUser();

        $this->actingAs($user)->get(route('website.checkout.show', $product->id))
            ->assertOk()
            ->assertSee(route('website.wallet.charge.show', ['product' => $product->id]), false);

        $this->get(route('website.wallet.charge.show', ['product' => $product->id]))
            ->assertOk()
            ->assertSee('name="return_product"', false);
    }

    #[Test]
    public function after_a_confirmed_gateway_payment_the_callback_page_links_back_to_checkout_without_purchasing(): void
    {
        Http::fake([
            '*/payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'AUTH123']], 200),
            '*/payment/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 998877]], 200),
        ]);
        $product = $this->makeSellableProduct(mainPrice: 100000);
        $method = $this->makeZarinpalMethod();
        $user = $this->verifiedUser();

        $this->actingAs($user)->post(route('website.wallet.charge.store'), [
            'amount' => 150000, 'payment_method_id' => $method->id, 'return_product' => $product->id,
        ])->assertRedirect();

        $payment = Payment::query()->where('user_id', $user->id)->firstOrFail();

        $this->get('/payment/zarinpal/callback?payment_id='.$payment->id.'&Authority=AUTH123&Status=OK')
            ->assertOk()
            ->assertSee('بازگشت به تکمیل خرید')
            ->assertSee(route('website.checkout.show', $product->id), false);

        // D-3: خرید خودکار ممنوع — فقط کیف‌پول شارژ شد.
        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals('confirmed', $payment->fresh()->status);
    }

    #[Test]
    public function a_failed_payment_does_not_show_the_return_link(): void
    {
        Http::fake([
            '*/payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'AUTH123']], 200),
        ]);
        $product = $this->makeSellableProduct(mainPrice: 100000);
        $method = $this->makeZarinpalMethod();
        $user = $this->verifiedUser();

        $this->actingAs($user)->post(route('website.wallet.charge.store'), [
            'amount' => 150000, 'payment_method_id' => $method->id, 'return_product' => $product->id,
        ]);
        $payment = Payment::query()->firstOrFail();

        $this->get('/payment/zarinpal/callback?payment_id='.$payment->id.'&Authority=AUTH123&Status=NOK')
            ->assertOk()
            ->assertDontSee('بازگشت به تکمیل خرید');
    }

    #[Test]
    public function an_unknown_or_unavailable_product_id_is_ignored_and_never_becomes_a_link(): void
    {
        Http::fake([
            '*/payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'AUTH123']], 200),
            '*/payment/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 1]], 200),
        ]);
        $method = $this->makeZarinpalMethod();
        $user = $this->verifiedUser();

        $this->actingAs($user)->post(route('website.wallet.charge.store'), [
            'amount' => 150000, 'payment_method_id' => $method->id, 'return_product' => 999999,
        ]);
        $payment = Payment::query()->firstOrFail();

        $this->get('/payment/zarinpal/callback?payment_id='.$payment->id.'&Authority=AUTH123&Status=OK')
            ->assertOk()
            ->assertDontSee('بازگشت به تکمیل خرید');
    }
}
