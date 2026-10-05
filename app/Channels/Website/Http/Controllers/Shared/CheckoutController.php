<?php

namespace App\Channels\Website\Http\Controllers\Shared;

use App\Channels\Website\Http\Controllers\Guest\GuestCheckoutController;
use App\Channels\Website\Services\WebsiteCatalogFacade;
use App\Channels\Website\Services\WebsiteGuestCheckoutFacade;
use App\Channels\Website\Services\WebsitePurchaseFacade;
use App\Channels\Website\Services\WebsiteWalletFacade;
use App\Channels\Website\Support\CoreErrorMapper;
use App\Channels\Website\Support\GuestCheckoutContinuation;
use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Models\PaymentMethod;
use App\Services\Core\Store\EmailNotVerifiedException;
use App\Services\Core\Store\EmailVerificationGate;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * «Checkout مستقیم بدون Cart» — `Product → Checkout`، فقط پرداخت از
 * کیف‌پول (Wallet Payment). Zarinpal و Card-to-Card کیف‌پول را شارژ
 * می‌کنند (ChargeController)؛ بعد از شارژ، کاربر به همین Checkout
 * برمی‌گردد. کنترلر هیچ Business Logic مستقلی ندارد.
 *
 * Guest Checkout از این کنترلر استفاده نمی‌کند؛ این کنترلر فقط پشت
 * `auth` + `store.customer` سوار است.
 */
class CheckoutController
{
    use ResolvesWebsiteRouteNames;

    public function __construct(
        protected WebsiteCatalogFacade $catalog,
        protected WebsiteWalletFacade $wallet,
        protected WebsitePurchaseFacade $purchase,
        protected CoreErrorMapper $errors,
        protected IdentityService $identity,
        protected EmailVerificationGate $emailGate,
        protected GuestCheckoutContinuation $guestContinuation,
        protected WebsiteGuestCheckoutFacade $guest,
    ) {}

    /**
     * صفحه‌ی تایید خرید. توکن Idempotency اینجا (نه در store()) ساخته
     * و در فرم به‌صورت hidden قرار می‌گیرد — «از الگوی
     * idempotencyKey که در Bot/Core از قبل هست استفاده شود». هر بار
     * تازه‌سازی این صفحه یک توکن جدید می‌سازد (الگوی استاندارد
     * Synchronizer Token)، پس Submit دوبار همان فرم (مثلاً با دکمه‌ی
     * Back مرورگر) دقیقاً همان Operation قبلی را idempotent برمی‌گرداند
     * و خرید دوم واقعی رخ نمی‌دهد.
     */
    public function show(Request $request, int $product, StoreContext $store): View|Response
    {
        $item = $this->catalog->item($product, $store);

        if (! $item) {
            abort(404);
        }

        // B4.4: پیش‌فاکتور از Core (کمبود، موجودی پس از خرید، مبلغ پیشنهادی شارژ)؛ این‌جا هیچ حسابی نیست.
        $quote = $this->wallet->quote($request->user(), $store, $item->price);

        // «ادامه‌ی خرید مهمان»: فقط برای نمایش مراحل (۳ مرحله‌ای)؛ مصرف نشست همچنان بعد از خرید موفق است.
        $guest = $this->guestContinuation->activeFor($request, $store);

        return view('website.shared.checkout-show', [
            'item' => $item,
            'product' => $item->product,
            'price' => $item->price,
            'balance' => $quote->balance,
            'quote' => $quote,
            'topup' => $quote->suggestedTopup(),
            'canCharge' => PaymentMethod::query()->where('status', 'active')->exists(),
            'fromGuest' => $guest !== null && (int) $guest->product_id === $item->id(),
            'store' => $store,
            'idempotencyToken' => (string) Str::uuid(),
            'route' => fn (string $name, array $params = []) => $store->isReseller()
                ? route('website.store.'.$name, ['slug' => $store->reseller->slug, ...$params])
                : route('website.'.$name, $params),
        ]);
    }

    public function store(Request $request, int $product, StoreContext $store): RedirectResponse
    {
        $request->validate([
            'idempotency_token' => ['required', 'string', 'max:64'],
        ]);

        $productModel = $this->catalog->findVisibleProduct($product, $store);

        if (! $productModel) {
            abort(404);
        }

        // Master G11: Email تأییدنشده ⇒ هدایت به صفحه‌ی تأیید، *قبل* از
        // ساخت CustomerAccount. Enforcement اصلی داخل PurchaseService است؛
        // این فقط UX + جلوگیری از ساخت عضویت بی‌مصرف است.
        try {
            $this->emailGate->assertVerified($request->user());
        } catch (EmailNotVerifiedException) {
            return $this->redirectToVerification($request, $productModel->id);
        }

        // CustomerAccount در لحظه‌ی خرید (Lazy) Resolve/ساخته می‌شود — نه
        // توسط میان‌افزار روی یک GET. تنها استثنا: ورود موفق (Google یا Email+Password) که
        // عضویت فروشگاه مبدأ را از قبل می‌سازد (G21)؛ این Resolve آن را
        // بی‌تکرار پیدا می‌کند.
        $customer = $this->identity->resolveCustomerAccount($request->user(), $store);

        try {
            $account = $this->purchase->purchaseWithWallet(
                customer: $customer,
                product: $productModel,
                store: $store,
                idempotencyKey: 'website:'.$request->string('idempotency_token'),
            );
        } catch (EmailNotVerifiedException) {
            return $this->redirectToVerification($request, $productModel->id);
        } catch (Throwable $e) {
            $mapped = $this->errors->map($e);

            return back()->withErrors(['checkout' => $mapped['message'].' (کد پیگیری: '.$mapped['reference'].')']);
        }

        // G6/G9: همان خرید Pending تکمیل شد ⇒ نشست Guest مصرف می‌شود.
        $guest = $this->guestContinuation->activeFor($request, $store);

        if ($guest && $guest->product_id === $productModel->id) {
            $this->guest->consume($guest, $request->user());
        }

        $routeName = $store->isReseller() ? 'website.store.orders.show' : 'website.orders.show';
        $routeParams = $store->isReseller()
            ? ['slug' => $store->reseller->slug, 'order' => $account->order_id]
            : ['order' => $account->order_id];

        return redirect()->route($routeName, $routeParams)
            ->with('status', 'خرید با موفقیت ثبت شد.')
            ->withoutCookie(GuestCheckoutController::COOKIE_NAME);
    }

    /** بعد از تأیید Email، کاربر به همین Checkout برمی‌گردد (url.intended). */
    protected function redirectToVerification(Request $request, int $productId): RedirectResponse
    {
        $request->session()->put('url.intended', $this->websiteRoute($request, 'checkout.show', ['product' => $productId]));

        return redirect()->route('verification.notice')
            ->with('status', 'برای خرید ابتدا ایمیل خود را تأیید کنید.');
    }
}
