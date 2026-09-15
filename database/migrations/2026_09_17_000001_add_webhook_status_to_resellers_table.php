<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1 گزارش امنیتی (موارد #14 و #15): وضعیت ثبت وب‌هوک هیچ‌جا نگهداری
 * نمی‌شد. نتیجه این بود که اگر ثبت وب‌هوک شکست می‌خورد (توکن اشتباه،
 * قطعی شبکه، تحریم/فیلترینگ)، هیچ نشانه‌ای در پنل دیده نمی‌شد و ادمین
 * تازه وقتی متوجه می‌شد که مشتریِ نماینده شکایت می‌کرد ربات جواب
 * نمی‌دهد. حالا آخرین نتیجه‌ی تلاش ثبت روی خودِ رکورد نماینده ذخیره و
 * در لیست نمایندگان نمایش داده می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->string('webhook_status')->nullable()->after('webhook_secret');
            $table->text('webhook_error')->nullable()->after('webhook_status');
            $table->timestamp('webhook_registered_at')->nullable()->after('webhook_error');
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn(['webhook_status', 'webhook_error', 'webhook_registered_at']);
        });
    }
};
