<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طبق درخواست صریح: پنل اصلی (Core) باید بتواند هر سبد فروش
 * (Category) را برای «همه‌ی نمایندگان» یک‌جا فعال/غیرفعال کند —
 * یک کلید سراسری در سطح سبد فروش، نه تنظیمی جداگانه برای هر نماینده
 * (آن قابلیت از قبل با ResellerProductPrice.is_enabled پوشش داده
 * شده). پیش‌فرض true تا رفتار فعلی (همه‌ی سبدهای فعال برای نمایندگان
 * در دسترس‌اند) برای دسته‌بندی‌های موجود تغییر نکند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('available_to_resellers')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('available_to_resellers');
        });
    }
};
