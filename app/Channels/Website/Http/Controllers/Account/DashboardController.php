<?php

namespace App\Channels\Website\Http\Controllers\Account;

use App\Channels\Website\Services\WebsiteDashboardFacade;
use App\Models\CustomerAccount;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * B3.1 — Customer Dashboard: سرویس‌های فعال، کیف‌پول، اعلان‌ها در یک نگاه.
 *
 * فقط‌خواندنی و بدون منطق: همه‌چیز از CustomerDashboardService (Core) می‌آید. CustomerAccount را
 * `store.customer` فقط می‌خواند و نمی‌سازد؛ کاربرِ بدون عضویت در این فروشگاه یک داشبورد خالی می‌بیند
 * (مثل Orders/Accounts) و با باز کردن صفحه چیزی ساخته نمی‌شود.
 */
class DashboardController
{
    public function __construct(protected WebsiteDashboardFacade $dashboard) {}

    public function show(Request $request, StoreContext $store): View
    {
        /** @var CustomerAccount|null $customer */
        $customer = $request->attributes->get('customerAccount');

        $snapshot = $this->dashboard->snapshot($request->user(), $store, $customer);

        return view('website.account.dashboard', [
            'dashboard' => $snapshot,
            'noticeUrl' => fn ($notice) => $this->dashboard->noticeUrl($notice, $store),
            'store' => $store,
        ]);
    }
}
