<?php

namespace App\Services\Core\Payments;

use App\DataTransferObjects\GatewayInitiationResult;
use App\DataTransferObjects\GatewayVerificationResult;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;

/**
 * درگاه آنلاین Zarinpal (بند ۱۲).
 *
 * تنظیمات لازم در payment_methods.settings:
 * {
 *   "driver": "zarinpal",
 *   "merchant_id": "xxxxxxxx-xxxx-...",
 *   "sandbox": false            // اختیاری، برای تست روی sandbox.zarinpal.com
 * }
 *
 * توجه واحد پول: مبالغ داخل سیستم ملورین به تومان ذخیره می‌شوند (مطابق
 * سند نیازمندی)؛ API زرین‌پال مبلغ را به ریال می‌خواهد، بنابراین همه‌جا
 * ضرب‌در‌۱۰ انجام می‌شود.
 */
class ZarinpalGateway implements PaymentGatewayInterface
{
    /**
     * sandbox: اولویت با payment_methods.settings.sandbox (برای هر روش
     * پرداخت)، و در نبودِ آن ZARINPAL_SANDBOX (config/services.php).
     * قبلاً ZARINPAL_SANDBOX تعریف شده بود ولی هیچ‌جا خوانده نمی‌شد.
     */
    protected function isSandbox(Payment $payment): bool
    {
        return (bool) ($payment->paymentMethod->settings['sandbox']
            ?? config('services.zarinpal.sandbox', false));
    }

    protected function baseUrl(Payment $payment): string
    {
        $sandbox = $this->isSandbox($payment);

        return $sandbox
            ? 'https://sandbox.zarinpal.com/pg/v4/payment'
            : 'https://api.zarinpal.com/pg/v4/payment';
    }

    protected function startPayUrl(Payment $payment, string $authority): string
    {
        $sandbox = $this->isSandbox($payment);
        $host = $sandbox ? 'sandbox.zarinpal.com' : 'www.zarinpal.com';

        return "https://{$host}/pg/StartPay/{$authority}";
    }

    protected function merchantId(Payment $payment): string
    {
        $merchantId = $payment->paymentMethod->settings['merchant_id'] ?? null;

        if (! $merchantId) {
            throw new \RuntimeException('merchant_id برای درگاه Zarinpal تنظیم نشده است.');
        }

        return $merchantId;
    }

    /** مبلغ ریالی معادل amount تومانی این پرداخت */
    protected function amountInRials(Payment $payment): int
    {
        return (int) round((float) $payment->amount * 10);
    }

    public function isManual(): bool
    {
        return false;
    }

    /**
     * آدرس callback که زرین‌پال بعد از پرداخت کاربر را به آن برمی‌گرداند.
     *
     * چون این پکیج فقط لایه‌ی Core است و به ساختار routeهای پروژه‌ی نهایی
     * (ربات/سایت/پنل) وابسته نیست، این آدرس را از کانفیگ می‌خوانیم — نه از
     * یک route با نام ثابت. در پروژه‌ی اصلی لازم است در config/services.php
     * چیزی شبیه این تنظیم شود:
     *   'zarinpal' => ['callback_url' => env('ZARINPAL_CALLBACK_URL')]
     * و آن route باید payment_id را از querystring بخواند و
     * PaymentService::handleCallback() را صدا بزند.
     */
    protected function callbackUrl(Payment $payment): string
    {
        $base = config('services.zarinpal.callback_url');

        if (! $base) {
            throw new \RuntimeException(
                'services.zarinpal.callback_url تنظیم نشده است؛ آدرس بازگشت زرین‌پال را در کانفیگ پروژه مشخص کنید.'
            );
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return "{$base}{$separator}payment_id={$payment->id}";
    }

    public function initiate(Payment $payment): GatewayInitiationResult
    {
        $response = Http::post($this->baseUrl($payment).'/request.json', [
            'merchant_id' => $this->merchantId($payment),
            'amount' => $this->amountInRials($payment),
            'callback_url' => $this->callbackUrl($payment),
            'description' => "پرداخت ملورین #{$payment->id}",
        ]);

        $body = $response->json();
        $code = $body['data']['code'] ?? null;

        if (! $response->successful() || $code !== 100) {
            $message = $body['errors']['message'] ?? 'خطای نامشخص در ایجاد تراکنش زرین‌پال.';

            return GatewayInitiationResult::fail($message, $body);
        }

        $authority = $body['data']['authority'];

        return GatewayInitiationResult::redirect(
            $this->startPayUrl($payment, $authority),
            $authority,
            $body
        );
    }

    public function verify(Payment $payment, array $callbackData): GatewayVerificationResult
    {
        if (($callbackData['Status'] ?? null) !== 'OK') {
            return GatewayVerificationResult::fail('کاربر پرداخت را در زرین‌پال لغو یا ناموفق کرد.', $callbackData);
        }

        $response = Http::post($this->baseUrl($payment).'/verify.json', [
            'merchant_id' => $this->merchantId($payment),
            'amount' => $this->amountInRials($payment),
            'authority' => $payment->gateway_reference,
        ]);

        $body = $response->json();
        $code = $body['data']['code'] ?? null;

        // کد ۱۰۰ یعنی تایید موفق؛ کد ۱۰۱ یعنی «قبلاً تایید شده» که آن هم
        // باید موفق در نظر گرفته شود (برای جلوگیری از خطا در callbackهای تکراری).
        if (! in_array($code, [100, 101], true)) {
            $message = $body['errors']['message'] ?? 'تایید تراکنش زرین‌پال ناموفق بود.';

            return GatewayVerificationResult::fail($message, $body);
        }

        return GatewayVerificationResult::ok($body['data']['ref_id'] ?? null, $body);
    }
}
