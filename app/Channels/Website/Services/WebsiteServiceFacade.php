<?php

namespace App\Channels\Website\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Account;
use App\Services\Core\Customer\AccountManagementService;
use App\Services\Core\Customer\ServiceOverview;
use App\Services\Core\Customer\UsageRefreshResult;
use App\Services\Core\Purchase\PurchaseNotAllowedException;
use App\Services\Core\Renewal\RenewalFailedException;
use App\Services\Core\Store\StoreContext;
use App\Support\Money;

/**
 * Adapter نازک روی AccountManagementService (B3.2). فقط سه کار: (۱) نگاشت «نام route» به URL همین
 * Context، (۲) تبدیل نتیجه‌ی Core به پیام فارسی، (۳) ساخت کلید Idempotency از توکن فرم.
 * هیچ تصمیم مالی/کسب‌وکاری اینجا گرفته نمی‌شود (Website فقط Channel است).
 */
class WebsiteServiceFacade
{
    public function __construct(protected AccountManagementService $management) {}

    public function overview(Account $account): ServiceOverview
    {
        return $this->management->overview($account);
    }

    public function url(string $name, StoreContext $store, array $params = []): string
    {
        return $store->isReseller()
            ? route('website.store.'.$name, [$store->reseller->slug, ...$params])
            : route('website.'.$name, $params);
    }

    /** پیام فارسی نتیجه‌ی «به‌روزرسانی مصرف» + لحن آن (success|warning|info). */
    public function refreshUsage(Account $account): array
    {
        $result = $this->management->refreshUsage($account);

        return match ($result->status) {
            UsageRefreshResult::REFRESHED => ['success', 'مصرف سرویس از سرور به‌روزرسانی شد.'],
            UsageRefreshResult::THROTTLED => ['info', 'مصرف همین چند لحظه‌ی پیش به‌روز شده است؛ چند دقیقه‌ی دیگر دوباره امتحان کنید.'],
            UsageRefreshResult::UNSUPPORTED => ['info', 'این سرور گزارش مصرفِ لحظه‌ای ندارد؛ مصرف به‌صورت دوره‌ای ثبت می‌شود.'],
            UsageRefreshResult::SKIPPED => ['info', 'برای سرویسِ غیرفعال یا منقضی، مصرف لحظه‌ای نمایش داده نمی‌شود.'],
            default => ['warning', 'دریافت مصرف از سرور ممکن نشد؛ آخرین مقدار ثبت‌شده نمایش داده می‌شود.'],
        };
    }

    /**
     * تمدید با پیش‌بررسی UX (مبلغ دقیقِ همین Context از quote()، نه mainPrice). دروازه‌ی واقعی همچنان
     * داخل RenewalService/PurchaseGuard است. نگاشت خطا همان متنی است که قبل از B3.2 نشان داده می‌شد.
     *
     * @return array{ok: bool, message?: string, before?: int, after?: int}
     */
    public function renew(Account $account, ?string $token): array
    {
        // S-07: بدون توکن، کلید به پنجره‌ی ۱۰ثانیه‌ای همان اکانت گره می‌خورد.
        $key = $token !== null && $token !== '' && strlen($token) <= 64
            ? 'website-renew:'.$account->id.':'.$token
            : 'website-renew:'.$account->id.':w'.intdiv(time(), 10);

        $quote = $this->management->quote($account);
        $before = $this->management->balance($account);

        // ارسال دوباره‌ی همان فرم: چیزی کسر نمی‌شود؛ پیش‌بررسی کمبود نباید جلوی پاسخ موفق را بگیرد.
        if (! $quote->canRenew && ! $this->management->isReplay($key)) {
            return ['ok' => false, 'message' => $quote->needsTopUp()
                ? 'برای تمدید، ابتدا کیف پول خود را شارژ کنید. هزینه‌ی تمدید: '.Money::format($quote->price)
                : (string) $quote->message];
        }

        try {
            $this->management->renew($account, $key);
        } catch (InsufficientBalanceException) {
            return ['ok' => false, 'message' => 'موجودی کیف پول کافی نیست.'];
        } catch (RenewalFailedException $e) {
            return ['ok' => false, 'message' => "تمدید ناموفق بود: {$e->getMessage()}\n{$e->customerNotice()}"];
        } catch (PurchaseNotAllowedException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (\RuntimeException $e) {
            // مثل ربات: بازگشت وجه/Retry دستی اینجا نیست؛ وضعیت سفارش ثبت شده و Admin رسیدگی می‌کند.
            return ['ok' => false, 'message' => "تمدید ناموفق بود: {$e->getMessage()}\nلطفاً با پشتیبانی تماس بگیرید؛ وضعیت سفارش شما ثبت شده است."];
        }

        $after = $this->management->balance($account);

        return ['ok' => true, 'before' => $before, 'after' => $after];
    }
}
