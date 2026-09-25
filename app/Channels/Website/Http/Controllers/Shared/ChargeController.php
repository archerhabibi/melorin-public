<?php

namespace App\Channels\Website\Http\Controllers\Shared;

use App\Channels\Website\Services\WebsiteChargeFacade;
use App\Channels\Website\Support\CoreErrorMapper;
use App\Models\PaymentMethod;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * فاز W2 بند ۵ (ادامه): «یک صفحه‌ی انتخاب روش (Wallet / Zarinpal /
 * Card-to-Card) بعد از Checkout» (بند ۲۹۷ Roadmap). این کنترلر خودِ
 * آن صفحه نیست — Checkout همیشه Wallet را نشان می‌دهد (پچ ۳.۲.۱)؛
 * اینجا فقط وقتی موجودی کافی نیست وارد بازی می‌شود: «کیف‌پولت را شارژ
 * کن»، جدا از فرایند Checkout، دقیقاً مثل بند ۳۰/۳۱ Roadmap («Wallet:
 * نمایش موجودی + Charge»).
 *
 * بعد از شارژ موفق (چه Zarinpal چه Card-to-Card)، کاربر باید خودش به
 * Checkout برگردد و «پرداخت از کیف‌پول» را دوباره بزند — این پچ عمداً
 * «شارژ خودکار و ادامه‌ی خرید» را پیاده نمی‌کند چون آن یعنی نگه‌داشتن
 * نیّتِ خرید بین دو درخواست HTTP (session) که خودش یک تصمیم امنیتی/
 * معماری جداست و در Roadmap فعلی مشخص نشده.
 */
class ChargeController
{
    public function __construct(
        protected WebsiteChargeFacade $charge,
        protected CoreErrorMapper $errors,
    ) {}

    public function show(StoreContext $store): View
    {
        return view('website.shared.wallet-charge', [
            'methods' => PaymentMethod::query()->where('status', 'active')->get(),
            'store' => $store,
        ]);
    }

    public function store(Request $request, StoreContext $store): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:10000'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
        ]);

        $method = PaymentMethod::query()->where('status', 'active')->findOrFail($data['payment_method_id']);

        try {
            ['payment' => $payment, 'initiation' => $initiation] = $this->charge->charge(
                user: $request->user(),
                method: $method,
                amount: (float) $data['amount'],
                store: $store,
            );
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
