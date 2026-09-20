<?php

namespace App\Services\Core\Store;

use App\Models\CustomerAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * بند ۳۶ (Identity Resolution) و بند ۵ (CustomerAccount) بلوپرینت.
 *
 * تنها نقطه‌ی مجاز برای «این کاربر در این فروشگاه، کدام CustomerAccount
 * است؟». هیچ جای دیگری نباید CustomerAccount::create یا firstOrCreate
 * صدا بزند — وگرنه همان وضعیت پراکندگی که بند ۶ درباره‌اش هشدار می‌دهد
 * دوباره تکرار می‌شود، این بار روی مالکیت مالی.
 *
 * اصل حیاتی (بند ۳۶): پیدا کردن Identity هرگز به معنی merge کردن
 * CustomerAccountها نیست. اگر بفهمیم علیِ فروشگاه اصلی همان علیِ
 * نماینده‌ی A است، این دو عضویت و دو کیف‌پول همچنان کاملاً جدا می‌مانند.
 */
class IdentityService
{
    /**
     * CustomerAccount این Identity در این فروشگاه را برمی‌گرداند و اگر
     * نبود می‌سازد.
     *
     * چرا firstOrCreate ساده کافی نیست: بین خواندن و نوشتن یک فاصله
     * وجود دارد، و دو درخواست هم‌زمان (که در ربات تلگرام کاملاً عادی
     * است — کاربر دوبار روی دکمه می‌زند) می‌توانند هر دو «نبود» ببینند
     * و هر دو بسازند. نتیجه: دو کیف‌پول موازی برای یک نفر. اینجا به
     * unique دیتابیس تکیه می‌کنیم: اگر درج به تصادم خورد، یعنی رقیب
     * زودتر ساخته و ما همان را می‌خوانیم.
     */
    public function resolveCustomerAccount(User $user, StoreContext $store): CustomerAccount
    {
        $attributes = [
            'user_id' => $user->id,
            'store_type' => $store->storeType,
            'reseller_id' => $store->resellerId(),
        ];

        $existing = $this->findCustomerAccount($user, $store);

        if ($existing) {
            return $existing;
        }

        try {
            return CustomerAccount::create($attributes + [
                'status' => 'active',
                'display_name' => $user->full_name,
            ]);
        } catch (QueryException $e) {
            // تصادم unique یعنی یک درخواست هم‌زمان زودتر ساخته است.
            $raced = $this->findCustomerAccount($user, $store);

            if ($raced) {
                return $raced;
            }

            throw $e;
        }
    }

    public function findCustomerAccount(User $user, StoreContext $store): ?CustomerAccount
    {
        return CustomerAccount::query()
            ->where('user_id', $user->id)
            ->forStore($store->storeType, $store->resellerId())
            ->first();
    }

    /**
     * آیا این User عضو «فعال» این فروشگاه است؟ (Scope — بند ۶ و ۴۳)
     *
     * عضویت همان CustomerAccount است؛ هیچ ستون تک‌مقداریِ روی User
     * (users.reseller_id) مبنای Scope نیست تا یک نفر بتواند هم‌زمان
     * مشتری چند نماینده باشد (Rule 12).
     */
    public function isActiveMember(User $user, StoreContext $store): bool
    {
        return $this->findCustomerAccount($user, $store)?->isActive() === true;
    }

    /**
     * همه‌ی عضویت‌های یک Identity در فروشگاه‌های مختلف. برای پنل ادمین
     * («این کاربر در کدام فروشگاه‌ها مشتری است؟») و برای صفحه‌ی کاربری
     * سایت.
     */
    public function accountsOf(User $user)
    {
        return CustomerAccount::query()
            ->with('reseller')
            ->where('user_id', $user->id)
            ->orderBy('store_type')
            ->get();
    }

    /**
     * پیدا کردن Identity از روی نشانه‌هایی که کاربر در سایت یا ربات
     * می‌دهد (بند ۳۶). ترتیب اهمیت دارد: telegram_id قوی‌ترین نشانه است
     * چون خودِ تلگرام تأییدش کرده، بعد ایمیل و تلفن که کاربر ادعا کرده.
     *
     * عمداً هیچ کاربری اینجا ساخته نمی‌شود — صرفِ دانستن یک شماره تلفن
     * نباید یک Identity بسازد؛ ساختن کاربر کار مسیر ثبت‌نام است.
     */
    public function findIdentity(?int $telegramId = null, ?string $email = null, ?string $phone = null): ?User
    {
        if ($telegramId) {
            $user = User::where('telegram_id', $telegramId)->first();

            if ($user) {
                return $user;
            }
        }

        if ($email) {
            $user = User::where('email', $email)->first();

            if ($user) {
                return $user;
            }
        }

        if ($phone) {
            return User::where('phone', $phone)->first();
        }

        return null;
    }

    /**
     * مهمان (بند ۳۵): یک CustomerAccount بدون Identity. برای خریدی که
     * هنوز نمی‌دانیم مال کیست، ولی باید سفارش و اکانتش مالک داشته باشد.
     */
    public function createGuestAccount(StoreContext $store, array $metadata = []): CustomerAccount
    {
        return CustomerAccount::create([
            'user_id' => null,
            'store_type' => $store->storeType,
            'reseller_id' => $store->resellerId(),
            'status' => 'active',
            'metadata' => $metadata,
        ]);
    }

    /**
     * وصل کردن یک CustomerAccount مهمان به Identity واقعی، بعد از این‌که
     * کاربر ثبت‌نام کرد یا خودش را شناساند.
     *
     * حالت لبه‌ای که حتماً باید مدیریت شود: ممکن است همین Identity از قبل
     * در همین فروشگاه یک CustomerAccount داشته باشد (مثلاً قبلاً از ربات
     * خرید کرده و حالا به‌عنوان مهمان از سایت خریده). در آن صورت دو
     * عضویت در یک فروشگاه خواهیم داشت که unique اجازه‌اش را نمی‌دهد. اینجا
     * صادقانه‌ترین کار این است که سفارش‌های مهمان به عضویت موجود منتقل
     * شوند و خودِ رکورد مهمان کنار گذاشته شود — ولی این یک انتقال مالی
     * است و باید در فاز B، همراه با انتقال کیف‌پول و لاگ Audit، انجام
     * شود. تا آن موقع، این متد صریحاً خطا می‌دهد به‌جای این‌که بی‌صدا
     * داده را نصفه‌نیمه جابه‌جا کند.
     */
    public function attachGuestToIdentity(CustomerAccount $guest, User $user): CustomerAccount
    {
        if (! $guest->isGuest()) {
            throw new \InvalidArgumentException('این CustomerAccount مهمان نیست.');
        }

        $store = StoreContext::fromReseller($guest->reseller);

        if ($this->findCustomerAccount($user, $store)) {
            throw new \RuntimeException(
                'این کاربر از قبل در این فروشگاه عضویت دارد؛ ادغام سفارش‌های مهمان با عضویت موجود در فاز B پیاده می‌شود.'
            );
        }

        return DB::transaction(function () use ($guest, $user) {
            $guest->update([
                'user_id' => $user->id,
                'display_name' => $guest->display_name ?? $user->full_name,
            ]);

            return $guest->fresh();
        });
    }
}
