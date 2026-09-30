<?php

namespace Tests\Feature\Website;

use App\Models\GuestCheckout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * فاز W7 (نفر 5) - E2E صریح بند 63 زیرسند: Guest E2E.
 * مرجع: docs/VERIFICATION-MATRIX.md
 *
 * مسیر: بازدید ناشناس از محصول -> فرم مهمان (بدون هیچ حساب/ورودی) ->
 * توکن مهمان -> تکمیل به یک User ناقص و ورود خودکار -> رسیدن به همان
 * Checkout تست‌شده‌ی کاربر لاگین. زنجیره‌ی واقعی پرداخت (Zarinpal/
 * Card-to-Card) در WalletChargeFlowTest جدا پوشش داده شده - این E2E
 * تمرکزش روی خودِ مسیر Guest است، نه تکرار آن تست‌ها.
 */
class GuestE2ETest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    #[Test]
    public function an_anonymous_visitor_can_browse_and_start_a_purchase_without_any_account(): void
    {
        $product = $this->makeSellableProduct(mainPrice: 90000);

        // بازدید ناشناس - هیچ Cookie/Session ای از قبل نیست.
        $this->get(route('website.home'))->assertOk();
        $this->get(route('website.products.show', $product->id))
            ->assertOk()
            ->assertSee('مهمان');

        $this->get(route('website.guest-checkout.show', $product->id))->assertOk();

        $this->post(route('website.guest-checkout.store', $product->id), [
            'guest_name' => 'Reza Karimi',
            'guest_phone' => '09351112233',
        ]);

        $guest = GuestCheckout::query()->firstOrFail();
        $this->assertEquals($product->id, $guest->product_id);
        $this->assertEquals('pending', $guest->status);

        $pending = $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'));
        $pending->assertOk()->assertSee('Reza Karimi');

        $purchase = $this->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.guest-checkout.purchase'));

        $user = User::query()->where('phone', '09351112233')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $purchase->assertRedirect(route('website.checkout.show', $product->id));

        $this->assertEquals('consumed', $guest->fresh()->status);
    }
}
