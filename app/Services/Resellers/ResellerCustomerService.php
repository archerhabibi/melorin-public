<?php

namespace App\Services\Resellers;

use App\Exceptions\ResellerScopeViolationException;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * ResellerCustomerService — طبق سند معماری، بخش ۳ (ResellerCustomerService)
 * و «اصل طلایی مشتری نماینده» در سند نیازمندی (بند ۲): یک کاربر در هر
 * لحظه فقط می‌تواند مشتری صفر یا یک نماینده باشد (users.reseller_id).
 * این سرویس تنها راه مجاز برای تغییر آن رابطه است — نه ویرایش مستقیم
 * ستون از جای دیگر — تا تضمین‌های Isolation (بند ۱۸: «Reseller A
 * نمی‌تواند Customer Reseller B را بخواند») همیشه برقرار بمانند.
 */
class ResellerCustomerService
{
    /**
     * اختصاص یک کاربر به یک نماینده به‌عنوان مشتری.
     *
     * اگر کاربر از قبل مشتریِ همین نماینده باشد، بی‌اثر است (idempotent).
     * اگر مشتریِ نماینده‌ی دیگری باشد، خطا می‌دهد — انتقال مستقیم مشتری
     * بین نمایندگان عمداً پشتیبانی نمی‌شود (طبق بند ۲ سند نیازمندی، هر
     * نماینده فقط باید مشتریان خودش را ببیند/مدیریت کند؛ انتقال باید
     * آگاهانه و توسط ادمین اصلی از پنل انجام شود، نه یک عملیات ساده).
     *
     * @throws ResellerScopeViolationException
     */
    public function assign(Reseller $reseller, User $customer): User
    {
        if ($customer->reseller_id === $reseller->id) {
            return $customer;
        }

        if ($customer->reseller_id !== null) {
            throw new ResellerScopeViolationException('این کاربر از قبل مشتری یک نماینده‌ی دیگر است.');
        }

        $customer->update(['reseller_id' => $reseller->id]);

        return $customer;
    }

    /**
     * حذف رابطه‌ی مالکیت مشتری از یک نماینده. فقط وقتی مجاز است که
     * مشتری واقعاً همین لحظه مال همین نماینده باشد — جلوگیری از این‌که
     * نماینده‌ی A با فرستادن id مشتریِ نماینده‌ی B او را «آزاد» کند
     * (دقیقاً همان تست اجباری بند ۱۸: «تغییر ID/URL/callback نباید
     * Scope را دور بزند»).
     *
     * @throws ResellerScopeViolationException
     */
    public function remove(Reseller $reseller, User $customer): void
    {
        if ($customer->reseller_id !== $reseller->id) {
            throw new ResellerScopeViolationException('این کاربر مشتری این نماینده نیست.');
        }

        $customer->update(['reseller_id' => null]);
    }

    /** Query کاملاً Scope‌شده به مشتریان یک نماینده — هیچ‌جای دیگری نباید User::where('reseller_id', ...) را مستقیم بنویسد */
    public function customersQuery(Reseller $reseller): Builder
    {
        return User::query()->where('reseller_id', $reseller->id);
    }

    /**
     * بررسی مالکیت پیش از هر عملیات دیگر روی یک مشتری (مثلاً پیش از
     * نمایش جزئیات یا اکانت‌هایش در پنل/ربات نمایندگی).
     */
    public function ownsCustomer(Reseller $reseller, User $customer): bool
    {
        return $customer->reseller_id === $reseller->id;
    }

    /**
     * @throws ResellerScopeViolationException اگر مشتری متعلق به این نماینده نباشد
     */
    public function assertOwnsCustomer(Reseller $reseller, User $customer): void
    {
        if (! $this->ownsCustomer($reseller, $customer)) {
            throw new ResellerScopeViolationException('این مشتری متعلق به این نماینده نیست.');
        }
    }
}
