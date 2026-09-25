<?php

namespace App\Channels\Website\Http\Controllers\Shared;

use App\Models\CustomerAccount;
use App\Models\Order;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * فاز W2 بند ۶ و ۸: «Order creation/Display — فقط Request/Display» و
 * «Provisioning Status Display — از Core». این کنترلر هیچ تصمیمی
 * نمی‌گیرد و هیچ وضعیتی را تغییر نمی‌دهد؛ فقط سفارشی که همین لحظه با
 * CheckoutController ساخته شده (یا هر سفارش قبلی همین مشتری) را
 * نمایش می‌دهد.
 *
 * فهرست کامل سفارش‌ها («Orders» با مالکیت و Context، بند ۳۲ زیرسند)
 * عمداً اینجا نیست — طبق Roadmap آن بخشِ فاز W4 است، نه W2. این کنترلر
 * فقط صفحه‌ی تک‌سفارشیِ بعد از Checkout را پوشش می‌دهد.
 */
class OrderController
{
    /**
     * مالکیت (بند ۳۲: «فقط Context و مالکیت مجاز») اینجا با یک شرط
     * ساده سنجیده می‌شود، نه یک Policy جدا — چون تنها مصرف‌کننده‌ی
     * فعلی همین یک متد است؛ اگر W4 فهرست کامل سفارش‌ها را اضافه کرد و
     * این شرط در جای دوم تکرار شد، آن‌جا باید به یک Policy واقعی
     * تبدیل شود.
     */
    public function show(Request $request, int $order, StoreContext $store): View|Response
    {
        /** @var CustomerAccount|null $customer */
        $customer = $request->attributes->get('customerAccount');

        $orderModel = Order::query()->with(['product', 'account'])->find($order);

        if (! $orderModel || ! $customer || $orderModel->customer_account_id !== $customer->id) {
            abort(404);
        }

        if ($orderModel->reseller_id !== $store->resellerId()) {
            abort(404);
        }

        return view('website.shared.order-show', [
            'order' => $orderModel,
            'store' => $store,
        ]);
    }
}
