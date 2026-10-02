<?php

namespace App\Http\Controllers\Admin;

use App\Models\Payment;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\Api;

/**
 * پروکسی نمایش تصویر رسید کارت‌به‌کارت.
 *
 * دو منبع ممکن است: رسید ارسالی از ربات (`receipt_image` = یک
 * Telegram file_id) یا رسید آپلودشده از سایت (`receipt_image` با
 * پیشوند `website:`، ذخیره‌شده روی دیسک خصوصی `local` توسط
 * `App\Channels\Website\Http\Controllers\Shared\ReceiptController`).
 * این کد مشترکِ پنل ادمین است، نه بخشی از کانال Website — چون نمایش رسید
 * برای ادمین باید مستقل از کانالِ مبدأ باشد («هیچ کانالی نباید منطق
 * مشترک را دوباره پیاده کند»).
 *
 * چون receipt_image تلگرام فقط یک file_id است (نه URL عمومی)، پنل
 * Filament نمی‌تواند مستقیم <img src="..."> بدهد — این route فایل را
 * از منبع درست واکشی/استریم می‌کند.
 *
 * دانلود فایل تلگرام: در irazasyed/telegram-bot-sdk متدِ `download()`
 * وجود ندارد؛ `getFile()` فقط file_path را برمی‌گرداند و دانلود واقعی
 * باید با یک درخواست HTTP جدا به
 * https://api.telegram.org/file/bot<TOKEN>/<path> انجام شود.
 *
 * محافظت‌شده با میدل‌ور auth:admin (در routes/admin.php ثبت شده).
 *
 * دو لایه‌ی دفاعی، مستقل از اعتبارسنجی آپلود (`mimes:` در
 * ReceiptController):
 * چون هیچ اعتبارسنجی‌ای ۱۰۰٪ غیرقابل‌دور‌زدن نیست:
 * (۱) `Content-Type` واقعاً واکشی‌شده فقط اگر در فهرست مجاز
 *     (image/jpeg, image/png, image/webp, application/pdf) باشد
 *     مستقیم استفاده می‌شود، وگرنه `application/octet-stream` (مرورگر
 *     مجبور به دانلود می‌شود، نه اجرا/رندر).
 * (۲) هدر `X-Content-Type-Options: nosniff` — مرورگر را از حدس‌زدن
 *     نوع محتوا بر اساس بایت‌های فایل منع می‌کند (دفاع در برابر
 *     Stored XSS از طریق یک فایل «تصویر» که واقعاً HTML/JS است).
 */
class TelegramReceiptController
{
    /** هر Content-Type دیگری، حتی اگر finfo آن را تشخیص بدهد، force-download می‌شود. */
    protected const ALLOWED_CONTENT_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    protected function safeHeaders(?string $detectedType): array
    {
        $normalized = $detectedType ? trim(explode(';', $detectedType)[0]) : null;

        $type = in_array($normalized, self::ALLOWED_CONTENT_TYPES, true)
            ? $normalized
            : 'application/octet-stream';

        return [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    public function __invoke(Payment $payment, Api $telegram): Response
    {
        abort_if(! $payment->receipt_image, 404);

        if (str_starts_with($payment->receipt_image, 'website:')) {
            $path = substr($payment->receipt_image, strlen('website:'));

            abort_unless(Storage::disk('local')->exists($path), 404, 'فایل رسید پیدا نشد.');

            return response(
                Storage::disk('local')->get($path),
                200,
                $this->safeHeaders(Storage::disk('local')->mimeType($path))
            );
        }

        $file = $telegram->getFile(['file_id' => $payment->receipt_image]);
        $filePath = $file->getFilePath() ?? null;

        abort_if(! $filePath, 404, 'فایل رسید روی سرورهای تلگرام پیدا نشد (ممکن است منقضی شده باشد).');

        $token = config('telegram.bots.main.token');
        $downloadUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";

        $response = Http::timeout(15)->get($downloadUrl);

        abort_if(! $response->successful(), 502, 'دانلود رسید از تلگرام ناموفق بود.');

        return response(
            $response->body(),
            200,
            $this->safeHeaders($response->header('Content-Type'))
        );
    }
}
