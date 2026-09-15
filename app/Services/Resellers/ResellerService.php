<?php

namespace App\Services\Resellers;

use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * ResellerService — طبق سند معماری Reseller Platform، بخش ۳
 * (Core/Resellers/Services/ResellerService). مسئول چرخه‌ی حیات خودِ
 * Reseller (ساخت/فعال/غیرفعال) است — نه مشتریان یا قیمت‌گذاری، که در
 * سرویس‌های مجزا (ResellerCustomerService، ResellerPricingService)
 * پیاده‌سازی شده‌اند تا هر سرویس یک مسئولیت واحد داشته باشد.
 *
 * نکته: مفهوم «Manager» (نقش دومِ reseller_admins) به‌طور کامل حذف شده
 * است — برداشت اشتباهی از آن شده بود و در هیچ بخشی از ربات/پنل واقعاً
 * استفاده نمی‌شد. هر Reseller دقیقاً یک owner دارد؛ جدول
 * reseller_admins هنوز به‌همین شکل باقی است (برای اینکه isOwner/
 * isAdminOf یک مسیر واحد و قابل‌تست داشته باشند) ولی هیچ راهی برای
 * افزودن نقش دومی روی آن وجود ندارد.
 */
class ResellerService
{
    /**
     * یک نماینده‌ی جدید می‌سازد و همان کاربر را با نقش owner در
     * reseller_admins ثبت می‌کند.
     */
    /**
     * ساخت نماینده + رکورد مالکیتش. در یک تراکنش واحد (P1 گزارش
     * امنیتی): اگر ساخت ResellerAdmin شکست می‌خورد، پیش از این یک
     * Reseller بی‌مالک در دیتابیس باقی می‌ماند — نمایندگی‌ای که هیچ‌کس
     * نمی‌تواند واردش شود و فقط دستی در دیتابیس قابل رفع است.
     */
    public function create(User $ownerUser, array $attributes = []): Reseller
    {
        return DB::transaction(function () use ($ownerUser, $attributes) {
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
        });
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

    public function isOwner(Reseller $reseller, User $user): bool
    {
        return ResellerAdmin::query()
            ->where('reseller_id', $reseller->id)
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->exists();
    }

    /** فعلاً معادل isOwner() است (چون Manager حذف شده)؛ به‌عنوان نقطه‌ی واحدِ چک «آیا این کاربر روی این Reseller دسترسی مدیریتی دارد؟» نگه داشته شده تا کد صداکننده مجبور به تغییر نباشد */
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
                    // secret اختصاصی همین نماینده — تلگرام آن را در هدر
                    // X-Telegram-Bot-Api-Secret-Token برمی‌گرداند و
                    // WebhookController با hash_equals تطبیقش می‌دهد.
                    // بدون این، هرکس slug را می‌دانست می‌توانست Update
                    // جعلی بفرستد (P0 گزارش امنیتی).
                    'secret_token' => $reseller->ensureWebhookSecret(),
                    // آرایه‌ی خالی = همه‌ی انواع پیش‌فرض، از جمله
                    // callback_query. اگر این پارامتر فرستاده نشود،
                    // تلگرام «آخرین تنظیم قبلی» را نگه می‌دارد — همان
                    // باگی که یک بار روی ربات اصلی باعث شد هیچ کلیکی
                    // روی دکمه‌های این‌لاین به سرور نرسد.
                    'allowed_updates' => '[]',
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
