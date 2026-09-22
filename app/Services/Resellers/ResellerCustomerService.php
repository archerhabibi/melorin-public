<?php

namespace App\Services\Resellers;

use App\Exceptions\ResellerScopeViolationException;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * ResellerCustomerService — «مشتری نماینده» = یک CustomerAccount در فروشگاه او.
 *
 * فاز ۱۵ (Rule 12): تا امروز «اصل طلایی» می‌گفت هر User حداکثر مشتری یک
 * نماینده است (users.reseller_id تک‌مقداری). سند معماری صریحاً خلافش را
 * می‌خواهد: «یک User می‌تواند Customer چند Reseller باشد.» پس عضویت فقط
 * CustomerAccountِ (user, store) است و این سرویس تنها راه مجاز تغییر آن
 * برای پنل/ادمین است.
 *
 * جداسازی داده (بند ۱۸: «نماینده‌ی A نمی‌تواند مشتری B را ببیند») حفظ می‌شود:
 * هر پرسش با reseller_id همان فروشگاه محدود است.
 */
class ResellerCustomerService
{
    public function __construct(protected IdentityService $identity) {}

    /**
     * افزودن (یا فعال‌بودنِ) عضویت یک کاربر در فروشگاه این نماینده.
     * Idempotent است. کاربری که مشتری نماینده‌ی دیگری هم هست مشکلی ندارد.
     *
     * @throws ResellerScopeViolationException اگر عضویت او در این فروشگاه مسدود/غیرفعال شده باشد
     */
    public function assign(Reseller $reseller, User $customer): User
    {
        $account = $this->identity->resolveCustomerAccount($customer, StoreContext::reseller($reseller));

        if (! $account->isActive()) {
            throw new ResellerScopeViolationException('عضویت این کاربر در فروشگاه شما غیرفعال است.');
        }

        return $customer;
    }

    /**
     * غیرفعال‌کردن عضویت یک مشتری در فروشگاه همین نماینده. Wallet و سابقه
     * حفظ می‌شود (پول مشتری نباید ناپدید شود)؛ فقط خرید/تمدید مسدود می‌شود.
     *
     * فقط وقتی مجاز است که مشتری واقعاً عضو همین فروشگاه باشد — جلوگیری از
     * این‌که نماینده‌ی A با شناسه‌ی مشتریِ B او را «آزاد» کند (بند ۱۸).
     *
     * @throws ResellerScopeViolationException
     */
    public function remove(Reseller $reseller, User $customer): void
    {
        $account = $this->identity->findCustomerAccount($customer, StoreContext::reseller($reseller));

        if (! $account) {
            throw new ResellerScopeViolationException('این کاربر مشتری این نماینده نیست.');
        }

        $account->update(['status' => 'disabled']);
    }

    /** Query کاملاً Scope‌شده به مشتریان فعال یک نماینده */
    public function customersQuery(Reseller $reseller): Builder
    {
        return User::query()->whereHas('customerAccounts', function (Builder $q) use ($reseller) {
            $q->where('store_type', 'reseller')
                ->where('reseller_id', $reseller->id)
                ->where('status', 'active');
        });
    }

    /** بررسی مالکیت پیش از هر عملیات روی یک مشتری (پنل/ربات نمایندگی) */
    public function ownsCustomer(Reseller $reseller, User $customer): bool
    {
        return $this->identity->isActiveMember($customer, StoreContext::reseller($reseller));
    }
}
