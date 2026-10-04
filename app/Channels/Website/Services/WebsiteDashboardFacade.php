<?php

namespace App\Channels\Website\Services;

use App\Models\CustomerAccount;
use App\Models\User;
use App\Services\Core\Customer\CustomerDashboard;
use App\Services\Core\Customer\CustomerDashboardService;
use App\Services\Core\Customer\DashboardNotice;
use App\Services\Core\Store\StoreContext;

/**
 * Adapter نازک روی CustomerDashboardService (B3.1). تنها کار خودش: تبدیل «هدفِ» هر اعلان
 * (نوع+شناسه) به URL همین Channel در همین Context. هیچ تصمیمی اینجا گرفته نمی‌شود.
 */
class WebsiteDashboardFacade
{
    public function __construct(protected CustomerDashboardService $dashboard) {}

    public function snapshot(User $user, StoreContext $store, ?CustomerAccount $customer): CustomerDashboard
    {
        return $this->dashboard->snapshot($user, $store, $customer);
    }

    public function noticeUrl(DashboardNotice $notice, StoreContext $store): ?string
    {
        [$name, $params] = match ($notice->targetType) {
            DashboardNotice::TARGET_ACCOUNT => ['accounts.show', [$notice->targetId]],
            DashboardNotice::TARGET_ORDER => ['orders.show', [$notice->targetId]],
            DashboardNotice::TARGET_WALLET => ['wallet.show', []],
            DashboardNotice::TARGET_CHARGE => ['wallet.charge.show', []],
            DashboardNotice::TARGET_TICKET => ['tickets.show', [$notice->targetId]],
            DashboardNotice::TARGET_TICKETS => ['tickets.index', []],
            default => [null, []],
        };

        if ($name === null) {
            return null;
        }

        return $store->isReseller()
            ? route('website.store.'.$name, [$store->reseller->slug, ...$params])
            : route('website.'.$name, $params);
    }
}
