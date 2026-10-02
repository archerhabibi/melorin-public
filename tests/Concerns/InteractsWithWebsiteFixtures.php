<?php

namespace Tests\Concerns;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ServerPanel;
use Illuminate\Support\Facades\Http;

/**
 * فاز W7 (نفر ۵) — «اسکلت تست/Fixtureهای مشترک Website ... تا نفرات
 * ۱ تا ۴ همان اسکلت را استفاده کنند، نه اینکه هرکدام یک روش جدا برای
 * Mock کردن اختراع کنند» (بخش ۱۰ Roadmap، درسِ باگ پچ ۳.۱.۹).
 *
 * این پچ (۳.۲.۱۲) دیر منتشر شد — قاعدتاً باید از همان روز اول کنار
 * نفرات ۱ تا ۴ می‌آمد، اما چون همه‌ی آن پچ‌ها از قبل توسط من نوشته
 * شده بودند (بدون تفکیک نقش رسمی نفر ۵ در آن لحظه)، هرکدام از تست‌های
 * موجود (`CheckoutFlowTest`, `GuestCheckoutTokenTest`, `WalletChargeFlowTest`
 * و غیره) نسخه‌ی خودشان از `makeProduct()`/`fakeSanaeiPanel()` را
 * تکرار کرده بودند — دقیقا همان الگوی «چند fake ناهماهنگ» که بند ۱۰
 * هشدار داده. این Trait آن تکرار را جمع می‌کند؛ تست‌های موجود *بدون
 * تغییر رفتار* به آن مهاجرت داده نشدند (ریسک بی‌دلیل روی تست‌های از
 * قبل سبز)، ولی هر تست تازه (از جمله E2Eهای همین پچ) از اینجا استفاده
 * می‌کند و بازنویسی تدریجی تست‌های قدیمی، بدهی فنی مستند‌شده است (نه
 * فراموش‌شده).
 */
trait InteractsWithWebsiteFixtures
{
    /**
     * یک دسته‌بندی + محصول فعال با یک پنل Sanaei فعال متصل، آماده‌ی
     * خرید. دقیقاً هم‌ساختار نسخه‌ای که در CheckoutFlowTest (پچ ۳.۲.۱)
     * برای اولین بار نوشته شد.
     */
    protected function makeSellableProduct(int $mainPrice = 120000): Product
    {
        $category = Category::factory()->create(['status' => 'active']);
        $panel = $this->makeActiveSanaeiPanel();

        $category->serverPanels()->attach($panel->id);

        return Product::factory()->create([
            'category_id' => $category->id,
            'main_price' => $mainPrice,
            'status' => 'active',
        ]);
    }

    protected function makeActiveSanaeiPanel(): ServerPanel
    {
        return ServerPanel::factory()->create([
            'status' => 'active',
            'panel_type' => 'sanaei',
            'credentials' => json_encode(['api_token' => 'x']),
            'extra_settings' => ['template_username' => 't', 'sub_base_url' => 'https://s.test/sub'],
        ]);
    }

    /**
     * Http::fake برای پنل Sanaei — همان پاسخ موفق ثابتی که در چند
     * تست (PurchaseFlowTest از قبل، CheckoutFlowTest پچ ۳.۲.۱) تکرار
     * شده بود.
     */
    protected function fakeSanaeiPanel(): void
    {
        // GET /panel/api/clients/get/{username} در سه جا استفاده می‌شود:
        // کلاینت نمونه ('t' در fixtureها)، بررسی آزاد بودن username، و خواندن
        // اکانت. کلاینت نمونه باید پیدا شود، هر username دیگر «ناموجود» باشد
        // (مثل رفتار واقعی سنایی)، و بقیه‌ی درخواست‌ها موفق.
        Http::fake(function ($request) {
            $url = $request->url();

            if ($request->method() === 'GET' && str_contains($url, '/panel/api/clients/get/')) {
                $username = rawurldecode(substr($url, strrpos($url, '/') + 1));

                if ($username === 't') {
                    return Http::response([
                        'success' => true,
                        'obj' => ['inboundIds' => [1], 'flow' => '', 'limitIp' => 0],
                    ], 200);
                }

                return Http::response(['success' => false, 'obj' => null, 'msg' => 'record not found'], 200);
            }

            return Http::response([
                'success' => true,
                'obj' => ['inboundIds' => [1], 'flow' => '', 'limitIp' => 0],
                'subscription_url' => 'https://sub.example.test/abc',
            ], 200);
        });
    }

    /** Http::fake برای درگاه Zarinpal — هم‌ساختار PaymentServiceTest موجود. */
    protected function fakeZarinpalGateway(): void
    {
        Http::fake([
            '*/payment/request.json' => Http::response([
                'data' => ['code' => 100, 'authority' => 'AUTH123'],
            ], 200),
        ]);
    }

    protected function makeCardToCardMethod(): PaymentMethod
    {
        return PaymentMethod::factory()->create();
    }

    protected function makeZarinpalMethod(): PaymentMethod
    {
        return PaymentMethod::factory()->zarinpal()->create();
    }
}
