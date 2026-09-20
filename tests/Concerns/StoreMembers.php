<?php

namespace Tests\Concerns;

use App\Models\CustomerAccount;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;

/**
 * فاز ۱۵ (Rule 12): «مشتری یک نماینده» = داشتنِ CustomerAccount در فروشگاه او،
 * نه users.reseller_id. این trait جایگزین الگوی قدیمی
 * `User::factory()->create(['reseller_id' => $reseller->id])` است.
 */
trait StoreMembers
{
    /** کاربری که عضو فعالِ فروشگاه این نماینده است (کاربر ممکن است عضو فروشگاه‌های دیگر هم باشد) */
    protected function memberOf(Reseller $reseller, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        $this->accountIn($user, $reseller);

        return $user;
    }

    /** CustomerAccount این کاربر در یک فروشگاه (Main اگر $reseller خالی باشد) — در صورت نبود می‌سازد */
    protected function accountIn(User $user, ?Reseller $reseller): CustomerAccount
    {
        return app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::fromReseller($reseller));
    }
}
