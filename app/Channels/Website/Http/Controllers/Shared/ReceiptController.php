<?php

namespace App\Channels\Website\Http\Controllers\Shared;

use App\Models\Payment;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * آپلود رسید کارت‌به‌کارت از سایت.
 *
 * مالکیت دقیقاً با همان `Payment::findPendingForReceipt()` سنجیده
 * می‌شود که ربات هم استفاده می‌کند (P1 گزارش امنیتی، مورد #12؛
 * `app/Channels/TelegramBot/Handlers/WalletHandler.php`) — نه یک
 * پیاده‌سازی موازی، تا همان تضمین امنیتی («payment_id به‌تنهایی مرز
 * امنیتی نیست») برای سایت هم برقرار بماند.
 *
 * تفاوت با ربات: تلگرام یک `file_id` می‌دهد؛ اینجا فایل واقعی روی یک
 * دیسک خصوصی (`local`، نه `public`) ذخیره می‌شود و مقدار ستون
 * `receipt_image` با پیشوند `website:` نوشته می‌شود تا از file_idهای
 * تلگرام قابل‌تشخیص باشد. `TelegramReceiptController` طبق همین پیشوند
 * به‌روزرسانی شده تا هر دو منبع را نشان دهد (کدِ مشترک ادمین است،
 * نه بخشی از کانال Website).
 */
class ReceiptController
{
    public function show(int $payment, Request $request, StoreContext $store): View|Response
    {
        $paymentModel = Payment::findPendingForReceipt(
            $payment, $request->user(), $store->isReseller() ? $store->reseller : null
        );

        if (! $paymentModel) {
            abort(404);
        }

        $paymentModel->load('paymentMethod');

        return view('website.shared.wallet-receipt', [
            'payment' => $paymentModel,
            'instructions' => $paymentModel->paymentMethod->settings ?? [],
            'store' => $store,
            // B4.4: مقصد «بازگشت به تکمیل خرید» فقط از Session سمت سرور (ChargeController::store) می‌آید، نه از Query.
            'returnUrl' => $request->session()->get(ChargeController::RETURN_SESSION_KEY),
            'route' => fn (string $name, array $params = []) => $store->isReseller()
                ? route('website.store.'.$name, ['slug' => $store->reseller->slug, ...$params])
                : route('website.'.$name, $params),
        ]);
    }

    public function store(int $payment, Request $request, StoreContext $store): Response
    {
        // بند ۹.۶: «حداکثر ۵ مگابایت، فقط jpg/png/webp/pdf، اعتبارسنجی
        // واقعی محتوا نه فقط پسوند». `mimes:` در Laravel از finfo
        // (بررسی واقعی بایت‌های فایل) استفاده می‌کند، نه فقط پسوند نام
        // فایل — پس این خط همان الزام «محتوای واقعی» را برآورده می‌کند.
        $data = $request->validate([
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'depositor_name' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $paymentModel = Payment::findPendingForReceipt(
            $payment, $request->user(), $store->isReseller() ? $store->reseller : null
        );

        if (! $paymentModel) {
            abort(404);
        }

        // دیسک خصوصی («local»، نه «public») چون رسید بانکی داده‌ی
        // مالی حساس است؛ فقط از طریق TelegramReceiptController (پشت
        // auth:admin) قابل‌مشاهده است، نه با یک URL عمومی مستقیم.
        $path = $request->file('receipt')->store('website-receipts/'.$paymentModel->id, 'local');

        // S-09: رسید قبلی همین پرداخت پاک می‌شود (وگرنه با هر آپلود، فایل یتیم
        // روی دیسک می‌ماند).
        $previous = (string) $paymentModel->receipt_image;

        if (str_starts_with($previous, 'website:')) {
            $previousPath = substr($previous, strlen('website:'));

            if ($previousPath !== $path) {
                Storage::disk('local')->delete($previousPath);
            }
        }

        $paymentModel->update([
            'receipt_image' => 'website:'.$path,
            'depositor_name' => $data['depositor_name'],
        ]);

        return redirect()->back()->with('status', 'رسید ثبت شد. پس از بررسی ادمین، کیف پول شما شارژ خواهد شد.');
    }
}
