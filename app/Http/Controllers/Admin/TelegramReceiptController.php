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
 * `App\Channels\Website\Http\Controllers\Shared\ReceiptController` —
 * پچ 3.2.2، فاز W2). این تغییر روی کد مشترک پنل ادمین است، نه داخل
 * کانال Website — چون نمایش رسید برای ادمین باید مستقل از این باشد
 * که رسید از کدام کانال آمده (بند ۱: «هیچ کانالی نباید منطق مشترک را
 * دوباره پیاده کند»).
 *
 * چون receipt_image تلگرام فقط یک file_id است (نه URL عمومی)، پنل
 * Filament نمی‌تواند مستقیم <img src="..."> بدهد — این route فایل را
 * از منبع درست واکشی/استریم می‌کند.
 *
 * باگ نسخه‌ی قبلی (بخش تلگرام): از یک متد $telegram->download()
 * استفاده می‌کرد که اصلاً در irazasyed/telegram-bot-sdk وجود ندارد
 * (خطای واقعی روی سرور: «Method [download] does not exist»). روش
 * درست طبق خودِ SDK: getFile() فقط file_path را برمی‌گرداند؛ دانلود
 * واقعی باید با یک درخواست HTTP جدا به
 * https://api.telegram.org/file/bot<TOKEN>/<path> انجام شود.
 *
 * محافظت‌شده با میدل‌ور auth:admin (در routes/admin.php ثبت شده).
 */
class TelegramReceiptController
{
    public function __invoke(Payment $payment, Api $telegram): Response
    {
        abort_if(! $payment->receipt_image, 404);

        if (str_starts_with($payment->receipt_image, 'website:')) {
            $path = substr($payment->receipt_image, strlen('website:'));

            abort_unless(Storage::disk('local')->exists($path), 404, 'فایل رسید پیدا نشد.');

            return response(
                Storage::disk('local')->get($path),
                200,
                ['Content-Type' => Storage::disk('local')->mimeType($path) ?: 'image/jpeg']
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
            ['Content-Type' => $response->header('Content-Type') ?: 'image/jpeg']
        );
    }
}
