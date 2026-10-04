<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B3.5 — Profile Center: «نامی که کاربر خودش انتخاب کرده» باید از «نام نمایشی تلگرام» قابل‌تفکیک باشد.
 *
 * وب‌هوک هر دو ربات تا B3.4 روی هر پیام `users.full_name` را با نام فعلی تلگرام بازنویسی می‌کرد؛ یعنی هر نامی که
 * مشتری در سایت (ثبت‌نام یا ویرایش پروفایل) وارد می‌کرد، با اولین پیام ربات از بین می‌رفت. این ستون زمان آخرین
 * ویرایش دستیِ نام را نگه می‌دارد؛ `NULL` = هیچ‌وقت ویرایش نشده (همه‌ی کاربران موجود، بدون backfill، درست‌اند).
 * قاعده‌ی هم‌گام‌سازی نام تلگرام در `ProfileCenterService::applyTelegramName()` است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('full_name_edited_at')->nullable()->after('full_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('full_name_edited_at');
        });
    }
};
