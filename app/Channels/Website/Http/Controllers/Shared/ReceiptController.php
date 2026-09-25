<?php

namespace App\Channels\Website\Http\Controllers\Shared;

use App\Models\Payment;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * فاز W2 بند ۵ (ادامه): آپلود رسید کارت‌به‌کارت از سایت.
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
 * به‌روزرسانی شده تا هر دو منبع را نشان دهد (این تغییر خارج از مرز
 * کانال Website است — روی کد مشترک ادمین — و در مستند پچ به‌صراحت
 * ذکر شده).
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
        ]);
    }

    public function store(int $payment, Request $request, StoreContext $store): Response
    {
        $data = $request->validate([
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
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

        $paymentModel->update([
            'receipt_image' => 'website:'.$path,
            'depositor_name' => $data['depositor_name'],
        ]);

        return redirect()->back()->with('status', 'رسید ثبت شد. پس از بررسی ادمین، کیف پول شما شارژ خواهد شد.');
    }
}
