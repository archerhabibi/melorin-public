<?php

namespace App\Services\Resellers;

use App\Exceptions\ResellerScopeViolationException;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * ResellerService — طبق سند معماری Reseller Platform، بخش ۳
 * (Core/Resellers/Services/ResellerService). مسئول چرخه‌ی حیات خودِ
 * Reseller (ساخت/فعال/غیرفعال) و مدیریت مدیران آن (بند ۱۳ سند
 * نیازمندی: Owner می‌تواند Manager اضافه/حذف کند) است — نه مشتریان یا
 * قیمت‌گذاری، که در سرویس‌های مجزا (ResellerCustomerService،
 * ResellerPricingService) پیاده‌سازی شده‌اند تا هر سرویس یک مسئولیت
 * واحد داشته باشد.
 */
class ResellerService
{
    /**
     * یک نماینده‌ی جدید می‌سازد و همان کاربر را با نقش owner در
     * reseller_admins ثبت می‌کند — طبق تصمیم معماری، «مالک بودن» هم از
     * همان جدول admins خوانده می‌شود تا isAdminOf() یک مسیر واحد داشته
     * باشد، نه دو مسیر جداگانه برای owner و manager.
     */
    public function create(User $ownerUser, array $attributes = []): Reseller
    {
        $reseller = Reseller::create(array_merge([
            'user_id' => $ownerUser->id,
            'status' => 'active',
        ], $attributes));

        ResellerAdmin::create([
            'reseller_id' => $reseller->id,
            'user_id' => $ownerUser->id,
            'role' => 'owner',
        ]);

        return $reseller;
    }

    public function activate(Reseller $reseller): Reseller
    {
        $reseller->update(['status' => 'active']);

        return $reseller;
    }

    /**
     * غیرفعال کردن نماینده. طبق بند ۱۴ سند نیازمندی: «در حالت Disabled،
     * فروش و عملیات حساس Bot متوقف شود» — این وضعیت همان چیزی است که
     * ResellerBot\UpdateRouter پیش از پردازش هر پیام چک می‌کند
     * (ر.ک. Reseller::isActive()).
     */
    public function deactivate(Reseller $reseller): Reseller
    {
        $reseller->update(['status' => 'inactive']);

        return $reseller;
    }

    /**
     * فقط owner مجاز است manager اضافه کند — این چک اینجا (نه در لایه‌ی
     * بالاتر) انجام می‌شود چون «امنیت نباید به Controller/UI وابسته
     * باشد» (سند معماری، بند ۵).
     *
     * @throws ResellerScopeViolationException اگر $actor خودش owner/manager این Reseller نباشد
     */
    public function addAdmin(Reseller $reseller, User $actor, User $newAdmin, string $role = 'manager'): ResellerAdmin
    {
        if (! $this->isOwner($reseller, $actor)) {
            throw new ResellerScopeViolationException('فقط مالک نماینده می‌تواند مدیر اضافه کند.');
        }

        if ($role === 'owner') {
            throw new ResellerScopeViolationException('نمی‌توان بیش از یک owner برای یک نماینده تعیین کرد.');
        }

        return ResellerAdmin::query()->updateOrCreate(
            ['reseller_id' => $reseller->id, 'user_id' => $newAdmin->id],
            ['role' => $role],
        );
    }

    /**
     * @throws ResellerScopeViolationException اگر $actor مالک نباشد، یا تلاش شود owner حذف شود
     */
    public function removeAdmin(Reseller $reseller, User $actor, User $adminToRemove): void
    {
        if (! $this->isOwner($reseller, $actor)) {
            throw new ResellerScopeViolationException('فقط مالک نماینده می‌تواند مدیر حذف کند.');
        }

        $row = ResellerAdmin::query()
            ->where('reseller_id', $reseller->id)
            ->where('user_id', $adminToRemove->id)
            ->first();

        if (! $row) {
            return;
        }

        if ($row->isOwner()) {
            throw new ResellerScopeViolationException('مالک نماینده قابل‌حذف نیست.');
        }

        $row->delete();
    }

    public function isOwner(Reseller $reseller, User $user): bool
    {
        return ResellerAdmin::query()
            ->where('reseller_id', $reseller->id)
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->exists();
    }

    public function isManager(Reseller $reseller, User $user): bool
    {
        return ResellerAdmin::query()
            ->where('reseller_id', $reseller->id)
            ->where('user_id', $user->id)
            ->where('role', 'manager')
            ->exists();
    }

    /** owner یا manager — یعنی این کاربر اصلاً روی این Reseller دسترسی مدیریتی دارد */
    public function isAdminOf(Reseller $reseller, User $user): bool
    {
        return ResellerAdmin::query()
            ->where('reseller_id', $reseller->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * ثبت خودکار وب‌هوک ربات نماینده نزد تلگرام — دقیقاً همان درخواست
     * صریح: «وب‌هوک و ربات تلگرام نماینده را در پنل تحت وب اتوماتیک
     * وصل کند». آدرس همیشه webhook_slug است (نه bot_token واقعی)،
     * چون bot_token با encrypted cast ذخیره می‌شود و در URL نباید افشا
     * شود (ر.ک. کامنت‌های ResellerBot\Http\Controllers\WebhookController).
     *
     * @return array{success: bool, description: string}
     */
    public function registerWebhook(Reseller $reseller): array
    {
        $webhookUrl = rtrim(config('app.url'), '/')."/reseller-bot/webhook/{$reseller->webhook_slug}";

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post("https://api.telegram.org/bot{$reseller->bot_token}/setWebhook", [
                    'url' => $webhookUrl,
                ]);

            $body = $response->json();

            return [
                'success' => (bool) ($body['ok'] ?? false),
                'description' => $body['description'] ?? ($response->successful() ? 'ثبت شد.' : 'خطای نامشخص از تلگرام.'),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'description' => $e->getMessage()];
        }
    }
}
