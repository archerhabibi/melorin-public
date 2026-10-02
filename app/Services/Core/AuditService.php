<?php

namespace App\Services\Core;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * AuditService — ثبت متمرکز عملیات حساس (P2 گزارش امنیتی، مورد #26).
 *
 * جدول audit_logs از همان نسخه‌های اول وجود داشت ولی هیچ‌جای پروژه
 * چیزی در آن نمی‌نوشت — یعنی بند ۳۱ سند نیازمندی («تمام عملیات حساس
 * باید دارای لاگ و سابقه باشند») عملاً پیاده نشده بود. این سرویس تنها
 * نقطه‌ی نوشتن در آن است.
 *
 * `$actor` می‌تواند Admin، Reseller یا `User` (guard 'web'، یعنی مشتری‌های
 * Website) باشد. اگر کنشگر مشتریِ Website (مثل Identity Linking) شناخته
 * نشود، Audit Log به‌غلط با actor_type='system' ثبت می‌شود و سابقه‌ای که
 * نمی‌گوید «چه کسی» کاری کرده بی‌فایده است. Website سرویس Audit جداگانه
 * ندارد؛ همین یک نقطه‌ی مشترک است.
 *
 * طراحی عمدی: ثبت لاگ هرگز نباید عملیات اصلی را بشکند. اگر نوشتن لاگ
 * به هر دلیلی شکست بخورد (مثلاً جدول هنوز migrate نشده)، خطا فقط در
 * لاگ فایل ثبت می‌شود و جریان اصلی ادامه پیدا می‌کند — یک تأیید پرداخت
 * نباید به‌خاطر شکستِ نوشتنِ سابقه‌اش rollback شود.
 */
class AuditService
{
    /**
     * @param  string  $action  شناسه‌ی کوتاه و ماشین‌خوان، مثل 'payment.approved'
     * @param  Model|null  $target  موضوع عملیات (پرداخت، نماینده، محصول، ...)
     */
    public function record(
        string $action,
        ?Model $target = null,
        array $before = [],
        array $after = [],
        Admin|Reseller|User|null $actor = null,
    ): void {
        try {
            [$actorType, $actorId] = $this->resolveActor($actor);

            AuditLog::create([
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'action' => $action,
                'target_type' => $target?->getMorphClass(),
                'target_id' => $target?->getKey(),
                'before' => $before ?: null,
                'after' => $after ?: null,
                'ip_address' => Request::ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('audit_log_write_failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * اگر actor صریح داده نشده باشد، از گاردهای فعال تشخیص داده می‌شود.
     * 'system' یعنی عملیات توسط خودِ سیستم انجام شده (مثلاً callback
     * درگاه پرداخت یا یک Job)، نه یک انسانِ لاگین‌کرده.
     *
     * @return array{0: string, 1: int}
     */
    protected function resolveActor(Admin|Reseller|User|null $actor): array
    {
        if ($actor instanceof Admin) {
            return ['admin', $actor->id];
        }

        if ($actor instanceof Reseller) {
            return ['reseller', $actor->id];
        }

        if ($actor instanceof User) {
            return ['customer', $actor->id];
        }

        if ($admin = Auth::guard('admin')->user()) {
            return ['admin', $admin->id];
        }

        if ($customer = Auth::guard('web')->user()) {
            return ['customer', $customer->id];
        }

        return ['system', 0];
    }
}
