<?php

namespace App\Channels\Website\Http\Controllers\Shared;

use App\Support\Money;
use App\Channels\Website\Services\WebsiteChargeFacade;
use App\Channels\Website\Services\WebsiteCatalogFacade;
use App\Channels\Website\Services\WebsiteWalletFacade;
use App\Channels\Website\Support\CoreErrorMapper;
use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Models\PaymentMethod;
use App\Services\Core\Store\EmailNotVerifiedException;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * صفحه‌ی شارژ کیف‌پول (Wallet Charge: Zarinpal / Card-to-Card). Checkout
 * همیشه Wallet را نشان می‌دهد؛ این کنترلر فقط وقتی وارد بازی می‌شود که
 * موجودی کافی نیست («کیف‌پولت را شارژ کن»)، جدا از فرایند Checkout.
 *
 * بعد از شارژ موفق (چه Zarinpal چه Card-to-Card)، کاربر باید خودش به
 * Checkout برگردد و «پرداخت از کیف‌پول» را دوباره بزند — عمداً
 * «شارژ خودکار و ادامه‌ی خرید» را پیاده نمی‌کند چون آن یعنی نگه‌داشتن
 * نیّتِ خرید بین دو درخواست HTTP (session) که خودش یک تصمیم امنیتی/
 * معماری جداست و در Contract فعلی مشخص نشده.
 */
class ChargeController
{
    use ResolvesWebsiteRouteNames;

    /** کلید Session برای بازگشت به Checkout بعد از شارژ (D-3؛ شکاف C13). */
    public const RETURN_SESSION_KEY = 'charge_return_checkout_url';

    public function __construct(
        protected WebsiteChargeFacade $charge,
        protected CoreErrorMapper $errors,
        protected WebsiteCatalogFacade $catalog,
        protected WebsiteWalletFacade $wallet,
    ) {}

    public function show(Request $request, StoreContext $store): View
    {
        $minimum = Money::minTopup();

        // B3.3: مبلغ پیش‌فرض از Query (دکمه‌های پیشنهادی بدون JS). فقط مقدار معتبر و ≥ حداقل پذیرفته می‌شود؛
        // این فقط مقدار اولیه‌ی فرم است و اعتبارسنجی واقعی همچنان در store() انجام می‌شود.
        $raw = $request->query('amount');
        $prefill = is_string($raw) ? Money::parse($raw) : null;
        $prefill = $prefill !== null && $prefill >= $minimum ? $prefill : null;

        $balance = $this->wallet->currentBalance($request->user(), $store);

        // B4.4: وقتی از Checkout آمده‌ایم، خلاصه‌ی همان خرید (و کمبود) بالای فرم نشان داده می‌شود و اگر کاربر
        // مبلغ نداده باشد، کمبود دقیق (حداقل = حداقل شارژ) پیش‌فرض است. فقط تعرفه‌ی قابل‌مشاهده در همین Store.
        $returnProductId = $request->integer('product') ?: null;
        $returnItem = $returnProductId ? $this->catalog->item($returnProductId, $store) : null;
        $quote = $returnItem ? $this->wallet->quote($request->user(), $store, $returnItem->price, readOnly: true) : null;

        if ($prefill === null && $quote) {
            $prefill = $quote->suggestedTopup($minimum);
        }

        return view('website.shared.wallet-charge', [
            'methods' => PaymentMethod::query()->where('status', 'active')->get(),
            'store' => $store,
            'returnProduct' => $returnProductId, // همان قرارداد قبلی؛ دیده‌بودن تعرفه در store() بررسی می‌شود
            'returnItem' => $returnItem,
            'quote' => $quote,
            'balance' => $balance,
            'minimum' => $minimum,
            'presets' => array_values(array_filter(Money::topupPresets(), fn (int $p) => $p >= $minimum)),
            'prefill' => $prefill,
        ]);
    }

    public function store(Request $request, StoreContext $store): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', function (string $attribute, mixed $value, \Closure $fail) {
                $minor = Money::parse((string) $value);

                if ($minor === null) {
                    $fail(Money::decimals() === 0
                        ? 'مبلغ باید عدد صحیح باشد.'
                        : 'مبلغ نامعتبر است (حداکثر '.Money::decimals().' رقم اعشار).');
                } elseif ($minor < Money::minTopup()) {
                    $fail('حداقل مبلغ شارژ '.Money::format(Money::minTopup()).' است.');
                }
            }],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'return_product' => ['nullable', 'integer'],
        ]);

        // D-3 (شکاف C13): بعد از شارژ، کاربر باید به Checkout همان Product
        // برگردد (خرید خودکار ممنوع). فقط شناسه‌ی Productی که در همین Store
        // قابل‌مشاهده است پذیرفته می‌شود؛ URL از کلاینت trusted نیست.
        $request->session()->forget(self::RETURN_SESSION_KEY);

        if (! empty($data['return_product']) && $this->catalog->findVisibleProduct((int) $data['return_product'], $store)) {
            $request->session()->put(
                self::RETURN_SESSION_KEY,
                $this->websiteRoute($request, 'checkout.show', ['product' => (int) $data['return_product']])
            );
        }

        $method = PaymentMethod::query()->where('status', 'active')->findOrFail($data['payment_method_id']);

        try {
            ['payment' => $payment, 'initiation' => $initiation] = $this->charge->charge(
                user: $request->user(),
                method: $method,
                amount: Money::toMinor((string) $data['amount']),
                store: $store,
            );
        } catch (EmailNotVerifiedException) {
            // G11: Wallet Charge تا تأیید Email مسدود است.
            $request->session()->put('url.intended', $this->websiteRoute($request, 'wallet.charge.show'));

            return redirect()->route('verification.notice')
                ->with('status', 'برای شارژ کیف‌پول ابتدا ایمیل خود را تأیید کنید.');
        } catch (Throwable $e) {
            $mapped = $this->errors->map($e);

            return back()->withErrors(['charge' => $mapped['message'].' (کد پیگیری: '.$mapped['reference'].')']);
        }

        if (! $initiation->success) {
            return back()->withErrors(['charge' => $initiation->errorMessage ?? 'شروع پرداخت ناموفق بود.']);
        }

        if ($method->type === 'card_to_card') {
            $routeName = $store->isReseller() ? 'website.store.wallet.receipt.show' : 'website.wallet.receipt.show';
            $routeParams = $store->isReseller()
                ? ['slug' => $store->reseller->slug, 'payment' => $payment->id]
                : ['payment' => $payment->id];

            return redirect()->route($routeName, $routeParams);
        }

        // Zarinpal و مشابه: هدایت مستقیم به درگاه (خارج از دامنه‌ی سایت).
        return redirect()->away($initiation->redirectUrl);
    }
}
