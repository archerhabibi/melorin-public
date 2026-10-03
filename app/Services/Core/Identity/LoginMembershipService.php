<?php

namespace App\Services\Core\Identity;

use App\Models\CustomerAccount;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * G21 — «ورود موفق ⇒ CustomerAccount فروشگاه مبدأ» (تصمیم صاحب پروژه، استثنای R7).
 *
 * تنها نقطه‌ی مشترک برای هر دو روش ورود Website: Google (`ExternalIdentityService`) و
 * Email+Password (`AuthenticatedSessionController`). ساخت واقعی همیشه از
 * `Store\IdentityService::resolveCustomerAccount` است (Idempotent، ضد Race).
 *
 * - فقط وقتی Context داده شده و فروشگاه «قابل عملیات» است.
 * - موجود ⇒ همان، بدون هیچ تغییری (Status/نام دست نمی‌خورد).
 * - Wallet/Order ساخته نمی‌شود.
 * - شکست اینجا ورود را نمی‌شکند؛ Checkout همچنان Lazy Resolve می‌کند.
 */
class LoginMembershipService
{
    public function __construct(
        protected AuditService $audit,
        protected IdentityService $stores,
    ) {}

    /**
     * @param  string  $method  `google` | `password` — فقط برای نام Audit.
     * @return array{0: CustomerAccount, 1: bool}|null [عضویت، تازه‌ساخته‌شده؟] یا null اگر ساخته/خوانده نشد
     */
    public function ensure(User $user, ?StoreContext $store, string $method): ?array
    {
        if ($store === null || ! $store->isOperational()) {
            return null;
        }

        try {
            $existing = $this->stores->findCustomerAccount($user, $store);
            $customer = $existing ?? $this->stores->resolveCustomerAccount($user, $store);
            $created = $existing === null;

            if ($created) {
                $this->audit->record(
                    "identity.{$method}_customer_account_created",
                    $customer,
                    after: $store->toArray(),
                    actor: $user,
                );
            }

            return [$customer, $created];
        } catch (Throwable $e) {
            Log::warning('login_customer_account_failed', [
                'method' => $method,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
