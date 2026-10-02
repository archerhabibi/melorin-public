<?php

namespace Tests\Concerns;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ServerPanel;
use Illuminate\Support\Facades\Http;

/**
 * اسکلت مشترک تست/Fixtureهای Website: یک روش واحد برای ساختن محصولِ قابل‌فروش
 * و Mock کردن پنل Sanaei، تا تست‌ها «چند fake ناهماهنگ» نداشته باشند.
 * تست‌های Website (Checkout، Guest، Reseller، E2E) از همین Trait استفاده
 * می‌کنند. تست‌های Core/Provisioning/Concurrency Fixtureهای خودشان را دارند
 * چون پارامترهای متفاوتی لازم دارند (sale_limit، template_username،
 * بدون پنل، …).
 */
trait InteractsWithWebsiteFixtures
{
    /**
     * یک دسته‌بندی + محصول فعال با یک پنل Sanaei فعال متصل، آماده‌ی
     * خرید. `$overrides` فیلدهای Product را بازنویسی می‌کند (مثلاً
     * `reseller_price`).
     */
    protected function makeSellableProduct(int $mainPrice = 120000, array $overrides = []): Product
    {
        $category = Category::factory()->create(['status' => 'active']);
        $panel = $this->makeActiveSanaeiPanel();

        $category->serverPanels()->attach($panel->id);

        return Product::factory()->create(array_merge([
            'category_id' => $category->id,
            'main_price' => $mainPrice,
            'status' => 'active',
        ], $overrides));
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
     * تست تکرار می‌شد.
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
