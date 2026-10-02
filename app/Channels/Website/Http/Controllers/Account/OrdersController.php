<?php

namespace App\Channels\Website\Http\Controllers\Account;

use App\Models\CustomerAccount;
use App\Models\Order;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Orders — فقط Context و مالکیت مجاز («User فقط Orderهای مجاز خود و
 * Context جاری را مشاهده می‌کند»). این کنترلر مکمّل `Shared\OrderController::show()`
 * (صفحه‌ی تک‌سفارش) است — همان الگوی مالکیت (customer_account_id +
 * reseller_id دوباره‌بررسی‌شده) اینجا هم تکرار می‌شود، نه یک Policy
 * جدید.
 */
class OrdersController
{
    public function index(Request $request, StoreContext $store): View
    {
        /** @var CustomerAccount|null $customer */
        $customer = $request->attributes->get('customerAccount');

        $orders = Order::query()
            ->with('product')
            ->where('customer_account_id', $customer?->id)
            ->where('reseller_id', $store->resellerId())
            ->latest('id')
            ->paginate(15);

        return view('website.account.orders.index', [
            'orders' => $orders,
            'store' => $store,
        ]);
    }
}
