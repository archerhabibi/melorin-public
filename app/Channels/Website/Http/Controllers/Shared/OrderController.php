<?php

namespace App\Channels\Website\Http\Controllers\Shared;

use App\Models\Account;
use App\Models\CustomerAccount;
use App\Models\Order;
use App\Services\Core\Customer\OrderTrackingService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Order creation/Display — فقط Request/Display» و «Provisioning Status
 * Display — از Core». این کنترلر هیچ تصمیمی
 * نمی‌گیرد و هیچ وضعیتی را تغییر نمی‌دهد؛ فقط سفارشی که همین لحظه با
 * CheckoutController ساخته شده (یا هر سفارش قبلی همین مشتری) را
 * نمایش می‌دهد.
 *
 * فهرست کامل سفارش‌ها در `Account\OrdersController` است؛ این کنترلر
 * فقط صفحه‌ی تک‌سفارشیِ بعد از Checkout را پوشش می‌دهد.
 */
class OrderController
{
    public function __construct(protected OrderTrackingService $tracking) {}

    /**
     * مالکیت (بند ۳۲: «فقط Context و مالکیت مجاز») اینجا با یک شرط
     * ساده سنجیده می‌شود، نه یک Policy جدا — چون تنها مصرف‌کننده‌ی
     * فعلی همین یک متد است؛ فهرست سفارش‌ها (`Account\OrdersController`) همین
     * الگو را تکرار می‌کند؛ اگر جای سومی پیدا شد، باید به یک Policy
     * واقعی تبدیل شود.
     */
    public function show(Request $request, int $order, StoreContext $store): View|Response
    {
        /** @var CustomerAccount|null $customer */
        $customer = $request->attributes->get('customerAccount');

        $orderModel = Order::query()->with(['product', 'account', 'renewedAccount'])->find($order);

        if (! $orderModel || ! $customer || $orderModel->customer_account_id !== $customer->id) {
            abort(404);
        }

        if ($orderModel->reseller_id !== $store->resellerId()) {
            abort(404);
        }

        // B4.5: سرویسِ مرتبط (خرید ⇒ اکانتِ ساخته‌شده · تمدید ⇒ اکانتِ تمدیدشده). دوباره مالکیت سنجیده می‌شود
        // تا حتی با داده‌ی ناسازگار لینکی به سرویس دیگران ساخته نشود.
        /** @var Account|null $service */
        $service = $orderModel->isRenewal() ? $orderModel->renewedAccount : $orderModel->account;
        if ($service && $service->customer_account_id !== $customer->id) {
            $service = null;
        }

        $route = fn (string $name, array $params = []) => $store->isReseller()
            ? route('website.store.'.$name, ['slug' => $store->reseller->slug, ...$params])
            : route('website.'.$name, $params);

        return view('website.shared.order-show', [
            'order' => $orderModel,
            'tracking' => $this->tracking->track($orderModel),
            'amount' => $this->tracking->amount($orderModel),
            'service' => $service,
            'store' => $store,
            'route' => $route,
        ]);
    }
}
