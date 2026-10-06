<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Core\Payments\InvalidGatewayCallbackException;
use App\Services\Core\PaymentService;
use App\Services\Resellers\Branding\StoreBrandResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * مسیر بازگشت کاربر از درگاه پرداخت آنلاین (P2 گزارش امنیتی، مورد #17).
 *
 * تا پیش از این، ZarinpalGateway و PaymentService::handleCallback() هر
 * دو کامل بودند ولی هیچ routeای وجود نداشت که کاربر بعد از پرداخت به
 * آن برگردد — یعنی زنجیره‌ی پرداخت آنلاین عملاً ناتمام بود.
 *
 * نکته‌ی امنیتی: هیچ داده‌ی مالی‌ای از querystring مورد اعتماد نیست.
 * تنها چیزی که از URL خوانده می‌شود شناسه‌ی پرداخت است؛ مبلغ و صحت
 * تراکنش مستقیماً با فراخوانی verify سمت‌به‌سمت با خودِ زرین‌پال تأیید
 * می‌شود (داخل handleCallback → gateway->verify)، نه بر اساس چیزی که
 * مرورگر کاربر حمل می‌کند.
 */
class PaymentCallbackController
{
    public function zarinpal(Request $request, PaymentService $paymentService, StoreBrandResolver $brands)
    {
        $paymentId = $request->query('payment_id');

        if (! $paymentId) {
            return response()->view('payment.callback', [
                'success' => false,
                'message' => 'شناسه‌ی پرداخت در آدرس بازگشت وجود ندارد.',
            ], 400);
        }

        $payment = Payment::query()->find($paymentId);

        if (! $payment) {
            return response()->view('payment.callback', [
                'success' => false,
                'message' => 'پرداخت موردنظر پیدا نشد.',
            ], 404);
        }

        try {
            $payment = $paymentService->handleCallback($payment, $request->query());
        } catch (InvalidGatewayCallbackException) {
            // S-03: Authority نامطابق ← هیچ چیز تغییر نکرده؛ پاسخ عمومی و یکسان با
            // «پرداخت پیدا نشد» تا وجود/عدم‌وجود payment_id قابل‌تشخیص نباشد.
            return response()->view('payment.callback', [
                'success' => false,
                'message' => 'پرداخت موردنظر پیدا نشد.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('zarinpal_callback_failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return response()->view('payment.callback', [
                'success' => false,
                'message' => 'در تأیید پرداخت خطایی رخ داد. اگر مبلغ از حساب شما کسر شده، با پشتیبانی تماس بگیرید.',
            ], 500);
        }

        $confirmed = $payment->status === 'confirmed';

        // B4.4: لینک‌های «قدم بعدی» فقط از خودِ Payment (فروشگاه مبدأ) ساخته می‌شوند؛ هیچ داده‌ی مالی/شخصی نشان
        // داده نمی‌شود (این مسیر بدون ورود است). مقصد «بازگشت به خرید» فقط از Session سمت سرور می‌آید.
        $slug = $payment->reseller_id ? $payment->reseller?->slug : null;
        $link = fn (string $name) => $slug ? route('website.store.'.$name, $slug) : route('website.'.$name);

        return response()->view('payment.callback', [
            'success' => $confirmed,
            'message' => $confirmed
                ? 'پرداخت با موفقیت تأیید و کیف پول شما شارژ شد.'
                : 'پرداخت تأیید نشد یا توسط شما لغو شد.',
            'amount' => $payment->amount,
            // B5.7: صفحه‌ی نتیجه با رنگ/نام فروشگاه مبدأ (نماینده)؛ Main = برند پیش‌فرض. فقط‌خواندنی و بدون داده‌ی شخصی.
            'brand' => $brands->forReseller($payment->reseller_id ? $payment->reseller : null),
            // شارژ اعتبار خودِ نماینده (wallet_owner_type=reseller) در پنل نماینده است؛ لینک کیف‌پول مشتری برایش بی‌معناست.
            ...($payment->wallet_owner_type === 'reseller' ? [] : [
                'walletUrl' => $link('wallet.show'),
                'retryUrl' => $link('wallet.charge.show'),
            ]),
        ]);
    }
}
