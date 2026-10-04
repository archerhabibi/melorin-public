<?php

namespace Tests\Feature\Website;

use App\Models\GuestCheckout;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerProductPrice;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * B4.3 — Guest Checkout UX. Contract داده (Master §3: G2/G3/G5/G7) دست‌نخورده است؛ فقط تجربه‌ی کاربری:
 * مراحل، خلاصه‌ی خرید، ویرایش اطلاعات (Prefill)، محافظ ظرفیت تکمیل، فرم دسترس‌پذیر.
 */
class GuestCheckoutUxTest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    protected function startGuest(Product $product, array $extra = []): GuestCheckout
    {
        $this->post(route('website.guest-checkout.store', $product->id), ['guest_email' => 'ux@example.test'] + $extra);

        return GuestCheckout::query()->latest('id')->firstOrFail();
    }

    protected function soldOutProduct(): Product
    {
        return $this->makeSellableProduct(overrides: ['sale_limit' => 3, 'units_sold' => 3]);
    }

    #[Test]
    public function the_start_page_shows_steps_summary_and_the_login_shortcut(): void
    {
        $product = $this->makeSellableProduct(mainPrice: 150000, overrides: ['duration_days' => 30, 'traffic_gb' => 50]);

        $this->get(route('website.guest-checkout.show', $product->id))
            ->assertOk()
            ->assertSee('مرحله‌ی 1 از 3')
            ->assertSee('aria-current="step"', false)
            ->assertSee('خلاصه‌ی خرید')
            ->assertSee($product->name)
            ->assertSee(Money::format(150000))
            ->assertSee('30 روز')
            ->assertSee('قبلاً ثبت‌نام کرده‌اید؟')
            ->assertSee(route('website.login'), false)
            ->assertSee('هیچ حسابی ساخته نمی‌شود');
    }

    #[Test]
    public function the_form_fields_are_accessible_and_mobile_friendly(): void
    {
        $product = $this->makeSellableProduct();

        $this->get(route('website.guest-checkout.show', $product->id))
            ->assertOk()
            ->assertSee('autocomplete="email"', false)
            ->assertSee('inputmode="email"', false)
            ->assertSee('autocomplete="tel"', false)
            ->assertSee('autocomplete="name"', false)
            ->assertSee('for="f-guest_email"', false);
    }

    #[Test]
    public function validation_errors_are_shown_inline_with_aria_and_keep_the_input(): void
    {
        $product = $this->makeSellableProduct();

        $this->from(route('website.guest-checkout.show', $product->id))
            ->followingRedirects()
            ->post(route('website.guest-checkout.store', $product->id), [
                'guest_email' => 'not-an-email',
                'guest_name' => 'علی رضایی',
            ])
            ->assertOk()
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('id="e-guest_email"', false)
            ->assertSee('علی رضایی');

        $this->assertEquals(0, GuestCheckout::query()->count());
    }

    #[Test]
    public function the_pending_page_shows_step_two_and_offers_edit_and_cancel(): void
    {
        $product = $this->makeSellableProduct();
        $guest = $this->startGuest($product);

        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'))
            ->assertOk()
            ->assertSee('مرحله‌ی 2 از 3')
            ->assertSee('ویرایش اطلاعات')
            ->assertSee(route('website.guest-checkout.show', $product->id), false)
            ->assertSee('لغو و شروع دوباره');
    }

    #[Test]
    public function editing_prefills_the_form_from_the_active_session_and_replaces_it_on_submit(): void
    {
        $product = $this->makeSellableProduct();
        $first = $this->startGuest($product, ['guest_name' => 'مینا', 'guest_phone' => '09121230000']);

        $this->withCookie('guest_checkout_token', $first->token)
            ->get(route('website.guest-checkout.show', $product->id))
            ->assertOk()
            ->assertSee('value="ux@example.test"', false)
            ->assertSee('value="مینا"', false)
            ->assertSee('value="09121230000"', false);

        $this->withCookie('guest_checkout_token', $first->token)
            ->post(route('website.guest-checkout.store', $product->id), ['guest_email' => 'fixed@example.test'])
            ->assertRedirect(route('website.guest-checkout.pending'));

        // نشست قبلی باطل می‌شود (B2.3)، نه این‌که نشست Pending دوم کنارش بماند.
        $this->assertEquals('expired', $first->fresh()->status);
        $this->assertEquals(1, GuestCheckout::query()->where('status', 'pending')->count());
    }

    #[Test]
    public function the_form_is_empty_without_a_session_and_never_prefills_from_a_foreign_token(): void
    {
        $product = $this->makeSellableProduct();
        $guest = $this->startGuest($product, ['guest_name' => 'مینا']);

        // Cookie بی‌اعتبار (توکن ناشناس) ⇒ هیچ داده‌ای نشت نمی‌کند.
        $this->withCookie('guest_checkout_token', 'forged-'.$guest->token)
            ->get(route('website.guest-checkout.show', $product->id))
            ->assertOk()
            ->assertDontSee('ux@example.test')
            ->assertDontSee('مینا');
    }

    #[Test]
    public function a_sold_out_product_sends_the_guest_back_to_the_product_page_without_a_session(): void
    {
        $product = $this->soldOutProduct();

        $this->get(route('website.guest-checkout.show', $product->id))
            ->assertRedirect(route('website.products.show', $product->id))
            ->assertSessionHas('warning');

        $this->post(route('website.guest-checkout.store', $product->id), ['guest_email' => 'late@example.test'])
            ->assertRedirect(route('website.products.show', $product->id))
            ->assertSessionHas('warning');

        $this->assertEquals(0, GuestCheckout::query()->count());

        // پیام هشدار روی صفحه‌ی تعرفه دیده می‌شود.
        $this->followingRedirects()
            ->get(route('website.guest-checkout.show', $product->id))
            ->assertOk()
            ->assertSee('ظرفیت فروش این تعرفه تکمیل شده است');
    }

    #[Test]
    public function capacity_running_out_after_the_session_started_replaces_the_login_buttons_with_a_message(): void
    {
        $product = $this->makeSellableProduct(overrides: ['sale_limit' => 1, 'units_sold' => 0]);
        $guest = $this->startGuest($product);

        $product->forceFill(['units_sold' => 1])->save();

        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'))
            ->assertOk()
            ->assertSee('تکمیل شد')
            ->assertSee('مشاهده‌ی تعرفه‌های جایگزین')
            // (لینک «ثبت‌نام» هدر Layout همیشه هست؛ فقط CTAهای خودِ صفحه حذف می‌شوند.)
            ->assertDontSee('ایمیل واردشده به‌تنهایی')
            ->assertDontSee('برای تکمیل خرید، وارد حساب خود شوید')
            ->assertDontSee('ویرایش اطلاعات');
    }

    #[Test]
    public function a_logged_in_user_is_still_redirected_to_checkout_even_for_the_form(): void
    {
        $product = $this->makeSellableProduct();
        $user = User::factory()->create(['telegram_id' => null, 'email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('website.guest-checkout.show', $product->id))
            ->assertRedirect(route('website.checkout.show', $product->id));
    }

    #[Test]
    public function the_product_page_explains_the_two_ways_to_buy(): void
    {
        $product = $this->makeSellableProduct();

        $this->get(route('website.products.show', $product->id))
            ->assertOk()
            ->assertSee('برای خرید وارد شوید')
            ->assertSee('خرید به‌عنوان مهمان')
            ->assertSee('فقط به ایمیل نیاز دارد');
    }

    #[Test]
    public function reseller_pages_keep_every_link_inside_the_store(): void
    {
        $product = $this->makeSellableProduct();
        $reseller = Reseller::factory()->create(['status' => 'active']);
        ResellerProductPrice::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'customers_price' => 130000,
            'is_enabled' => true,
        ]);

        $this->get(route('website.store.guest-checkout.show', [$reseller->slug, $product->id]))
            ->assertOk()
            ->assertSee(Money::format(130000))
            ->assertSee(route('website.store.login', $reseller->slug), false)
            ->assertSee(route('website.store.products.show', [$reseller->slug, $product->id]), false)
            ->assertSee(route('website.store.guest-checkout.store', [$reseller->slug, $product->id]), false)
            ->assertDontSee(route('website.login'), false);

        $this->post(route('website.store.guest-checkout.store', [$reseller->slug, $product->id]), ['guest_email' => 'r@example.test'])
            ->assertRedirect(route('website.store.guest-checkout.pending', $reseller->slug));

        $guest = GuestCheckout::query()->latest('id')->firstOrFail();

        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.store.guest-checkout.pending', $reseller->slug))
            ->assertOk()
            ->assertSee(route('website.store.register', $reseller->slug), false)
            ->assertSee(route('website.store.guest-checkout.cancel', $reseller->slug), false)
            ->assertSee(route('website.store.guest-checkout.show', [$reseller->slug, $product->id]), false)
            ->assertDontSee(route('website.register'), false);
    }
}
