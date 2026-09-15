<?php

use App\Models\Reseller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * P0 گزارش امنیتی: وب‌هوک ربات نماینده هیچ authentication ای نداشت.
 *
 * ربات اصلی از قبل X-Telegram-Bot-Api-Secret-Token را بررسی می‌کرد، ولی
 * مسیر /reseller-bot/webhook/{slug} فقط به «حدس‌نزدنیِ» slug تکیه داشت.
 * یعنی هرکس slug را می‌دانست (که یک راز رمزنگاری‌شده نیست و در URL هم
 * دیده می‌شود) می‌توانست Update جعلی بفرستد — مثلاً خودش را به‌جای یک
 * مشتری جا بزند.
 *
 * هر نماینده secret مستقل خودش را می‌گیرد (نه یک secret مشترک) تا نشت
 * آن برای یک نماینده، بقیه را در معرض خطر نگذارد.
 *
 * برای نمایندگان موجود، یک secret تصادفی تولید می‌شود؛ ولی چون آن
 * secret هنوز نزد تلگرام ثبت نشده، اجرای دوباره‌ی «ثبت وب‌هوک» از پنل
 * ادمین برای هر نماینده‌ی فعال لازم است (ر.ک. یادداشت ارتقا در VERSION).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->string('webhook_secret')->nullable()->after('webhook_slug');
        });

        Reseller::query()->whereNull('webhook_secret')->get()->each(function (Reseller $reseller) {
            $reseller->forceFill(['webhook_secret' => Str::random(48)])->saveQuietly();
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn('webhook_secret');
        });
    }
};
