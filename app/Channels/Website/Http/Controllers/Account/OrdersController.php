<?php

namespace App\Channels\Website\Http\Controllers\Account;

use App\Models\CustomerAccount;
use App\Services\Core\Customer\OrderTrackingService;
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
    public function __construct(protected OrderTrackingService $tracking) {}

    public function index(Request $request, StoreContext $store): View
    {
        /** @var CustomerAccount|null $customer */
        $customer = $request->attributes->get('customerAccount');

        // B4.5: فیلتر با «گروه» (نه وضعیت خام)؛ مقدار نامعتبر ⇒ همه. عضویت نیست ⇒ فهرست خالی (id=0 هیچ‌چیز نمی‌یابد).
        $group = $this->tracking->group($request->query('group'));
        $customerId = (int) $customer?->id;

        return view('website.account.orders.index', [
            'orders' => $this->tracking->paginate($customerId, $store->resellerId(), $group)
                ->appends(array_filter(['group' => $group])),
            'counts' => $this->tracking->counts($customerId, $store->resellerId()),
            'group' => $group,
            'groupLabels' => OrderTrackingService::groupLabels(),
            'tracking' => $this->tracking,
            'store' => $store,
            'route' => fn (string $name, array $params = []) => $store->isReseller()
                ? route('website.store.'.$name, ['slug' => $store->reseller->slug, ...$params])
                : route('website.'.$name, $params),
        ]);
    }
}
